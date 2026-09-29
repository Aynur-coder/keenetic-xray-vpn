<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/links.php';

function test_link_kind(): void {
    eq(link_kind('https://sub.x/a'), 'subscription', 'https subscription url');
    eq(link_kind('  vless://u@h:1  '), 'key', 'padded vless key');
    eq(link_kind('hy2://a@b:1'), 'key', 'hy2 alias key');
    eq(link_kind('hello'), null, 'plain text is neither');
}

function test_hysteria2_build(): void {
    // Happy path: sni present, no pin, no obfs.
    $ob = build_outbound_from_link('hysteria2://pw@host.example:443/?sni=s.example#Name', 'srv');
    eq($ob['settings']['address'], 'host.example', 'happy path address');
    eq($ob['settings']['port'], 443, 'happy path port');
    eq($ob['streamSettings']['hysteriaSettings']['auth'], 'pw', 'happy path auth');
    eq($ob['streamSettings']['tlsSettings']['serverName'], 's.example', 'happy path sni');
    eq($ob['streamSettings']['tlsSettings']['alpn'], ['h3'], 'happy path alpn');

    // insecure=1 with no pin: Xray 26 dropped allowInsecure, so this must be skipped.
    eq(build_outbound_from_link('hy2://a@h:443?insecure=1', 'srv'), null, 'insecure-only is unsupported');

    // pinSHA256 present: lowercased, colons stripped.
    $pinned = build_outbound_from_link('hy2://a@h:443?pinSHA256=AB:CD', 'srv');
    eq($pinned['streamSettings']['tlsSettings']['pinnedPeerCertSha256'], 'abcd', 'pin is normalized');

    // Salamander obfs needs config Xray doesn't get from the link — skip rather than emit a dead server.
    eq(build_outbound_from_link('hy2://a@h:443?obfs=salamander', 'srv'), null, 'salamander obfs is unsupported');

    // Port-hopping lists dial the first port.
    $hopping = parse_hysteria2_link('hysteria2://a@h:443,20000-30000/');
    eq($hopping['port'], 443, 'port-hopping list uses first port');

    // Bracketed IPv6 host loses its brackets.
    $ipv6 = parse_hysteria2_link('hysteria2://pw@[2001:db8::1]:443/');
    eq($ipv6['address'], '2001:db8::1', 'bracketed ipv6 host');
}

function test_vless_reality_build(): void {
    $link = 'vless://11111111-1111-1111-1111-111111111111@host.example:443'
        . '?security=reality&sni=example.com&fp=chrome&pbk=PUBKEY123&sid=abcd1234&type=tcp#Reality';
    $ob = build_outbound_from_link($link, 'srv');
    eq($ob['streamSettings']['realitySettings']['publicKey'], 'PUBKEY123', 'reality publicKey');
    eq($ob['streamSettings']['realitySettings']['shortId'], 'abcd1234', 'reality shortId');
    eq($ob['streamSettings']['realitySettings']['serverName'], 'example.com', 'reality serverName');
}
