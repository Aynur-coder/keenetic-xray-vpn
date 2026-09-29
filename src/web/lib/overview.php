<?php
// One consistent status for the UI: a single `overview` action replaces the
// several ad-hoc reads (status, subscription health, probe cache, update
// cache) the old UI stitched together itself, each with its own idea of what
// "the VPN is broken" means.
//
// Pure logic only: no globals, no header()/session/file I/O beyond what's
// passed in, so this file can be require_once'd standalone from tests.

require_once __DIR__ . '/servers.php';

const OVERVIEW_MSG_WATCHDOG_PAUSED = 'Сервер недоступен — VPN на паузе';
const OVERVIEW_MSG_GOOGLE_RU =
    'Google видит выход этого сервера как Россию — Gemini и YouTube могут не работать';

/**
 * Builds the single status object the UI renders from.
 *
 * $in keys: xray_running (bool), watchdog (string: '' | 'ok' | 'paused'),
 * state (array: state.json contents), servers (array of rows
 * ['id','name','proto','enabled'] built from keys + cached servers),
 * subscription_health (?array: subscription_health()'s return),
 * probe (?array: id => ['google_country' => ?string, ...], or null when the
 * probe cache file is absent), mem ([used, total]), wg_up (bool),
 * version (string), update_available (bool), features (array).
 *
 * @return array{state: string, reason: ?string, active: ?array,
 *   effective: ?array, effective_reason: string, warnings: array,
 *   mem_used: int, mem_total: int, wg_up: bool, version: string,
 *   update_available: bool, features: array}
 */
function build_overview(array $in): array {
    $state = is_array($in['state'] ?? null) ? $in['state'] : [];
    $servers = is_array($in['servers'] ?? null) ? $in['servers'] : [];
    $watchdog = (string)($in['watchdog'] ?? '');
    $xrayRunning = !empty($in['xray_running']);

    // A rejected config (apply.php writes last_apply_error, cleared on the next
    // successful apply) wins over everything else: Xray may still be serving
    // traffic on the old config, but the change the user just made didn't take.
    $lastApplyError = $state['last_apply_error'] ?? null;
    if ($lastApplyError !== null && $lastApplyError !== '') {
        $overviewState = 'config_error';
        $reason = (string)$lastApplyError;
    } elseif ($watchdog === 'paused') {
        $overviewState = 'paused';
        $reason = null;
    } elseif ($xrayRunning) {
        $overviewState = 'running';
        $reason = null;
    } else {
        $overviewState = 'stopped';
        $reason = null;
    }

    $activeId = $state['active_outbound'] ?? null;
    $active = ($activeId !== null && $activeId !== '')
        ? overview_find_server($servers, $activeId) : null;

    // effective_outbound/effective_reason are written to state.json only once
    // Xray actually accepted a config built around them (apply.php), and are
    // already plain ids there (never a 'key-'/'sub-' tag prefix — api.php's
    // config generator resolves the tag back to the id before returning it).
    // Before the first successful apply, fall back to the same resolution
    // apply would use.
    if (array_key_exists('effective_outbound', $state)) {
        $effectiveId = $state['effective_outbound'];
        $effectiveReason = (string)($state['effective_reason'] ?? '');
    } else {
        $resolved = resolve_active($state, $servers, []);
        $effectiveId = $resolved['id'];
        $effectiveReason = $resolved['reason'];
    }
    $effective = $effectiveId !== null ? overview_find_server($servers, $effectiveId) : null;

    $warnings = [];

    $subHealth = $in['subscription_health'] ?? null;
    if (is_array($subHealth) && isset($subHealth['code'], $subHealth['message'])) {
        $warnings[] = ['code' => $subHealth['code'], 'message' => $subHealth['message']];
    }

    if ($effectiveReason === 'fallback_missing') {
        $name = $effective['name'] ?? (string)$effectiveId;
        $warnings[] = ['code' => 'selected_missing',
            'message' => 'Выбранный сервер пропал из подписки — сейчас используется ' . $name];
    }

    if ($watchdog === 'paused') {
        $warnings[] = ['code' => 'watchdog_paused', 'message' => OVERVIEW_MSG_WATCHDOG_PAUSED];
    }

    $probe = is_array($in['probe'] ?? null) ? $in['probe'] : null;
    if ($probe !== null && $effectiveId !== null
        && ($probe[$effectiveId]['google_country'] ?? null) === 'RU') {
        $warnings[] = ['code' => 'google_ru', 'message' => OVERVIEW_MSG_GOOGLE_RU];
    }

    $mem = is_array($in['mem'] ?? null) ? $in['mem'] : [0, 0];

    return [
        'state'             => $overviewState,
        'reason'            => $reason,
        'active'            => $active,
        'effective'         => $effective,
        'effective_reason'  => $effectiveReason,
        'warnings'          => $warnings,
        'mem_used'          => (int)($mem[0] ?? 0),
        'mem_total'         => (int)($mem[1] ?? 0),
        'wg_up'             => !empty($in['wg_up']),
        'version'           => (string)($in['version'] ?? ''),
        'update_available'  => !empty($in['update_available']),
        'features'          => is_array($in['features'] ?? null) ? $in['features'] : [],
    ];
}

// Finds $id among $servers (rows with 'id'/'name'/'proto') and returns the
// display object the UI needs, or null when it isn't in the list anymore.
function overview_find_server(array $servers, $id): ?array {
    foreach ($servers as $s) {
        if (($s['id'] ?? null) === $id) {
            return ['id' => $s['id'], 'name' => $s['name'] ?? '', 'proto' => $s['proto'] ?? ''];
        }
    }
    return null;
}
