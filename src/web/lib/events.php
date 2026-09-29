<?php
// Human-readable event journal: a JSON-lines file the UI renders as a
// "what happened" feed (server switch, service start/stop, subscription
// refresh, rule changes, watchdog pause/resume, firewall flow kicks...).
//
// Pure logic only: no globals, no header()/session/file I/O beyond what's
// passed in, so this file can be require_once'd standalone from tests.

const EVENTS_DEFAULT_FILE = '/opt/var/log/xray-vpn/events.log';
const EVENTS_MAX_BYTES = 262144;

// Appends one JSON line: {"ts","level","type","msg","data"}.
//
// $featuresProvider lets callers (api.php) inject their own features reader
// (e.g. `fn() => get_features()`) so this stays testable standalone: with no
// provider, or when the provider's result has no 'diag_events' key, events
// are enabled by default — only an explicit `false` silences them.
function emit_event(
    string $level,
    string $type,
    string $msg,
    array $data = [],
    ?string $file = null,
    ?callable $featuresProvider = null
): void {
    $features = $featuresProvider ? $featuresProvider() : [];
    if (($features['diag_events'] ?? true) === false) return;

    $file = $file ?? EVENTS_DEFAULT_FILE;
    $dir = dirname($file);
    if ($dir !== '' && !is_dir($dir)) @mkdir($dir, 0755, true);

    // Rotate before writing so the current file never grows past the limit.
    if (is_file($file) && filesize($file) > EVENTS_MAX_BYTES) {
        @rename($file, $file . '.1');
    }

    $line = json_encode([
        'ts'    => date('c'),
        'level' => $level,
        'type'  => $type,
        'msg'   => $msg,
        'data'  => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

// Newest first. Reads events.log.1 too once the current file is exhausted —
// rotation guarantees every entry in $file is newer than every entry in
// "$file.1", so the two files can simply be read in that order.
function read_events(int $limit = 100, ?string $level = null, ?string $type = null, ?string $file = null): array {
    $file = $file ?? EVENTS_DEFAULT_FILE;
    $events = [];
    foreach ([$file, $file . '.1'] as $f) {
        if ($limit > 0 && count($events) >= $limit) break;
        if (!is_file($f)) continue;
        $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_reverse($lines) as $line) {
            $ev = json_decode($line, true);
            if (!is_array($ev)) continue;
            if ($level !== null && ($ev['level'] ?? '') !== $level) continue;
            if ($type !== null && ($ev['type'] ?? '') !== $type) continue;
            $events[] = $ev;
            if ($limit > 0 && count($events) >= $limit) break;
        }
    }
    return $events;
}
