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
