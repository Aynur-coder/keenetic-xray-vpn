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
