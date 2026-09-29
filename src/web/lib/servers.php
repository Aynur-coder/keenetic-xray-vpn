<?php
// Stable server selection across subscription link changes.
//
// keys.json and cached_servers.json entries get a fresh id whenever a link
// changes (cached ids are md5(link)), so state.json's active_outbound can
// point at an id that no longer exists after update_subscriptions. This
// module lets api.php remember *what* was selected (name/host/port/proto,
// not just the volatile id) and find it again, or fall back predictably.
//
// Pure logic only: no globals, no header()/session/file I/O beyond what's
// passed in, so this file can be require_once'd standalone from tests.

require_once __DIR__ . '/links.php';

// Server record (keys.json or cached_servers.json entry) -> a stable
// description used to re-find it after its id/link changes.
function server_hint(array $srv): array {
    $name = $srv['name'] ?? '';
    $link = $srv['link'] ?? '';
    $host = '';
    $port = 0;
    $proto = '';

    if (strpos($link, 'vless://') === 0) {
        $v = parse_vless_link($link);
        if ($v) { $host = $v['address']; $port = $v['port']; $proto = 'vless'; }
    } elseif (strpos($link, 'ss://') === 0) {
        $s = parse_ss_link($link);
        if ($s) { $host = $s['address']; $port = $s['port']; $proto = 'shadowsocks'; }
    } elseif (is_hysteria2_link($link)) {
        $h = parse_hysteria2_link($link);
        if ($h) { $host = $h['address']; $port = $h['port']; $proto = 'hysteria2'; }
    }

    return ['name' => $name, 'host' => $host, 'port' => $port, 'proto' => $proto];
}

// Finds the id of the enabled server in $servers that best matches $hint:
// same name+proto first (a subscription can list the same name twice, once
// per protocol, so proto disambiguates), then same host+port, else null.
//
// $servers is always a subscription's cached server list. A hint recorded for
// a *key* selection (source === 'key') must never be rematched onto one of
// these — a deleted key coincidentally sharing a name with a subscription
// server is not "the same server found again", it's an unrelated one. A hint
// with no 'source' (state.json written before this field existed) is treated
// as a subscription hint, matching the old unconditional-rematch behavior.
function rematch_server(array $hint, array $servers): ?string {
    if (($hint['source'] ?? 'sub') !== 'sub') return null;

    foreach ($servers as $srv) {
        if (empty($srv['enabled'])) continue;
        $h = server_hint($srv);
        if ($h['name'] === $hint['name'] && $h['proto'] === $hint['proto']) {
            return $srv['id'] ?? null;
        }
    }
    foreach ($servers as $srv) {
        if (empty($srv['enabled'])) continue;
        $h = server_hint($srv);
        if ($h['host'] === $hint['host'] && $h['port'] === $hint['port']) {
            return $srv['id'] ?? null;
        }
    }
    return null;
}

// First enabled key, else first enabled cached server, else null.
function _first_enabled_id(array $keys, array $cached): ?string {
    foreach ($keys as $k) {
        if (!empty($k['enabled']) && !empty($k['id'])) return $k['id'];
    }
    foreach ($cached as $srv) {
        if (!empty($srv['enabled']) && !empty($srv['id'])) return $srv['id'];
    }
    return null;
}

// Resolves which outbound id should actually be used, given the recorded
// selection in $state and the current keys/cached servers. Never throws and
// never assumes the recorded id still exists — that's the whole point.
function resolve_active(array $state, array $keys, array $cached): array {
    $active_id = $state['active_outbound'] ?? '';

    if ($active_id === '') {
        return ['id' => _first_enabled_id($keys, $cached), 'reason' => 'default'];
    }

    foreach (array_merge($keys, $cached) as $srv) {
        if (($srv['id'] ?? null) === $active_id) {
            if (!empty($srv['enabled'])) {
                return ['id' => $active_id, 'reason' => 'selected'];
            }
            return ['id' => _first_enabled_id($keys, $cached), 'reason' => 'fallback_disabled'];
        }
    }

    return ['id' => _first_enabled_id($keys, $cached), 'reason' => 'fallback_missing'];
}
