<?php
// Parsing and classification for subscription/key links (vless://, ss://,
// hysteria2:// / hy2://) and building Xray outbounds from them.
//
// Pure logic only: no globals, no header()/session/file I/O beyond what's
// passed in, so this file can be require_once'd standalone from tests.

// 'subscription' for http(s):// links, 'key' for a single-server link
// (vless/ss/trojan/hysteria2/hy2), null for anything else.
function link_kind(string $line): ?string {
    $line = trim($line);
    if (preg_match('#^https?://#i', $line)) return 'subscription';
    if (preg_match('#^(vless|ss|trojan|hysteria2|hy2)://#i', $line)) return 'key';
    return null;
}

function parse_vless_link($link) {
    $name = 'VLESS';
    if (preg_match('/#(.+)$/', $link, $nm)) {
        $name = urldecode($nm[1]);
        $link = preg_replace('/#.*$/', '', $link);
    }
    $link = urldecode($link);
    if (!preg_match('/^vless:\/\/([^@]+)@([^:]+):(\d+)\??(.*)$/', $link, $m)) return null;
    $params = [];
    parse_str($m[4] ?? '', $params);
    return [
        'uuid' => $m[1], 'address' => $m[2], 'port' => (int)$m[3],
        'security' => $params['security'] ?? 'none',
        'type' => $params['type'] ?? 'tcp',
        'sni' => $params['sni'] ?? '',
        'fp' => $params['fp'] ?? 'chrome',
        'pbk' => $params['pbk'] ?? '',
        'sid' => $params['sid'] ?? '',
        'flow' => $params['flow'] ?? '',
        'host' => $params['host'] ?? '',
        'path' => $params['path'] ?? '',
        'mode' => $params['mode'] ?? '',
        'name' => $name
    ];
}

// hysteria2://auth@host:port/?sni=...&insecure=1&obfs=salamander&obfs-password=...#name
// (hy2:// is the short alias). auth may itself be "user:pass" — keep it verbatim.
function parse_hysteria2_link($link) {
    $link = preg_replace('/#.*$/', '', $link);
    if (!preg_match('/^(?:hysteria2|hy2):\/\/(?:([^@]*)@)?([^\/?]+?)(?::([0-9,\-]+))?\/?(?:\?(.*))?$/',
            $link, $m)) return null;
    $params = [];
    parse_str($m[4] ?? '', $params);
    $host = trim($m[2], '[]');
    // Port hopping lists ("443,20000-30000") are not supported yet: dial the first port
    $port = (int)(preg_split('/[,\-]/', $m[3] ?? '')[0] ?: 443);
    if ($host === '' || $port <= 0) return null;
    return [
        'auth' => urldecode($m[1] ?? ''), 'address' => $host, 'port' => $port,
        'sni' => $params['sni'] ?? '',
        'insecure' => ($params['insecure'] ?? '') === '1',
        // Hysteria prints the fingerprint as hex, optionally colon-separated
        'pin' => strtolower(str_replace(':', '', $params['pinSHA256'] ?? '')),
        'obfs' => $params['obfs'] ?? '',
    ];
}

function parse_ss_link($link) {
    $link = preg_replace('/#.*$/', '', $link);
    $link = preg_replace('/\?.*@/', '@', $link);
    if (preg_match('/^ss:\/\/([^@]+)@(.+):(\d+)/', $link, $m)) {
        $decoded = base64_decode($m[1]);
        if (!$decoded && strpos($m[1], '%') !== false) $decoded = base64_decode(urldecode($m[1]));
        if ($decoded && preg_match('/^([^:]+):(.+)$/', $decoded, $dm)) {
            return ['address' => $m[2], 'port' => (int)$m[3], 'method' => $dm[1], 'password' => $dm[2]];
        }
    }
    return null;
}

function build_outbound_from_link($link, $tag) {
    if (strpos($link, 'vless://') === 0) {
        $v = parse_vless_link($link);
        if (!$v) return null;
        $out = [
            'tag' => $tag, 'protocol' => 'vless',
            'settings' => ['vnext' => [['address' => $v['address'], 'port' => $v['port'],
                'users' => [['id' => $v['uuid'], 'encryption' => 'none', 'flow' => $v['flow'] ?: '']]
            ]]],
            'streamSettings' => ['network' => $v['type'] ?: 'tcp', 'security' => $v['security'] ?: 'none']
        ];
        if ($v['security'] === 'reality') {
            $out['streamSettings']['realitySettings'] = [
                'serverName' => $v['sni'], 'fingerprint' => $v['fp'] ?: 'chrome',
                'publicKey' => $v['pbk'], 'shortId' => $v['sid'] ?: '', 'spiderX' => ''
            ];
        } elseif ($v['security'] === 'tls') {
            $out['streamSettings']['tlsSettings'] = [
                'serverName' => $v['sni'], 'fingerprint' => $v['fp'] ?: 'chrome'
            ];
        }
        if ($v['type'] === 'xhttp') {
            $out['streamSettings']['xhttpSettings'] = [];
            // Xray expects xhttpSettings.host as a plain string, not an array
            if (!empty($v['host'])) $out['streamSettings']['xhttpSettings']['host'] = $v['host'];
            if (!empty($v['path'])) $out['streamSettings']['xhttpSettings']['path'] = $v['path'];
            if (!empty($v['mode'])) $out['streamSettings']['xhttpSettings']['mode'] = $v['mode'];
        }
        return $out;
    }
    if (strpos($link, 'ss://') === 0) {
        $s = parse_ss_link($link);
        if (!$s) return null;
        return ['tag' => $tag, 'protocol' => 'shadowsocks', 'settings' => ['servers' => [$s]]];
    }
    if (is_hysteria2_link($link)) {
        $h = parse_hysteria2_link($link);
        // Salamander obfs needs Xray's finalmask config; skip rather than emit a server
        // that silently never connects.
        if (!$h || $h['obfs'] !== '') return null;
        $tls = ['serverName' => $h['sni'] ?: $h['address'], 'alpn' => ['h3']];
        if ($h['pin'] !== '') {
            $tls['pinnedPeerCertSha256'] = $h['pin'];
        } elseif ($h['insecure']) {
            // Xray 26 removed allowInsecure (the whole config then fails to load), and an
            // unverified certificate is open to MITM anyway — skip instead of guessing.
            return null;
        }
        // Xray splits Hysteria across two blocks: server in settings, auth in the transport
        return [
            'tag' => $tag, 'protocol' => 'hysteria',
            'settings' => ['version' => 2, 'address' => $h['address'], 'port' => $h['port']],
            'streamSettings' => [
                'network' => 'hysteria', 'security' => 'tls', 'tlsSettings' => $tls,
                'hysteriaSettings' => ['version' => 2, 'auth' => $h['auth']],
            ],
        ];
    }
    return null;
}

function is_hysteria2_link($link) {
    return strpos($link, 'hysteria2://') === 0 || strpos($link, 'hy2://') === 0;
}

// Normalizes a link's scheme to the small set of proto names the UI shows
// ('vless','ss','trojan','vmess','hysteria2'); hy2 is hysteria2's alias.
// '' for anything without a recognizable scheme.
function link_proto(string $link): string {
    if (preg_match('#^([a-z0-9]+)://#i', $link, $m)) {
        $scheme = strtolower($m[1]);
        return $scheme === 'hy2' ? 'hysteria2' : $scheme;
    }
    return '';
}

// Host/port for display, for any supported key link. Independent of
// build_outbound_from_link() (which may reject a link this still describes,
// e.g. salamander-obfuscated hysteria2) and of server_hint() (which only
// covers vless/ss/hysteria2 and names shadowsocks 'shadowsocks', not 'ss').
// ['host' => '', 'port' => 0] when the link can't be parsed.
function link_host_port(string $link): array {
    if (strpos($link, 'vless://') === 0) {
        $v = parse_vless_link($link);
        if ($v) return ['host' => $v['address'], 'port' => $v['port']];
    } elseif (strpos($link, 'ss://') === 0) {
        $s = parse_ss_link($link);
        if ($s) return ['host' => $s['address'], 'port' => $s['port']];
    } elseif (is_hysteria2_link($link)) {
        $h = parse_hysteria2_link($link);
        if ($h) return ['host' => $h['address'], 'port' => $h['port']];
    } elseif (strpos($link, 'trojan://') === 0) {
        $u = parse_url(preg_replace('/#.*$/', '', $link));
        if (is_array($u) && !empty($u['host'])) {
            return ['host' => $u['host'], 'port' => (int)($u['port'] ?? 443)];
        }
    } elseif (strpos($link, 'vmess://') === 0) {
        $payload = substr(preg_replace('/#.*$/', '', $link), strlen('vmess://'));
        $decoded = base64_decode($payload, true);
        $j = $decoded !== false ? json_decode($decoded, true) : null;
        if (is_array($j) && !empty($j['add'])) {
            return ['host' => (string)$j['add'], 'port' => (int)($j['port'] ?? 0)];
        }
    }
    return ['host' => '', 'port' => 0];
}

// Server address of a built outbound, whatever the protocol's layout
function outbound_address($ob) {
    return $ob['settings']['servers'][0]['address'] ?? $ob['settings']['vnext'][0]['address']
        ?? $ob['settings']['address'] ?? '';
}
