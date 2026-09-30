<?php
// Process/shell helpers shared by every status path.
//
// Pure logic only: no globals, no header()/session/file I/O beyond what's
// passed in, so this file can be require_once'd standalone from tests.

// BusyBox pgrep -x xray does not match how Xray is actually started
// (`/opt/sbin/xray run -config ...`), so it always reports "not found" even
// while Xray is running. pidof xray matches on the binary name and works.
function xray_pid(string $pidofCmd = 'pidof xray'): ?int {
    $out = trim(shell_exec($pidofCmd) ?? '');
    if ($out === '') return null;
    $parts = preg_split('/\s+/', $out);
    return isset($parts[0]) && $parts[0] !== '' ? (int)$parts[0] : null;
}

function xray_running(string $pidofCmd = 'pidof xray'): bool {
    return xray_pid($pidofCmd) !== null;
}

function shell_run(string $cmd): ?string {
    $withRedirect = strpos($cmd, '2>') === false ? $cmd . ' 2>&1' : $cmd;
    return trim(shell_exec($withRedirect) ?? '');
}

// Like shell_run(), but kills the subprocess if it hasn't finished within
// $seconds. BusyBox's `timeout` applet isn't guaranteed present on every
// build, so the deadline is enforced in PHP via proc_open, not the shell —
// needed by anything that shells out on a request with a hard time budget
// (route_explain's nslookup/ipset test must return in well under 3s).
// Returns null when the process could not be started or never produced
// output before being killed.
function shell_run_timeout(string $cmd, float $seconds): ?string {
    $withRedirect = strpos($cmd, '2>') === false ? $cmd . ' 2>&1' : $cmd;
    $proc = @proc_open($withRedirect, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return null;
    stream_set_blocking($pipes[1], false);
    $out = '';
    $deadline = microtime(true) + $seconds;
    do {
        $out .= stream_get_contents($pipes[1]);
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        usleep(20000);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        proc_terminate($proc, 9);
    }
    $out .= stream_get_contents($pipes[1]);
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    proc_close($proc);
    return trim($out);
}
