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
 * before_restart / after_restart (callable(string $mode): void or null),
 * start_old_if_stopped (bool: when the new config is rejected and Xray is not
 * running, start it on the previous config if that still passes -test).
 *
 * The generator may return 'state' => [key => value]; those keys are written
 * to state.json only once the new config has replaced the live one, so they
 * always describe what Xray actually runs.
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

        $reason = apply_test_config($opt['xray_bin'], $new);
        if ($reason !== null) {
            @unlink($new);
            apply_state_patch($opt['state_file'], ['last_apply_error' => $reason]);
            emit_event('error', 'config', 'Конфиг отклонён: ' . $reason, [],
                $opt['events_file'], function_exists('get_features') ? 'get_features' : null);
            // Spec §6: a rejected config never takes the VPN down. A running Xray
            // already keeps the old config; a stopped one (start/restart) is
            // brought up on it, as long as that old config itself still passes.
            if ($opt['start_old_if_stopped'] && !xray_running($opt['pidof_cmd'])
                && is_file($conf) && filesize($conf) > 0
                && apply_test_config($opt['xray_bin'], $conf) === null) {
                apply_restart($mode, $opt);
            }
            return ['ok' => false, 'xray_running' => xray_running($opt['pidof_cmd']),
                    'error' => $reason];
        }

        if (!@rename($new, $conf)) {
            @unlink($new);
            return ['ok' => false, 'xray_running' => xray_running($opt['pidof_cmd']),
                    'error' => 'Не удалось записать ' . $conf];
        }
        $genState = is_array($gen['state'] ?? null) ? $gen['state'] : [];
        apply_state_patch($opt['state_file'], $genState, ['last_apply_error']);

        apply_restart($mode, $opt);

        return ['ok' => true, 'xray_running' => xray_running($opt['pidof_cmd']), 'error' => null];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Stop Xray under the same lock as apply_changes(), so a stop can never
 * interleave with an apply's kill/start sequence (and an apply queued behind
 * it is the explicit next start). $opt keys: lock, lock_timeout, stop_cmd.
 *
 * @return array{ok: bool, error: ?string}
 */
function apply_stop(array $opt = []): array {
    $manager = '/opt/etc/xray/xray-manager.sh';
    $opt += [
        'lock'         => '/opt/var/run/xray-apply.lock',
        'lock_timeout' => 60,
        'stop_cmd'     => "$manager stop_watchdog 2>/dev/null; "
            . 'killall xray 2>/dev/null; rm -f /opt/var/run/xray.pid; '
            . "$manager cleanup_firewall 2>/dev/null",
    ];
    $lock = apply_acquire_lock($opt['lock'], (int)$opt['lock_timeout']);
    if ($lock === null) return ['ok' => false, 'error' => APPLY_BUSY_ERROR];
    try {
        shell_run($opt['stop_cmd']);
        return ['ok' => true, 'error' => null];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function apply_restart(string $mode, array $opt): void {
    if ($opt['before_restart']) ($opt['before_restart'])($mode);
    shell_run($opt['restart_cmd']);
    if ($opt['after_restart']) ($opt['after_restart'])($mode);
}

// null when Xray accepts $file, otherwise the reason it gave.
function apply_test_config(string $xrayBin, string $file): ?string {
    $out = [];
    $rc = 1;
    exec(escapeshellarg($xrayBin) . ' run -test -config ' . escapeshellarg($file) . ' 2>&1',
        $out, $rc);
    return $rc === 0 ? null : apply_error_line(implode("\n", $out));
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
        'start_old_if_stopped' => false,
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

// Read-modify-write so keys owned by other code survive. $set values may be
// null (stored as null); keys in $unset are removed.
function apply_state_patch(string $file, array $set, array $unset = []): void {
    $state = [];
    if (is_file($file)) {
        $d = json_decode((string)file_get_contents($file), true);
        if (is_array($d)) $state = $d;
    }
    $before = $state;
    foreach ($set as $k => $v) $state[$k] = $v;
    foreach ($unset as $k) unset($state[$k]);
    if ($state === $before) return;
    file_put_contents($file,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
