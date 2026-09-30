<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/servers.php';

function _sthlm_vless(string $id): array {
    return [
        'id' => $id, 'name' => '🇸🇪 Стокгольм [Wi-Fi]', 'enabled' => true,
        'link' => 'vless://11111111-1111-1111-1111-111111111111@old.example:443?security=reality'
            . '&sni=example.com&fp=chrome&pbk=PUBKEY&sid=abcd#' . rawurlencode('🇸🇪 Стокгольм [Wi-Fi]'),
    ];
}

function test_server_hint_vless(): void {
    $srv = _sthlm_vless('abc');
    $hint = server_hint($srv);
    eq($hint['name'], '🇸🇪 Стокгольм [Wi-Fi]', 'name from server record');
    eq($hint['host'], 'old.example', 'host parsed from vless link');
    eq($hint['port'], 443, 'port parsed from vless link');
    eq($hint['proto'], 'vless', 'proto is vless');
}

function test_server_hint_hysteria2(): void {
    $srv = ['id' => 'h1', 'name' => 'HY2 Server', 'enabled' => true,
        'link' => 'hysteria2://pw@hy.example:443/?sni=hy.example'];
    $hint = server_hint($srv);
    eq($hint['host'], 'hy.example', 'host parsed from hysteria2 link');
    eq($hint['port'], 443, 'port parsed from hysteria2 link');
    eq($hint['proto'], 'hysteria2', 'proto is hysteria2');
}

function test_rematch_by_name_proto(): void {
    // The server that used to hold the selected id is gone (link/id changed after a
    // subscription refresh); a new entry with the same name+protocol should be found.
    $hint = server_hint(_sthlm_vless('old-id-gone'));
    $servers = [
        ['id' => 'ru1', 'name' => 'Нюрнберг', 'enabled' => true,
            'link' => 'vless://22222222-2222-2222-2222-222222222222@other.example:443?security=reality'
                . '&sni=example.com&fp=chrome&pbk=X&sid=1234'],
        // Same name+proto as the old selection, new host/port/id.
        ['id' => 'new-id', 'name' => '🇸🇪 Стокгольм [Wi-Fi]', 'enabled' => true,
            'link' => 'vless://33333333-3333-3333-3333-333333333333@new.example:8443?security=reality'
                . '&sni=example.com&fp=chrome&pbk=Y&sid=5678'],
    ];
    eq(rematch_server($hint, $servers), 'new-id', 'matched by name+proto');
}

function test_rematch_prefers_name_over_host(): void {
    $hint = server_hint(_sthlm_vless('old-id-gone'));
    $servers = [
        // Same host:port as the old link, but a different name+proto: a decoy.
        ['id' => 'host-match', 'name' => 'Другое имя', 'enabled' => true,
            'link' => 'ss://YWVzLTI1Ni1nY206cGFzcw==@old.example:443'],
        // Same name+proto, different host/port: this must win.
        ['id' => 'name-match', 'name' => '🇸🇪 Стокгольм [Wi-Fi]', 'enabled' => true,
            'link' => 'vless://33333333-3333-3333-3333-333333333333@new.example:8443?security=reality'
                . '&sni=example.com&fp=chrome&pbk=Y&sid=5678'],
    ];
    eq(rematch_server($hint, $servers), 'name-match', 'name+proto takes priority over host+port');
}

function test_rematch_by_host_port(): void {
    // Name/protocol changed (e.g. renamed on the subscription, or reissued as ss)
    // but it is still reachable at the same host:port.
    $hint = server_hint(_sthlm_vless('old-id-gone'));
    $servers = [
        ['id' => 'ru1', 'name' => 'Нюрнберг', 'enabled' => true,
            'link' => 'vless://22222222-2222-2222-2222-222222222222@other.example:443?security=reality'
                . '&sni=example.com&fp=chrome&pbk=X&sid=1234'],
        ['id' => 'host-match', 'name' => 'Renamed Stockholm', 'enabled' => true,
            'link' => 'vless://44444444-4444-4444-4444-444444444444@old.example:443?security=reality'
                . '&sni=example.com&fp=chrome&pbk=Z&sid=9999'],
    ];
    eq(rematch_server($hint, $servers), 'host-match', 'matched by host+port when name+proto missing');
}

function test_rematch_none(): void {
    $hint = server_hint(_sthlm_vless('old-id-gone'));
    $servers = [
        ['id' => 'ru1', 'name' => 'Нюрнберг', 'enabled' => true,
            'link' => 'vless://22222222-2222-2222-2222-222222222222@other.example:443?security=reality'
                . '&sni=example.com&fp=chrome&pbk=X&sid=1234'],
    ];
    eq(rematch_server($hint, $servers), null, 'no name+proto or host+port match');
}

function test_rematch_skipped_for_deleted_key(): void {
    // The active selection was a *key* (not a subscription server) that has since been
    // deleted. Even though a subscription server with the same name+proto now exists,
    // it must not be treated as "found again" — that would silently move the selection
    // onto an unrelated server. rematch_server() returning null here is what keeps
    // update_subscriptions from touching active_outbound, so resolve_active() reports
    // fallback_missing instead.
    $hint = server_hint(_sthlm_vless('deleted-key-id'));
    $hint['source'] = 'key';
    $servers = [
        ['id' => 'new-id', 'name' => '🇸🇪 Стокгольм [Wi-Fi]', 'enabled' => true,
            'link' => 'vless://33333333-3333-3333-3333-333333333333@new.example:8443?security=reality'
                . '&sni=example.com&fp=chrome&pbk=Y&sid=5678'],
    ];
    eq(rematch_server($hint, $servers), null, 'a key-sourced hint is never rematched onto a subscription server');
}

function test_rematch_legacy_hint_without_source(): void {
    // state.json written before this field existed has no 'source' key at all — treat
    // it as a subscription hint (the old unconditional-rematch behavior) rather than
    // refusing to rematch it.
    $hint = server_hint(_sthlm_vless('old-id-gone'));
    eq(isset($hint['source']), false, 'server_hint() itself never adds a source');
    $servers = [
        ['id' => 'new-id', 'name' => '🇸🇪 Стокгольм [Wi-Fi]', 'enabled' => true,
            'link' => 'vless://33333333-3333-3333-3333-333333333333@new.example:8443?security=reality'
                . '&sni=example.com&fp=chrome&pbk=Y&sid=5678'],
    ];
    eq(rematch_server($hint, $servers), 'new-id', 'a legacy hint with no source still rematches');
}

function test_rematch_skips_disabled(): void {
    $hint = server_hint(_sthlm_vless('old-id-gone'));
    $servers = [
        ['id' => 'disabled-match', 'name' => '🇸🇪 Стокгольм [Wi-Fi]', 'enabled' => false,
            'link' => 'vless://33333333-3333-3333-3333-333333333333@new.example:8443?security=reality'
                . '&sni=example.com&fp=chrome&pbk=Y&sid=5678'],
    ];
    eq(rematch_server($hint, $servers), null, 'a disabled server is never a rematch target');
}

function test_enable_on_select(): void {
    // select_server always makes the chosen server usable, even if it had been left
    // enabled:false (from before per-server switches were removed from the UI, or via
    // a subscription refresh that carried the flag over) — only subscriptions can be
    // turned off now, so a server the user explicitly picked must run.
    $list = [
        ['id' => 'a', 'name' => 'A', 'enabled' => false],
        ['id' => 'b', 'name' => 'B', 'enabled' => false],
    ];
    $out = enable_server($list, 'a');
    eq($out[0]['enabled'], true, 'target id flipped to enabled');
    eq($out[1]['enabled'], false, 'other entries left untouched');
    eq($out[0]['name'], 'A', 'other fields untouched');

    eq(enable_server($list, 'missing'), $list, 'unknown id leaves the list unchanged');

    $already = [['id' => 'a', 'enabled' => true]];
    eq(enable_server($already, 'a'), $already, 'already-enabled entry is left as is');
}

function test_resolve_selected(): void {
    $state = ['active_outbound' => 'k1'];
    $keys = [['id' => 'k1', 'name' => 'Key 1', 'enabled' => true, 'link' => 'vless://u@h:1']];
    $cached = [];
    eq(resolve_active($state, $keys, $cached), ['id' => 'k1', 'reason' => 'selected'], 'enabled selection kept as-is');
}

function test_resolve_missing_fallback(): void {
    $state = ['active_outbound' => 'gone'];
    $keys = [['id' => 'k1', 'name' => 'Key 1', 'enabled' => true, 'link' => 'vless://u@h:1']];
    $cached = [['id' => 'c1', 'name' => 'Cached', 'enabled' => true, 'link' => 'vless://u@h:2']];
    eq(resolve_active($state, $keys, $cached), ['id' => 'k1', 'reason' => 'fallback_missing'],
        'id not found anywhere falls back to first enabled key');
}

function test_resolve_disabled_fallback(): void {
    $state = ['active_outbound' => 'k1'];
    $keys = [['id' => 'k1', 'name' => 'Key 1', 'enabled' => false, 'link' => 'vless://u@h:1']];
    $cached = [['id' => 'c1', 'name' => 'Cached', 'enabled' => true, 'link' => 'vless://u@h:2']];
    eq(resolve_active($state, $keys, $cached), ['id' => 'c1', 'reason' => 'fallback_disabled'],
        'selection found but disabled falls back to first enabled cached server');
}

function test_resolve_empty_state_default(): void {
    $state = [];
    $keys = [['id' => 'k1', 'name' => 'Key 1', 'enabled' => true, 'link' => 'vless://u@h:1']];
    $cached = [['id' => 'c1', 'name' => 'Cached', 'enabled' => true, 'link' => 'vless://u@h:2']];
    eq(resolve_active($state, $keys, $cached), ['id' => 'k1', 'reason' => 'default'], 'no prior selection defaults');
}

function test_resolve_nothing_enabled(): void {
    $state = [];
    $keys = [['id' => 'k1', 'name' => 'Key 1', 'enabled' => false, 'link' => 'vless://u@h:1']];
    $cached = [];
    eq(resolve_active($state, $keys, $cached), ['id' => null, 'reason' => 'default'], 'no enabled servers at all');
}

function test_list_groups_by_source(): void {
    $keys = [
        ['id' => 'k1', 'name' => 'Key 1', 'enabled' => true, 'link' => 'vless://u@h:1'],
    ];
    $cached = [
        ['id' => 'c1', 'name' => 'C1', 'enabled' => true, 'link' => 'vless://u@h:2', 'sub' => 'sub1'],
        ['id' => 'c2', 'name' => 'C2', 'enabled' => true, 'link' => 'vless://u@h:3', 'sub' => 'sub1'],
    ];
    $subs = [
        ['id' => 'sub1', 'name' => 'My Sub', 'url' => 'https://x', 'enabled' => true, 'updated' => '2026-01-01'],
    ];
    $result = list_servers($keys, $cached, $subs, [], [], [], null);

    eq($result['sources'], [
        ['id' => 'sub1', 'kind' => 'subscription', 'name' => 'My Sub', 'count' => 2,
            'updated' => '2026-01-01', 'error' => null, 'enabled' => true],
        ['id' => 'keys', 'kind' => 'keys', 'name' => 'Ключи', 'count' => 1,
            'updated' => null, 'error' => null, 'enabled' => true],
    ], 'one source per subscription plus a keys source, with server counts');

    $sourceIds = array_column($result['servers'], 'source', 'id');
    eq($sourceIds, ['k1' => 'keys', 'c1' => 'sub1', 'c2' => 'sub1'], 'each server tagged with its source id');

    // A subscription's last_error surfaces as the source's error.
    $subs[0]['last_error'] = 'fetch failed';
    $result2 = list_servers($keys, $cached, $subs, [], [], [], null);
    eq($result2['sources'][0]['error'], 'fetch failed', 'subscription last_error surfaces as source error');
}

function test_list_marks_active_and_favorite(): void {
    $keys = [
        ['id' => 'k1', 'name' => 'Key 1', 'enabled' => true, 'link' => 'vless://u@h:1'],
        ['id' => 'k2', 'name' => 'Key 2', 'enabled' => true, 'link' => 'vless://u@h:2'],
    ];
    $flags = ['k2' => ['favorite' => true]];
    $result = list_servers($keys, [], [], $flags, [], [], 'k1');

    $byId = array_column($result['servers'], null, 'id');
    eq($byId['k1']['active'], true, 'k1 is the active server');
    eq($byId['k2']['active'], false, 'k2 is not active');
    eq($byId['k1']['favorite'], false, 'k1 has no favorite flag');
    eq($byId['k2']['favorite'], true, 'k2 is favorited');

    // No active id at all: nothing is marked active.
    $result2 = list_servers($keys, [], [], [], [], [], null);
    eq($result2['servers'][0]['active'], false, 'null activeId marks nothing active');
}

function test_list_proto_from_link(): void {
    $keys = [
        ['id' => 'hy', 'name' => 'HY2', 'enabled' => true,
            'link' => 'hysteria2://pw@hy.example:443/?sni=hy.example'],
        ['id' => 'vl', 'name' => 'VLESS', 'enabled' => true,
            'link' => 'vless://11111111-1111-1111-1111-111111111111@v.example:443?security=reality'
                . '&sni=example.com&fp=chrome&pbk=PUBKEY&sid=abcd'],
        ['id' => 'ss', 'name' => 'SS', 'enabled' => true,
            'link' => 'ss://YWVzLTI1Ni1nY206cGFzcw==@ss.example:8443'],
    ];
    $result = list_servers($keys, [], [], [], [], [], null);
    $byId = array_column($result['servers'], null, 'id');

    eq($byId['hy']['proto'], 'hysteria2', 'hysteria2 proto from link');
    eq($byId['hy']['host'], 'hy.example', 'hysteria2 host from link');
    eq($byId['hy']['port'], 443, 'hysteria2 port from link');

    eq($byId['vl']['proto'], 'vless', 'vless proto from link');
    eq($byId['vl']['host'], 'v.example', 'vless host from link');
    eq($byId['vl']['port'], 443, 'vless port from link');

    eq($byId['ss']['proto'], 'ss', 'ss proto from link');
    eq($byId['ss']['host'], 'ss.example', 'ss host from link');
    eq($byId['ss']['port'], 8443, 'ss port from link');
}

function test_add_link_split(): void {
    $text = "https://sub.example/a\n"
        . "  vless://u@h:1#Name  \n"
        . "\n"
        . "not a link\n"
        . "hy2://a@b:1\n";
    $result = classify_lines($text);
    eq($result['subscriptions'], ['https://sub.example/a'], 'subscription url split out');
    eq($result['keys'], ['vless://u@h:1#Name', 'hy2://a@b:1'], 'keys split out, trimmed');
    eq($result['skipped'], [['line' => 'not a link', 'reason' => 'unrecognized']], 'unrecognized lines skipped with reason');
}

function test_add_link_vmess_skipped_with_reason(): void {
    // There is no vmess outbound builder (build_outbound_from_link() has no vmess
    // branch), so a vmess key must never be silently accepted into 'keys' — it would
    // sit there as a server that can never actually route traffic. It gets its own
    // skip reason instead of being lumped in with genuinely unrecognized lines.
    $vmess = 'vmess://' . base64_encode(json_encode(['add' => 'v.example', 'port' => 443]));
    $result = classify_lines("vless://u@h:1\n" . $vmess . "\n");
    eq($result['keys'], ['vless://u@h:1'], 'vmess line never reaches keys');
    eq($result['subscriptions'], [], 'vmess line never reaches subscriptions');
    eq($result['skipped'], [['line' => $vmess, 'reason' => 'vmess не поддерживается']],
        'vmess line skipped with its own reason');
}

function test_purge_cached_for_sub_drops_only_that_subscription(): void {
    $cached = [
        ['id' => 'a1', 'sub' => 'subA', 'name' => 'A1'],
        ['id' => 'b1', 'sub' => 'subB', 'name' => 'B1'],
        ['id' => 'a2', 'sub' => 'subA', 'name' => 'A2'],
        ['id' => 'x1', 'name' => 'no sub field'],
    ];
    $out = purge_cached_for_sub($cached, 'subA');
    eq(array_column($out, 'id'), ['b1', 'x1'], 'subA entries removed, others kept in order');
}

function test_purge_cached_for_sub_unknown_id_keeps_all(): void {
    $cached = [['id' => 'a1', 'sub' => 'subA'], ['id' => 'b1', 'sub' => 'subB']];
    eq(purge_cached_for_sub($cached, 'nope'), $cached, 'unknown sub id changes nothing');
    eq(purge_cached_for_sub($cached, ''), $cached, 'empty sub id changes nothing');
}

function test_purge_then_resolve_reports_fallback_for_deleted_active(): void {
    // Active server belonged to the deleted subscription: active_outbound is left as is,
    // so resolve_active reports the fallback instead of silently picking a new one.
    $cached = [
        ['id' => 'a1', 'sub' => 'subA', 'enabled' => true],
        ['id' => 'b1', 'sub' => 'subB', 'enabled' => true],
    ];
    $left = purge_cached_for_sub($cached, 'subA');
    $r = resolve_active(['active_outbound' => 'a1'], [], $left);
    eq($r['reason'], 'fallback_missing', 'deleted active → fallback_missing');
    eq($r['id'], 'b1', 'falls back to the first enabled remaining server');
}

function test_effective_enabled_respects_subscription(): void {
    $subs = [
        ['id' => 'on', 'enabled' => true],
        ['id' => 'off', 'enabled' => false],
    ];
    eq(effective_enabled(['id' => 'a', 'sub' => 'on', 'enabled' => true], $subs), true,
        'enabled server of an enabled subscription');
    eq(effective_enabled(['id' => 'b', 'sub' => 'off', 'enabled' => true], $subs), false,
        'enabled server of a disabled subscription is off');
    eq(effective_enabled(['id' => 'c', 'sub' => 'on', 'enabled' => false], $subs), false,
        'disabled server stays off');
    eq(effective_enabled(['id' => 'k', 'enabled' => true], $subs), true,
        'key (no sub field) follows its own flag');
    eq(effective_enabled(['id' => 'o', 'sub' => 'gone', 'enabled' => true], $subs), true,
        'unknown subscription id does not disable the server');
    eq(effective_enabled(['id' => 'b', 'sub' => 'off', 'enabled' => true], []), true,
        'no subscription list → own flag only (old callers)');
}

function test_resolve_disabled_subscription_fallback(): void {
    $subs = [['id' => 'subA', 'enabled' => false], ['id' => 'subB', 'enabled' => true]];
    $cached = [
        ['id' => 'a1', 'sub' => 'subA', 'enabled' => true],
        ['id' => 'b1', 'sub' => 'subB', 'enabled' => true],
    ];
    $r = resolve_active(['active_outbound' => 'a1'], [], $cached, $subs);
    eq($r['reason'], 'fallback_disabled', 'selected server of a disabled subscription → fallback');
    eq($r['id'], 'b1', 'fallback skips servers of disabled subscriptions');

    $r = resolve_active([], [], $cached, $subs);
    eq($r['id'], 'b1', 'default choice skips disabled subscriptions too');

    $r = resolve_active(['active_outbound' => 'b1'], [], $cached, $subs);
    eq([$r['id'], $r['reason']], ['b1', 'selected'], 'server of an enabled subscription stays');
}

// --- merge_refreshed_servers --------------------------------------------------

function _merge_srv(string $sub, string $name, string $host, bool $enabled = true): array {
    $link = 'vless://11111111-1111-1111-1111-111111111111@' . $host
        . ':443?security=reality&sni=example.com&fp=chrome&pbk=PUBKEY&sid=abcd';
    return ['id' => md5($link . '#' . $name), 'name' => $name, 'link' => $link,
            'enabled' => $enabled, 'sub' => $sub];
}

function test_merge_refreshed_servers_preserves_flags(): void {
    $subs = [['id' => 's1', 'url' => 'https://a', 'enabled' => true]];
    $old = [_merge_srv('s1', 'A', 'a.example', false), _merge_srv('s1', 'B', 'b.example', false),
            _merge_srv('s1', 'C', 'c.example', true)];
    $fetched = ['s1' => [
        _merge_srv('s1', 'A', 'a.example'),        // same id: flag carried by id
        _merge_srv('s1', 'B', 'b-new.example'),    // link reissued: carried by name+proto
        _merge_srv('s1', 'C', 'c.example'),
        _merge_srv('s1', 'D', 'd.example'),        // new server: enabled
    ]];
    $m = merge_refreshed_servers($old, $fetched, $subs);
    eq(array_column($m, 'name'), ['A', 'B', 'C', 'D'], 'fetched servers replace the old list');
    eq(array_column($m, 'enabled'), [false, false, true, true], 'enabled flags preserved');
    eq($m[1]['link'], $fetched['s1'][1]['link'], 'reissued server takes the new link');
    eq($m[1]['id'], $fetched['s1'][1]['id'], 'reissued server takes the new id');
}

function test_merge_refreshed_servers_failed_fetch_keeps_old(): void {
    $subs = [['id' => 's1', 'url' => 'https://a', 'enabled' => true],
             ['id' => 's2', 'url' => 'https://b', 'enabled' => true]];
    $old = [_merge_srv('s1', 'A', 'a.example', false), _merge_srv('s2', 'X', 'x.example')];
    $m = merge_refreshed_servers($old, ['s2' => [_merge_srv('s2', 'Y', 'y.example')]], $subs);
    eq(array_column($m, 'name'), ['A', 'Y'], 'failed fetch (no entry) keeps old servers');
    eq($m[0], $old[0], 'kept server unchanged');

    $m = merge_refreshed_servers($old, ['s1' => [], 's2' => []], $subs);
    eq($m, $old, 'empty fetch keeps old servers');
}

function test_merge_refreshed_servers_disabled_sub_keeps_old(): void {
    $subs = [['id' => 's1', 'url' => 'https://a', 'enabled' => false],
             ['id' => 's2', 'url' => 'https://b', 'enabled' => true]];
    $old = [_merge_srv('s1', 'A', 'a.example'), _merge_srv('s1', 'B', 'b.example', false),
            _merge_srv('s2', 'X', 'x.example'), _merge_srv('gone', 'G', 'g.example'),
            ['id' => 'legacy', 'name' => 'L', 'link' => 'ss://x', 'enabled' => true]];
    $m = merge_refreshed_servers($old, ['s2' => [_merge_srv('s2', 'X', 'x.example')]], $subs);
    eq(array_column($m, 'name'), ['A', 'B', 'X'], 'disabled sub kept; deleted sub dropped');
    eq(array_column($m, 'enabled'), [true, false, true], 'kept servers keep their flags');
}
