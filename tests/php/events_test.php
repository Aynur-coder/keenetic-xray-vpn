<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/events.php';

function _events_tmp_file(): string {
    return tempnam(sys_get_temp_dir(), 'xray_events_') . '.log';
}

function test_emit_and_read_newest_first(): void {
    $file = _events_tmp_file();
    @unlink($file);
    emit_event('info', 'server', 'first', [], $file);
    emit_event('info', 'server', 'second', [], $file);
    emit_event('info', 'server', 'third', [], $file);

    $events = read_events(100, null, null, $file);
    eq(count($events), 3, 'reads all three events');
    eq($events[0]['msg'], 'third', 'newest event first');
    eq($events[1]['msg'], 'second', 'middle event second');
    eq($events[2]['msg'], 'first', 'oldest event last');
    eq($events[0]['level'], 'info', 'level round-trips');
    eq($events[0]['type'], 'server', 'type round-trips');
    eq(isset($events[0]['ts']) && $events[0]['ts'] !== '', true, 'ts is set');

    @unlink($file);
}

function test_filter_level(): void {
    $file = _events_tmp_file();
    @unlink($file);
    emit_event('info', 'server', 'info one', [], $file);
    emit_event('warn', 'watchdog', 'warn one', [], $file);
    emit_event('info', 'rules', 'info two', [], $file);

    $warnOnly = read_events(100, 'warn', null, $file);
    eq(count($warnOnly), 1, 'only warn-level events returned');
    eq($warnOnly[0]['msg'], 'warn one', 'correct warn event returned');

    $rulesOnly = read_events(100, null, 'rules', $file);
    eq(count($rulesOnly), 1, 'only rules-type events returned');
    eq($rulesOnly[0]['msg'], 'info two', 'correct rules event returned');

    @unlink($file);
}

function test_rotation_at_256k(): void {
    $file = _events_tmp_file();
    @unlink($file);

    // Pre-fill with ~300 KB of valid event lines so the next emit_event call
    // sees the file already over the 256 KB threshold and rotates it first.
    $filler = str_repeat('x', 500);
    $fh = fopen($file, 'w');
    while (filesize($file) < 300 * 1024) {
        fwrite($fh, json_encode(['ts' => date('c'), 'level' => 'info', 'type' => 'server', 'msg' => $filler, 'data' => []]) . "\n");
        fflush($fh);
        clearstatcache(true, $file);
    }
    fclose($fh);

    emit_event('info', 'service', 'latest after rotation', [], $file);

    eq(file_exists($file . '.1'), true, 'rotated file exists');
    clearstatcache(true, $file);
    eq(filesize($file) < 262144, true, 'current file is under the size limit after rotation');

    $events = read_events(1, null, null, $file);
    eq($events[0]['msg'], 'latest after rotation', 'read_events still returns the latest event across rotation');

    @unlink($file);
    @unlink($file . '.1');
}

// emit_event() always stamps "now", so timestamp-filter fixtures write lines with
// explicit, known 'ts' values directly instead.
function _events_line(string $ts, string $msg): string {
    $ev = ['ts' => $ts, 'level' => 'info', 'type' => 'server', 'msg' => $msg, 'data' => []];
    return json_encode($ev) . "\n";
}

function test_filter_since(): void {
    $file = _events_tmp_file();
    @unlink($file);
    file_put_contents(
        $file,
        _events_line('2020-01-01T00:00:00+00:00', 'past event')
        . _events_line('2025-06-01T00:00:00+00:00', 'boundary event')
        . _events_line('2026-01-01T00:00:00+00:00', 'new event')
    );

    $recent = read_events(100, null, null, $file, '2025-06-01T00:00:00+00:00');
    eq(count($recent), 2, 'events at or after since are kept, the older one dropped');
    eq(array_column($recent, 'msg'), ['new event', 'boundary event'], 'newest-first, boundary inclusive');

    $all = read_events(100, null, null, $file, null);
    eq(count($all), 3, 'no since -> unfiltered');

    $unparsable = read_events(100, null, null, $file, 'not-a-real-date');
    eq(count($unparsable), 3, 'an unparsable since is ignored, not treated as excluding everything');

    @unlink($file);
}

function test_disabled_feature_no_write(): void {
    $file = _events_tmp_file();
    @unlink($file);

    emit_event('info', 'server', 'should not be written', [], $file, fn() => ['diag_events' => false]);
    eq(file_exists($file), false, 'no file is created when diag_events is disabled');

    // No provider at all, or a provider whose result has no 'diag_events' key,
    // both default to enabled.
    emit_event('info', 'server', 'should be written', [], $file);
    eq(file_exists($file), true, 'writes happen with no features provider (default enabled)');
    $events = read_events(100, null, null, $file);
    eq(count($events), 1, 'the write went through');

    @unlink($file);
}
