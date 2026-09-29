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
