<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/apply.php';

// Scratch dir with a fake xray binary, a "live" config and a state file.
// $testExit/$testOutput decide how the fake `xray run -test` behaves.
function _apply_env(int $testExit, string $testOutput): array {
    $dir = sys_get_temp_dir() . '/xray_apply_' . bin2hex(random_bytes(4));
    mkdir($dir);
    $bin = "$dir/xray";
    file_put_contents($bin, "#!/bin/sh\necho " . escapeshellarg($testOutput) . "\nexit $testExit\n");
    chmod($bin, 0755);
    file_put_contents("$dir/config.json", '{"old":true}');
    file_put_contents("$dir/state.json", json_encode(['selected' => 'key-1']));
    return [
        'dir' => $dir,
        'opt' => [
            'xray_bin'    => $bin,
            'conf'        => "$dir/config.json",
            'lock'        => "$dir/apply.lock",
            'state_file'  => "$dir/state.json",
            'events_file' => "$dir/events.log",
            'restart_cmd' => "echo restarted >> $dir/restarts",
            'pidof_cmd'   => 'echo 4242',
            'generate'    => function (string $out): array {
                file_put_contents($out, '{"new":true}');
                return ['ok' => true];
            },
            'before_restart' => null,
            'after_restart'  => null,
        ],
    ];
}

function _apply_cleanup(string $dir): void {
    foreach (glob("$dir/{,.}*", GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($dir);
}

function test_bad_config_keeps_old(): void {
    $env = _apply_env(1, 'Failed to start: infra/conf: illegal ip rule: bad.example.com');
    $dir = $env['dir'];
    $r = apply_changes('full', $env['opt']);

    eq($r['ok'], false, 'rejected config is not ok');
    eq(strpos((string)$r['error'], 'illegal ip rule') !== false, true, 'error carries Xray reason');
    eq(file_get_contents("$dir/config.json"), '{"old":true}', 'old config untouched');
    eq(file_exists("$dir/config.json.new"), false, '.new removed');
    eq(file_exists("$dir/restarts"), false, 'xray not restarted');

    $state = json_decode((string)file_get_contents("$dir/state.json"), true);
    eq(strpos((string)($state['last_apply_error'] ?? ''), 'illegal ip rule') !== false, true,
        'state.last_apply_error set');
    eq($state['selected'] ?? null, 'key-1', 'other state keys preserved');

    $ev = json_decode(trim((string)file_get_contents("$dir/events.log")), true);
    eq($ev['level'], 'error', 'error event level');
    eq($ev['type'], 'config', 'config event type');
    eq(strpos($ev['msg'], 'Конфиг отклонён: ') === 0, true, 'event message prefix');
    _apply_cleanup($dir);
}

function test_good_config_replaces(): void {
    $env = _apply_env(0, 'Configuration OK.');
    $dir = $env['dir'];
    file_put_contents("$dir/state.json",
        json_encode(['selected' => 'key-1', 'last_apply_error' => 'old failure']));
    $r = apply_changes('routing', $env['opt']);

    eq($r['ok'], true, 'valid config is ok');
    eq($r['error'], null, 'no error');
    eq($r['xray_running'], true, 'reports xray running');
    eq(file_get_contents("$dir/config.json"), '{"new":true}', 'config replaced');
    eq(file_exists("$dir/config.json.new"), false, '.new renamed away');
    eq(trim((string)file_get_contents("$dir/restarts")), 'restarted', 'restarted once');

    $state = json_decode((string)file_get_contents("$dir/state.json"), true);
    eq(array_key_exists('last_apply_error', $state), false, 'last_apply_error cleared');
    eq($state['selected'] ?? null, 'key-1', 'other state keys preserved');
    _apply_cleanup($dir);
}

function test_generate_failure_keeps_old(): void {
    $env = _apply_env(0, 'Configuration OK.');
    $dir = $env['dir'];
    $env['opt']['generate'] = fn(string $out): array => ['error' => 'No active outbounds'];
    $r = apply_changes('full', $env['opt']);

    eq($r['ok'], false, 'generation failure is not ok');
    eq($r['error'], 'No active outbounds', 'generator error passed through');
    eq(file_get_contents("$dir/config.json"), '{"old":true}', 'old config untouched');
    eq(file_exists("$dir/restarts"), false, 'xray not restarted');
    _apply_cleanup($dir);
}

function test_lock_serializes(): void {
    $env = _apply_env(0, 'Configuration OK.');
    $dir = $env['dir'];
    $lock = $env['opt']['lock'];
    $ready = "$dir/child-ready";
    $code = '$h = fopen(' . var_export($lock, true) . ', "c"); flock($h, LOCK_EX);'
        . ' touch(' . var_export($ready, true) . '); sleep(2);';
    $child = proc_open([PHP_BINARY, '-r', $code], [], $pipes);
    for ($i = 0; $i < 100 && !file_exists($ready); $i++) usleep(50000);
    eq(file_exists($ready), true, 'child took the lock');

    $t0 = microtime(true);
    $r = apply_changes('routing', $env['opt']);
    $elapsed = microtime(true) - $t0;
    proc_close($child);

    eq($r['ok'], true, 'apply succeeds once the lock frees');
    eq($elapsed >= 1.5, true, 'waited for the other apply (elapsed ' . round($elapsed, 2) . 's)');
    _apply_cleanup($dir);
}

function test_lock_timeout(): void {
    $env = _apply_env(0, 'Configuration OK.');
    $dir = $env['dir'];
    $h = fopen($env['opt']['lock'], 'c');
    // flock locks are per open file description, so a second fopen in this
    // process contends with $h just like another process would.
    flock($h, LOCK_EX);
    $env['opt']['lock_timeout'] = 1;
    $r = apply_changes('routing', $env['opt']);
    flock($h, LOCK_UN);
    fclose($h);

    eq($r['ok'], false, 'times out while another apply holds the lock');
    eq($r['error'], 'Другое применение изменений ещё идёт', 'timeout message');
    eq(file_get_contents("$dir/config.json"), '{"old":true}', 'config untouched on timeout');
    _apply_cleanup($dir);
}

function test_apply_error_line_trimmed(): void {
    $out = "Xray 1.8.24\nA unified platform\n\nFailed: " . str_repeat('x', 400) . "\n\n";
    $msg = apply_error_line($out);
    eq(strpos($msg, 'Failed') === false, true, 'long line keeps its tail');
    eq(strlen($msg) <= 303, true, 'capped around 300 chars');
    eq(apply_error_line("a\nlast reason\n  \n"), 'last reason', 'last non-empty line');
    eq(apply_error_line(''), 'xray -test failed', 'fallback for empty output');
}

// Child process that holds $lock for 2 s; returns once it has the lock.
function _apply_hold_lock(string $lock, string $ready) {
    $code = '$h = fopen(' . var_export($lock, true) . ', "c"); flock($h, LOCK_EX);'
        . ' touch(' . var_export($ready, true) . '); sleep(2);';
    $child = proc_open([PHP_BINARY, '-r', $code], [], $pipes);
    for ($i = 0; $i < 100 && !file_exists($ready); $i++) usleep(50000);
    return $child;
}

function test_stop_waits_for_lock(): void {
    $env = _apply_env(0, 'Configuration OK.');
    $dir = $env['dir'];
    $child = _apply_hold_lock($env['opt']['lock'], "$dir/child-ready");
    eq(file_exists("$dir/child-ready"), true, 'child took the lock');

    $t0 = microtime(true);
    $r = apply_stop(['lock' => $env['opt']['lock'], 'stop_cmd' => "echo stopped > $dir/stopped"]);
    $elapsed = microtime(true) - $t0;
    proc_close($child);

    eq($r['ok'], true, 'stop succeeds once the lock frees');
    eq($elapsed >= 1.5, true, 'stop waited for the running apply (elapsed ' . round($elapsed, 2) . 's)');
    eq(trim((string)file_get_contents("$dir/stopped")), 'stopped', 'stop command ran');

    // A stop that cannot get the lock does nothing.
    $h = fopen($env['opt']['lock'], 'c');
    flock($h, LOCK_EX);
    @unlink("$dir/stopped");
    $r = apply_stop(['lock' => $env['opt']['lock'], 'lock_timeout' => 1,
                     'stop_cmd' => "echo stopped > $dir/stopped"]);
    flock($h, LOCK_UN);
    fclose($h);
    eq($r['error'], 'Другое применение изменений ещё идёт', 'stop times out with busy error');
    eq(file_exists("$dir/stopped"), false, 'stop command not run on timeout');
    _apply_cleanup($dir);
}

function test_effective_written_only_on_success(): void {
    $gen = function (string $out): array {
        file_put_contents($out, '{"new":true}');
        return ['ok' => true,
                'state' => ['effective_outbound' => 'key-2', 'effective_reason' => 'selected']];
    };

    $env = _apply_env(1, 'illegal ip rule');
    $env['opt']['generate'] = $gen;
    apply_changes('routing', $env['opt']);
    $state = json_decode((string)file_get_contents($env['dir'] . '/state.json'), true);
    eq(array_key_exists('effective_outbound', $state), false, 'rejected config: effective not written');
    eq(array_key_exists('effective_reason', $state), false, 'rejected config: reason not written');
    _apply_cleanup($env['dir']);

    $env = _apply_env(0, 'Configuration OK.');
    $env['opt']['generate'] = $gen;
    apply_changes('routing', $env['opt']);
    $state = json_decode((string)file_get_contents($env['dir'] . '/state.json'), true);
    eq($state['effective_outbound'] ?? null, 'key-2', 'accepted config: effective written');
    eq($state['effective_reason'] ?? null, 'selected', 'accepted config: reason written');
    eq($state['selected'] ?? null, 'key-1', 'other state keys preserved');
    _apply_cleanup($env['dir']);
}

function test_rejected_config_starts_old_when_stopped(): void {
    $env = _apply_env(0, 'unused');
    $dir = $env['dir'];
    // Fake xray: rejects the new candidate, accepts the old live config.
    file_put_contents($env['opt']['xray_bin'], "#!/bin/sh\ncase \"\$4\" in\n"
        . "  *.new) echo 'illegal ip rule: bad.example.com'; exit 1;;\n"
        . "  *) echo 'Configuration OK.'; exit 0;;\nesac\n");
    $opt = $env['opt'];
    $opt['pidof_cmd'] = 'true'; // Xray is stopped

    $r = apply_changes('full', $opt);
    eq(file_exists("$dir/restarts"), false, 'without the option a stopped Xray stays stopped');

    $opt['start_old_if_stopped'] = true;
    $r = apply_changes('full', $opt);
    eq($r['ok'], false, 'still reports the rejection');
    eq(strpos((string)$r['error'], 'illegal ip rule') !== false, true, 'rejection reason returned');
    eq(file_get_contents("$dir/config.json"), '{"old":true}', 'old config kept');
    eq(trim((string)file_get_contents("$dir/restarts")), 'restarted', 'Xray started on old config');

    // Xray already running: left alone on its old config.
    @unlink("$dir/restarts");
    $r = apply_changes('full', ['pidof_cmd' => 'echo 4242'] + $opt);
    eq(file_exists("$dir/restarts"), false, 'running Xray not restarted');

    // No old config: nothing to fall back to.
    unlink("$dir/config.json");
    $r = apply_changes('full', $opt);
    eq($r['ok'], false, 'no old config: error');
    eq(file_exists("$dir/restarts"), false, 'no old config: Xray not started');
    _apply_cleanup($dir);
}

// A lock holder that dies (SIGKILL, fatal error) must not leave the lock held
// through background children it spawned (xray run &, warmup...).
function test_lock_not_inherited_by_background_children(): void {
    $env = _apply_env(0, 'Configuration OK.');
    $dir = $env['dir'];
    $lib = var_export(realpath(__DIR__ . '/../../src/web/lib/apply.php'), true);
    $code = 'require ' . $lib . ';'
        . ' $h = apply_acquire_lock(' . var_export($env['opt']['lock'], true) . ', 5);'
        . ' shell_exec("sleep 30 > /dev/null 2>&1 & echo \$! > ' . $dir . '/bg.pid");'
        . ' touch(' . var_export("$dir/child-ready", true) . '); sleep(30);';
    $child = proc_open([PHP_BINARY, '-r', $code], [], $pipes);
    for ($i = 0; $i < 100 && !file_exists("$dir/child-ready"); $i++) usleep(50000);
    eq(file_exists("$dir/child-ready"), true, 'child took the lock and spawned a background job');
    proc_terminate($child, 9);
    proc_close($child);

    $env['opt']['lock_timeout'] = 5;
    $t0 = microtime(true);
    $r = apply_changes('routing', $env['opt']);
    $elapsed = microtime(true) - $t0;
    $bg = (int)trim((string)@file_get_contents("$dir/bg.pid"));
    if ($bg > 0) _apply_kill($bg);

    eq($r['ok'], true, 'lock free after the holder was killed');
    eq($elapsed < 2, true, 'acquired promptly (elapsed ' . round($elapsed, 2) . 's)');
    _apply_cleanup($dir);
}

function _apply_kill(int $pid): void {
    shell_exec('kill ' . $pid . ' 2>/dev/null');
}
