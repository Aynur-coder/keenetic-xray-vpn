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

// Like shell_run_timeout(), but runs several commands concurrently under ONE shared
// deadline instead of one command alone. Deliberately NOT "background them inside one
// shell script and shell_run_timeout() the wrapper" ("(cmd1 &); (cmd2 &); wait") — that
// pattern only hands PHP a proc_open() handle to the wrapping shell; killing it on
// timeout (proc_terminate(), no process-group signal, and BusyBox has no setsid to
// build one) leaves its backgrounded children running as orphans. Here every command
// gets its OWN proc_open() call, so PHP holds a direct handle to each one and
// proc_terminate()s it individually on timeout — nothing is ever an orphan.
//
// Each command must be prefixed with `exec` (same convention as every other
// shell_run_timeout() caller in this codebase — route_in_vpn_set()'s nslookup,
// connections_snapshot()'s conntrack): proc_open() always spawns `sh -c $cmd`, so
// without `exec` the process PHP can see and terminate is the wrapping sh, not the
// command — `exec` replaces sh's own process image with the command's, keeping the
// same pid, so proc_terminate() actually reaches it.
//
// Returns each command's trimmed stdout in $cmds' order; null for one whose
// proc_open() call itself failed to start.
function shell_run_parallel_timeout(array $cmds, float $seconds): array {
    $procs = [];
    $pipes = [];
    $outputs = [];
    foreach ($cmds as $i => $cmd) {
        $withRedirect = strpos($cmd, '2>') === false ? $cmd . ' 2>&1' : $cmd;
        $proc = @proc_open($withRedirect, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p);
        if (!is_resource($proc)) { $outputs[$i] = null; continue; }
        stream_set_blocking($p[1], false);
        $procs[$i] = $proc;
        $pipes[$i] = $p;
        $outputs[$i] = '';
    }

    $deadline = microtime(true) + $seconds;
    do {
        $running = false;
        foreach ($procs as $i => $proc) {
            $outputs[$i] .= stream_get_contents($pipes[$i][1]);
            if (proc_get_status($proc)['running']) $running = true;
        }
        if (!$running) break;
        usleep(20000);
    } while (microtime(true) < $deadline);

    foreach ($procs as $i => $proc) {
        if (proc_get_status($proc)['running']) proc_terminate($proc, 9);
        $outputs[$i] .= stream_get_contents($pipes[$i][1]);
        foreach ($pipes[$i] as $p) { if (is_resource($p)) fclose($p); }
        proc_close($proc);
        $outputs[$i] = trim($outputs[$i]);
    }
    return $outputs;
}
