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

// Flips 'enabled' to true for the entry with this id in $list (keys.json or
// cached_servers.json contents), leaving every other entry and field untouched.
// Only subscriptions can be turned off in the UI now, so select_server calls this
// to make sure the server the user just picked is actually usable, even if it had
// been left enabled:false (e.g. from before per-server switches were removed).
function enable_server(array $list, string $id): array {
    foreach ($list as &$item) {
        if (($item['id'] ?? '') === $id) $item['enabled'] = true;
    }
    unset($item);
    return $list;
}

// Whether a server can carry traffic: its own 'enabled' flag, and for a cached
// subscription server (one with a 'sub' field) also its subscription's flag —
// a disabled subscription disables all of its servers. $subs is list.json
// (subscriptions); a subscription id not found there doesn't disable anything.
function effective_enabled(array $srv, array $subs): bool {
    if (empty($srv['enabled'])) return false;
    $subId = (string)($srv['sub'] ?? '');
    if ($subId === '') return true;
    foreach ($subs as $sub) {
        if (($sub['id'] ?? null) === $subId) {
            return !(array_key_exists('enabled', $sub) && !$sub['enabled']);
        }
    }
    return true;
}

// First enabled key, else first enabled cached server, else null.
function _first_enabled_id(array $keys, array $cached, array $subs = []): ?string {
    foreach ($keys as $k) {
        if (effective_enabled($k, $subs) && !empty($k['id'])) return $k['id'];
    }
    foreach ($cached as $srv) {
        if (effective_enabled($srv, $subs) && !empty($srv['id'])) return $srv['id'];
    }
    return null;
}

// Resolves which outbound id should actually be used, given the recorded
// selection in $state and the current keys/cached servers. Never throws and
// never assumes the recorded id still exists — that's the whole point.
// $subs (list.json) makes servers of a disabled subscription count as disabled
// ('fallback_disabled'); callers without it see only the servers' own flags.
function resolve_active(array $state, array $keys, array $cached, array $subs = []): array {
    $active_id = $state['active_outbound'] ?? '';

    if ($active_id === '') {
        return ['id' => _first_enabled_id($keys, $cached, $subs), 'reason' => 'default'];
    }

    foreach (array_merge($keys, $cached) as $srv) {
        if (($srv['id'] ?? null) === $active_id) {
            if (effective_enabled($srv, $subs)) {
                return ['id' => $active_id, 'reason' => 'selected'];
            }
            return ['id' => _first_enabled_id($keys, $cached, $subs), 'reason' => 'fallback_disabled'];
        }
    }

    return ['id' => _first_enabled_id($keys, $cached, $subs), 'reason' => 'fallback_missing'];
}

// cached_servers.json without the servers of subscription $subId (used when that
// subscription is deleted, so its servers stop being outbounds). Order is kept;
// entries without a 'sub' field and an empty/unknown $subId change nothing.
function purge_cached_for_sub(array $cached, string $subId): array {
    if ($subId === '') return $cached;
    return array_values(array_filter($cached, fn($s) => ($s['sub'] ?? '') !== $subId));
}

// New cached_servers.json after a subscription refresh, subscription by
// subscription in $subs order:
// - a disabled subscription (or one without a url) was not fetched: its
//   previously cached servers are kept as they are;
// - a subscription whose fetch failed or came back empty ($fetchedBySub has no
//   entry for it, or an empty one): its previous servers are kept, so a TSPU
//   reset or a host being down never wipes them;
// - otherwise its fetched servers replace the old ones, each keeping the
//   user's 'enabled' flag: by id (md5(link), stable while the link is), else —
//   the link was reissued — by the same name+proto match rematch_server() uses
//   for the active selection, among that subscription's old servers.
// Servers of subscriptions no longer in $subs (and entries with no 'sub') are
// dropped, as the old rebuild-from-scratch did.
//
// $fetchedBySub: sub id => list of fresh server records (id, name, link,
// enabled, sub).
function merge_refreshed_servers(array $old, array $fetchedBySub, array $subs): array {
    $oldBySub = [];
    foreach ($old as $srv) {
        $oldBySub[(string)($srv['sub'] ?? '')][] = $srv;
    }

    $merged = [];
    foreach ($subs as $sub) {
        $subId = (string)($sub['id'] ?? '');
        $previous = $oldBySub[$subId] ?? [];
        $fetched = $fetchedBySub[$subId] ?? [];
        if (empty($sub['enabled']) || empty($sub['url']) || empty($fetched)) {
            foreach ($previous as $srv) $merged[] = $srv;
            continue;
        }

        $flagById = [];
        $flagByNameProto = [];
        foreach ($previous as $srv) {
            $enabled = !empty($srv['enabled']);
            if (isset($srv['id'])) $flagById[$srv['id']] = $enabled;
            $h = server_hint($srv);
            $key = $h['name'] . "\0" . $h['proto'];
            if (!array_key_exists($key, $flagByNameProto)) $flagByNameProto[$key] = $enabled;
        }
        foreach ($fetched as $srv) {
            $id = $srv['id'] ?? null;
            if ($id !== null && array_key_exists($id, $flagById)) {
                $srv['enabled'] = $flagById[$id];
            } else {
                $h = server_hint($srv);
                $key = $h['name'] . "\0" . $h['proto'];
                if (array_key_exists($key, $flagByNameProto)) $srv['enabled'] = $flagByNameProto[$key];
            }
            $merged[] = $srv;
        }
    }
    return $merged;
}

// Splits pasted text (one or many lines) into subscription URLs, single-server
// keys, and lines that are neither — the pure half of the one-field "add"
// (api.php's add_link does the actual writing/fetching/applying). Blank lines
// are silently dropped; anything else link_kind() doesn't recognize is
// reported in 'skipped' so the UI can tell the user which line was bad.
//
// vmess:// is deliberately never bucketed into 'keys': there is no vmess
// outbound builder (build_outbound_from_link() has no vmess branch), so a
// vmess key added here would silently never route any traffic. link_kind()
// already doesn't classify it as a 'key' either; called out explicitly here
// (with its own reason) so it isn't lumped in with genuinely unrecognized
// input.
function classify_lines(string $text): array {
    $subscriptions = [];
    $keys = [];
    $skipped = [];
    foreach (explode("\n", $text) as $raw) {
        $line = trim($raw);
        if ($line === '') continue;
        if (stripos($line, 'vmess://') === 0) {
            $skipped[] = ['line' => $line, 'reason' => 'vmess не поддерживается'];
            continue;
        }
        $kind = link_kind($line);
        if ($kind === 'subscription') {
            $subscriptions[] = $line;
        } elseif ($kind === 'key') {
            $keys[] = $line;
        } else {
            $skipped[] = ['line' => $line, 'reason' => 'unrecognized'];
        }
    }
    return ['subscriptions' => $subscriptions, 'keys' => $keys, 'skipped' => $skipped];
}

// Builds the unified server list the UI renders: one row per source
// (subscription, plus a single 'keys' source for manually-added keys) and one
// row per server (keys.json + cached_servers.json entries), decorated with
// this install's favorite flags and whatever ping/probe results are cached.
//
// $flags: server_flags.json contents, id => ['favorite' => bool].
// $pingCache: xray-ping.json's 'results' map, id => ms|null (or [] if absent).
// $probeCache: xray-probe.json contents as-is, id => probe row (or [] if absent).
// $activeId: the currently active outbound id, or null.
function list_servers(
    array $keys, array $cached, array $subs, array $flags,
    array $pingCache, array $probeCache, ?string $activeId
): array {
    $sources = [];
    foreach ($subs as $sub) {
        $subId = $sub['id'] ?? '';
        $count = 0;
        foreach ($cached as $s) {
            if (($s['sub'] ?? '') === $subId) $count++;
        }
        $sources[] = [
            'id'      => $subId,
            'kind'    => 'subscription',
            'name'    => $sub['name'] ?? '',
            'count'   => $count,
            'updated' => $sub['updated'] ?? '',
            'error'   => $sub['last_error'] ?? null,
            'enabled' => !empty($sub['enabled']),
        ];
    }
    $sources[] = [
        'id'      => 'keys',
        'kind'    => 'keys',
        'name'    => 'Ключи',
        'count'   => count($keys),
        'updated' => null,
        'error'   => null,
        'enabled' => true,
    ];

    $servers = [];
    foreach ($keys as $k) {
        $servers[] = _server_row($k, 'keys', $flags, $pingCache, $probeCache, $activeId);
    }
    foreach ($cached as $s) {
        $servers[] = _server_row($s, (string)($s['sub'] ?? ''), $flags, $pingCache, $probeCache, $activeId);
    }

    return ['sources' => $sources, 'servers' => $servers];
}

function _server_row(
    array $srv, string $source, array $flags,
    array $pingCache, array $probeCache, ?string $activeId
): array {
    $id = $srv['id'] ?? '';
    $hp = link_host_port($srv['link'] ?? '');
    $probe = is_array($probeCache[$id] ?? null) ? $probeCache[$id] : [];
    return [
        'id'             => $id,
        'source'         => $source,
        'name'           => $srv['name'] ?? '',
        'proto'          => link_proto($srv['link'] ?? ''),
        'host'           => $hp['host'],
        'port'           => $hp['port'],
        'enabled'        => !empty($srv['enabled']),
        'favorite'       => !empty($flags[$id]['favorite'] ?? false),
        'active'         => $activeId !== null && $id !== '' && $id === $activeId,
        'ping_ms'        => $pingCache[$id] ?? null,
        'google_country' => $probe['google_country'] ?? null,
        'exit_country'   => $probe['exit_country'] ?? null,
    ];
}
