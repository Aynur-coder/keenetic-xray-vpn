<?php
// The single path by which a config change reaches Xray.
//
// 1. Serialised: an exclusive flock means two parallel requests (e.g. quick
//    server switches) can never run overlapping killall/start sequences.
// 2. Validated: the new config is written next to the live one and checked
//    with `xray run -test` first. A rejected config (2026-09-27: one host
//    stopped resolving and landed in an "ip" rule) never replaces the working
//    one, so the running Xray keeps serving every other server.
//
// Every external effect is overridable via $opt so tests never touch /opt or
// a real Xray. Production defaults below match what api.php did before.

require_once __DIR__ . '/system.php';
require_once __DIR__ . '/events.php';

const APPLY_BUSY_ERROR = 'Другое применение изменений ещё идёт';

/**
 * Regenerate the Xray config and restart Xray, under a lock, only if the new
 * config passes `xray run -test`.
 *
 * $mode 'full':    config + firewall + Xray restart + AdGuard reload + warmup.
 * $mode 'routing': config + Xray restart only.
 *
 * $opt keys (all optional): xray_bin, conf, lock, lock_timeout (s),
 * state_file, events_file, pid_file, manager, pidof_cmd, restart_cmd
 * (replaces the default kill/firewall/start shell sequence), generate
 * (callable(string $outFile): array, ['error'=>…] on failure),
 * before_restart / after_restart (callable(string $mode): void or null).
 *
 * @return array{ok: bool, xray_running: bool, error: ?string}
 */
function apply_changes(string $mode = 'full', array $opt = []): array {
    $opt += apply_default_options($mode, $opt['conf'] ?? '/opt/etc/xray/config.json');
    $conf = $opt['conf'];
    $new = $conf . '.new';

    $lock = apply_acquire_lock($opt['lock'], (int)$opt['lock_timeout']);
    if ($lock === null) {
        return ['ok' => false, 'xray_running' => xray_running($opt['pidof_cmd']),
                'error' => APPLY_BUSY_ERROR];
    }

    try {
        @unlink($new);
        try {
            $gen = ($opt['generate'])($new);
        } catch (Throwable $e) {
            $gen = ['error' => $e->getMessage()];
        }
        if (isset($gen['error']) || !is_file($new)) {
            @unlink($new);
            return ['ok' => false, 'xray_running' => xray_running($opt['pidof_cmd']),
                    'error' => (string)($gen['error'] ?? 'Конфиг не сгенерирован')];
        }

        $out = [];
        $rc = 1;
        exec(escapeshellarg($opt['xray_bin']) . ' run -test -config ' . escapeshellarg($new)
            . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            $reason = apply_error_line(implode("\n", $out));
            @unlink($new);
            apply_state_patch($opt['state_file'], ['last_apply_error' => $reason]);
            emit_event('error', 'config', 'Конфиг отклонён: ' . $reason, [],
                $opt['events_file'], function_exists('get_features') ? 'get_features' : null);
            return ['ok' => false, 'xray_running' => xray_running($opt['pidof_cmd']),
                    'error' => $reason];
        }

        if (!@rename($new, $conf)) {
            @unlink($new);
            return ['ok' => false, 'xray_running' => xray_running($opt['pidof_cmd']),
                    'error' => 'Не удалось записать ' . $conf];
        }
        apply_state_patch($opt['state_file'], ['last_apply_error' => null]);

        if ($opt['before_restart']) ($opt['before_restart'])($mode);
        shell_run($opt['restart_cmd']);
        if ($opt['after_restart']) ($opt['after_restart'])($mode);

        return ['ok' => true, 'xray_running' => xray_running($opt['pidof_cmd']), 'error' => null];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function apply_default_options(string $mode, string $conf): array {
    $pid = '/opt/var/run/xray.pid';
    $manager = '/opt/etc/xray/xray-manager.sh';
    // Same start command and pid file as before, so the init script and the
    // watchdog keep recognising the process.
    $restart = 'killall xray 2>/dev/null; sleep 1; '
        . ($mode === 'full' ? "$manager firewall 2>/dev/null; " : '')
        . "xray run -config $conf > /dev/null 2>&1 & echo \$! > $pid";
    return [
        'xray_bin'     => 'xray',
        'conf'         => $conf,
        'lock'         => '/opt/var/run/xray-apply.lock',
        'lock_timeout' => 60,
        'state_file'   => '/opt/etc/xray/state.json',
        'events_file'  => null,
        'pidof_cmd'    => 'pidof xray',
        'restart_cmd'  => $restart,
        'generate'     => 'generate_xray_config',
        'before_restart' => function_exists('write_derived_files')
            ? fn(string $m) => write_derived_files() : null,
        'after_restart'  => function (string $m): void {
            if ($m !== 'full') return;
            if (function_exists('reload_adguard')) reload_adguard();
            if (function_exists('warmup_ipset')) warmup_ipset();
        },
    ];
}

/**
 * PHP's flock() has no timeout, so poll a non-blocking lock until $timeout.
 *
 * @return resource|null the held lock handle, or null on timeout/error
 */
function apply_acquire_lock(string $file, int $timeout) {
    $dir = dirname($file);
    if ($dir !== '' && !is_dir($dir)) @mkdir($dir, 0755, true);
    $h = @fopen($file, 'c');
    if (!$h) return null;
    $deadline = microtime(true) + $timeout;
    while (true) {
        if (flock($h, LOCK_EX | LOCK_NB)) return $h;
        if (microtime(true) >= $deadline) break;
        usleep(100000);
    }
    fclose($h);
    return null;
}

/**
 * The reason Xray gave, from `xray run -test` output: its last non-empty line
 * (Xray prints a version banner first and the error chain last), keeping the
 * tail when it is longer than ~300 chars since the root cause is at the end.
 */
function apply_error_line(string $output): string {
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output)),
        fn(string $l): bool => $l !== ''));
    $line = $lines ? $lines[count($lines) - 1] : 'xray -test failed';
    $max = 300;
    if (function_exists('mb_strlen')) {
        if (mb_strlen($line) > $max) $line = '…' . mb_substr($line, -$max);
    } elseif (strlen($line) > $max) {
        $line = '...' . substr($line, -$max);
    }
    return $line;
}

// Read-modify-write so keys owned by other code survive; null removes a key.
function apply_state_patch(string $file, array $patch): void {
    $state = [];
    if (is_file($file)) {
        $d = json_decode((string)file_get_contents($file), true);
        if (is_array($d)) $state = $d;
    }
    $before = $state;
    foreach ($patch as $k => $v) {
        if ($v === null) unset($state[$k]);
        else $state[$k] = $v;
    }
    if ($state === $before) return;
    file_put_contents($file,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
