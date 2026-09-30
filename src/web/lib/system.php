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

// Longest line shell_lines_timeout() buffers; anything longer is dropped, so a runaway
// line can never grow into a multi-MB string.
const SHELL_LINE_MAX = 65536;

// Like shell_run_timeout(), but hands the output over line by line (without the "\n")
// instead of as one string, so a big output (`conntrack -L` on a busy router) never sits
// in memory whole — the router's PHP memory_limit is 8M. Returns null when the process
// could not be started. The process is killed once $seconds have passed (the lines read
// so far stay delivered), or as soon as the caller stops iterating and drops the
// generator — unset() it right after the loop so that happens there, not at scope end.
// Prefix $cmd with `exec`, as for shell_run_timeout().
function shell_lines_timeout(string $cmd, float $seconds): ?Generator {
    $withRedirect = strpos($cmd, '2>') === false ? $cmd . ' 2>&1' : $cmd;
    $proc = @proc_open($withRedirect, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return null;
    return _proc_output_lines($proc, $pipes, microtime(true) + $seconds);
}

function _proc_output_lines($proc, array $pipes, float $deadline): Generator {
    $out = $pipes[1];
    stream_set_blocking($out, false);
    $buf = '';
    try {
        while (microtime(true) < $deadline) {
            $chunk = fread($out, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($out)) {
                    if ($buf !== '') yield $buf;
                    break;
                }
                $r = [$out];
                $w = $e = null;
                $wait = (int)max(1000, min(100000, ($deadline - microtime(true)) * 1e6));
                @stream_select($r, $w, $e, 0, $wait);
                continue;
            }
            $buf .= $chunk;
            $pos = 0;
            while (($nl = strpos($buf, "\n", $pos)) !== false) {
                yield substr($buf, $pos, $nl - $pos);
                $pos = $nl + 1;
            }
            $buf = substr($buf, $pos);
            if (strlen($buf) > SHELL_LINE_MAX) $buf = '';
        }
    } finally {
        if (proc_get_status($proc)['running']) proc_terminate($proc, 9);
        foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
        proc_close($proc);
    }
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

// ============================================================================
// IP literal validation
//
// The router's PHP 8.4 build has no `filter` extension, so filter_var()/
// FILTER_VALIDATE_IP are never available — every caller that used to call
// them now uses these instead. Built on inet_pton() (core PHP, not an
// extension) plus the same strict format checks filter_var() itself applied
// (no leading/trailing junk, no leading-zero octets, which PHP's own IPv4
// validator rejects to avoid octal ambiguity). None of these accept a CIDR
// suffix ("1.2.3.4/24") — callers that allow one strip it before calling.
// ============================================================================

// A plain IPv4 literal: four dot-separated decimal octets, 0-255, no leading
// zeros (so "010.0.0.1" is rejected, same as filter_var() was), nothing else
// in the string. Replaces filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4).
function ip_is_v4(string $s): bool {
    if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $s)) return false;
    foreach (explode('.', $s) as $octet) {
        if ((strlen($octet) > 1 && $octet[0] === '0') || (int)$octet > 255) return false;
    }
    return @inet_pton($s) !== false;
}

// A plain IPv6 literal: inet_pton() must accept it AND it must actually
// contain ':' (inet_pton() also accepts a bare IPv4 dotted-quad, which is
// not an IPv6 literal here). Replaces
// filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6).
function ip_is_v6(string $s): bool {
    return strpos($s, ':') !== false && @inet_pton($s) !== false;
}

// Any IP literal, v4 or v6, no CIDR suffix. Replaces
// filter_var($s, FILTER_VALIDATE_IP).
function ip_is_valid(string $s): bool {
    return ip_is_v4($s) || ip_is_v6($s);
}
