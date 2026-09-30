<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/connections.php';

// ---- fixtures -----------------------------------------------------------------

// Real querylog.json line captured from the router: a blocked query whose Answer
// is a single 0.0.0.0 A record addressed via a compression pointer (c00c).
const CONN_QL_BLOCKED = '{"T":"2026-09-30T03:08:18.353877865+03:00","QH":"t2.zcvh.net","QT":"A","QC":"IN","CP":"","Answer":"ZZGBgAABAAEAAAAAAnQyBHpjdmgDbmV0AAABAAHADAABAAEAAAAKAAQAAAAA","IP":"192.168.1.126","Result":{"Rules":[{"Text":"||t2.zcvh.net^","IP":"","FilterListID":1784393224}],"Reason":3,"IsFiltered":true},"Elapsed":1531320}';

const CONN_CT_REDIRECTED = 'tcp      6 2 TIME_WAIT src=192.168.1.126 dst=17.248.236.26 sport=53025 dport=443 packets=25 bytes=38069 src=192.168.1.1 dst=192.168.1.126 sport=1080 dport=53025 packets=23 bytes=7951 [ASSURED] mark=0 use=1';
const CONN_CT_UDP_FASTNAT = 'udp      17 179 src=192.168.1.126 dst=142.251.152.2 sport=64201 dport=443 packets=97 bytes=27079 src=142.251.152.2 dst=95.105.78.232 sport=443 dport=64201 packets=81 bytes=33186 [ASSURED] [FASTNAT] mark=0 nmark=0 sc=0 ifw=36 ifl=38 mac=ec:2c:0d:34:18:c5 slan attrs= use=3';
const CONN_CT_LEAK = 'tcp      6 431999 ESTABLISHED src=192.168.1.50 dst=104.18.32.47 sport=50111 dport=443 packets=10 bytes=2000 src=104.18.32.47 dst=95.105.78.232 sport=443 dport=50111 packets=12 bytes=9000 [ASSURED] mark=0 use=1';

const CONN_ACCESS_LOG = "2026/09/30 00:50:15.250880 from 192.168.1.126:53219 accepted tcp:17.250.85.79:443 [tproxy-in -> sub-1f1c7155b9f316940d161d41c03f797b]\n"
    . "2026/09/30 00:50:16.000001 from 192.168.1.126:53025 accepted tcp:gemini.google.com:443 [tproxy-in -> key-abc]\n"
    . "2026/09/30 00:50:17.000001 from 192.168.1.77:40000 accepted udp:8.8.8.8:53 [fullvpn-in >> key-xyz]\n"
    . "2026/09/30 00:50:18.000001 from 127.0.0.1:41000 accepted tcp:example.org:80 [socks-in -> direct]\n"
    . "garbage line without the expected shape\n";

// Builds one DNS response: question www.example.com A, answers
// CNAME www.example.com -> example.com (pointer into the question), then
// A and AAAA for example.com addressed via a pointer into the CNAME rdata,
// plus a TXT record that must be ignored.
function _conn_dns_wire(): string {
    $qname = "\x03www\x07example\x03com\x00";          // offset 12
    $msg = pack('nnnnnn', 0x1234, 0x8180, 1, 4, 0, 0);
    $msg .= $qname . pack('nn', 1, 1);
    // CNAME: name = ptr to 12; rdata = "example" label + ptr to "com" (offset 12+4+8=24)
    $cnameRdataOffset = strlen($msg) + 2 + 10;
    $cnameRdata = "\x07example" . pack('n', 0xC000 | 24);
    $msg .= pack('n', 0xC00C) . pack('nnNn', 5, 1, 300, strlen($cnameRdata)) . $cnameRdata;
    $ptr = pack('n', 0xC000 | $cnameRdataOffset);
    $msg .= $ptr . pack('nnNn', 1, 1, 300, 4) . inet_pton('93.184.216.34');
    $msg .= $ptr . pack('nnNn', 28, 1, 300, 16) . inet_pton('2606:2800:220:1:248:1893:25c8:1946');
    $msg .= $ptr . pack('nnNn', 16, 1, 300, 4) . "\x03abc";
    return $msg;
}

function _conn_ql_line(string $host, array $ips, string $t = '2026-09-30T03:00:00+03:00'): string {
    $msg = pack('nnnnnn', 1, 0x8180, 0, count($ips), 0, 0);
    foreach ($ips as $ip) {
        $bin = inet_pton($ip);
        $type = strlen($bin) === 4 ? 1 : 28;
        $msg .= "\x00" . pack('nnNn', $type, 1, 60, strlen($bin)) . $bin;
    }
    return json_encode(['T' => $t, 'QH' => $host, 'QT' => 'A', 'Answer' => base64_encode($msg)]);
}

// ---- dns_wire_answers() -------------------------------------------------------

function test_dns_wire_answers_a_aaaa_cname_with_compression(): void {
    eq(
        dns_wire_answers(base64_encode(_conn_dns_wire())),
        ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
        'A + AAAA extracted past a compressed CNAME, TXT ignored'
    );
}

function test_dns_wire_answers_real_blocked_answer(): void {
    $row = json_decode(CONN_QL_BLOCKED, true);
    eq(dns_wire_answers($row['Answer']), ['0.0.0.0'], 'real router answer decodes to its 0.0.0.0 A record');
}

function test_dns_wire_answers_malformed(): void {
    eq(dns_wire_answers(''), [], 'empty');
    eq(dns_wire_answers('!!!not base64'), [], 'not base64');
    eq(dns_wire_answers(base64_encode("\x00\x01\x02")), [], 'shorter than a header');
    $truncated = substr(_conn_dns_wire(), 0, 60);
    eq(dns_wire_answers(base64_encode($truncated)), [], 'truncated message stops before the cut, no warnings');
    // A self-referencing pointer must not loop forever.
    $loop = pack('nnnnnn', 1, 0x8180, 1, 0, 0, 0) . pack('n', 0xC00C) . pack('nn', 1, 1);
    eq(dns_wire_answers(base64_encode($loop)), [], 'pointer loop in the question name is survived');
}

// ---- querylog_ip_map() --------------------------------------------------------

function test_querylog_ip_map_newest_wins(): void {
    $jsonl = _conn_ql_line('old.example', ['1.2.3.4', '5.6.7.8']) . "\n"
        . _conn_ql_line('Chat.OpenAI.com.', ['1.2.3.4']) . "\n"
        . CONN_QL_BLOCKED . "\n"
        . _conn_ql_line('v6.example', ['2001:db8::1']) . "\n";
    $map = querylog_ip_map(explode("\n", $jsonl));
    eq($map['1.2.3.4'] ?? null, 'chat.openai.com', 'later line wins; host lowercased, trailing dot dropped');
    eq($map['5.6.7.8'] ?? null, 'old.example', 'older mapping kept where not overridden');
    eq($map['2001:db8::1'] ?? null, 'v6.example', 'AAAA answers mapped too');
    eq(isset($map['0.0.0.0']), false, 'blocked 0.0.0.0 answers are not mapped');
}

function test_querylog_ip_map_skips_truncated_first_line(): void {
    $full = _conn_ql_line('a.example', ['9.9.9.9']);
    $jsonl = substr(_conn_ql_line('cut.example', ['7.7.7.7']), 20) . "\n" . $full . "\n{\"T\":";
    $map = querylog_ip_map(explode("\n", $jsonl));
    eq($map, ['9.9.9.9' => 'a.example'], 'broken head/tail lines ignored, whole lines parsed');
}

function test_file_tail_lines_drops_partial_first_line(): void {
    $f = tempnam(sys_get_temp_dir(), 'conn_tail_');
    $lines = [];
    for ($i = 0; $i < 50; $i++) $lines[] = _conn_ql_line("h$i.example", ["10.0.0.$i"]);
    file_put_contents($f, implode("\n", $lines) . "\n");
    $tail = iterator_to_array(file_tail_lines($f, 500), false);
    unlink($f);
    eq(strlen(implode("\n", $tail)) <= 500, true, 'never returns more than asked');
    eq(strpos($tail[0], '{') === 0, true, 'tail starts on a whole line');
    $map = querylog_ip_map($tail);
    eq($map['10.0.0.49'] ?? null, 'h49.example', 'newest line present');
    eq(isset($map['10.0.0.0']), false, 'oldest line not read');

    $small = tempnam(sys_get_temp_dir(), 'conn_tail_');
    file_put_contents($small, "one\ntwo\r\nthree");
    eq(iterator_to_array(file_tail_lines($small, 1000), false), ['one', 'two', 'three'],
        'file smaller than the window is read whole, first line kept, line ends stripped');
    unlink($small);
    eq(iterator_to_array(file_tail_lines('/nonexistent/conn/file', 100), false), [],
        'missing file yields no lines');
}

function test_querylog_ip_map_bounded_newest_win(): void {
    $lines = [];
    for ($i = 0; $i < 30; $i++) $lines[] = _conn_ql_line("h$i.example", ["10.0.1.$i"]);
    $lines[] = _conn_ql_line('again.example', ['10.0.1.0']);
    $map = querylog_ip_map($lines, null, 10);
    eq(count($map), 10, 'map capped at $max IPs');
    eq($map['10.0.1.0'] ?? null, 'again.example', 'a re-answered IP counts as newest');
    eq($map['10.0.1.29'] ?? null, 'h29.example', 'newest answers kept');
    eq(isset($map['10.0.1.19']), false, 'oldest answers dropped');
}

function test_querylog_ip_map_only_wanted_ips(): void {
    $lines = [_conn_ql_line('a.example', ['1.1.1.1', '2.2.2.2']), _conn_ql_line('b.example', ['3.3.3.3'])];
    eq(querylog_ip_map($lines, ['2.2.2.2' => true]), ['2.2.2.2' => 'a.example'], 'only looked-up IPs kept');
}

function test_xray_access_entries_only_wanted_keys(): void {
    $e = xray_access_entries(explode("\n", CONN_ACCESS_LOG), ['192.168.1.77:40000' => true]);
    eq(array_keys($e), ['192.168.1.77:40000'], 'only looked-up sources kept');
}

// ---- parse_conntrack() --------------------------------------------------------

function test_parse_conntrack_real_lines(): void {
    $text = CONN_CT_REDIRECTED . "\n" . CONN_CT_UDP_FASTNAT . "\n"
        . 'tcp      6 100 ESTABLISHED src=95.105.78.232 dst=1.1.1.1 sport=1 dport=2 src=1.1.1.1 dst=95.105.78.232 sport=2 dport=1 mark=0 use=1' . "\n"
        . 'udp      17 20 src=10.8.0.2 dst=8.8.8.8 sport=5353 dport=53 src=8.8.8.8 dst=95.105.78.232 sport=53 dport=5353 mark=0 use=1' . "\n"
        . 'tcp      6 5 ESTABLISHED src=172.20.0.5 dst=1.0.0.1 sport=1 dport=443 packets=1 bytes=60 src=1.0.0.1 dst=95.105.78.232 sport=443 dport=1 mark=0 use=1' . "\n"
        . 'tcp      6 5 ESTABLISHED src=172.32.0.5 dst=1.0.0.1 sport=1 dport=443 src=1.0.0.1 dst=95.105.78.232 sport=443 dport=1 mark=0 use=1' . "\n"
        . "conntrack v1.4.6 (conntrack-tools): 6 flow entries have been shown.\n";
    $rows = parse_conntrack($text);
    eq(count($rows), 4, 'only LAN sources (192.168/10/172.16-31) kept');
    eq($rows[0], [
        'proto' => 'tcp', 'src' => '192.168.1.126', 'dst' => '17.248.236.26',
        'sport' => 53025, 'dport' => 443, 'reply_src' => '192.168.1.1', 'reply_sport' => 1080,
        'state' => 'TIME_WAIT', 'packets' => 48, 'bytes' => 46020,
    ], 'redirected tcp flow parsed, packets/bytes summed over both directions');
    eq($rows[1]['proto'], 'udp', 'udp proto');
    eq($rows[1]['state'], null, 'udp has no state');
    eq($rows[1]['reply_src'], '142.251.152.2', 'udp reply src');
    eq($rows[1]['reply_sport'], 443, 'udp reply sport');
    eq($rows[1]['bytes'], 60265, 'udp bytes summed');
    eq($rows[2]['src'], '10.8.0.2', '10.* is LAN');
    eq($rows[2]['packets'], null, 'missing packets -> null');
    eq($rows[2]['bytes'], null, 'missing bytes -> null');
    eq($rows[3]['src'], '172.20.0.5', '172.16/12 is LAN, 172.32 is not');
}

// ---- xray_access_outbounds() / xray_access_domains() ----------------------------

function test_xray_access_outbounds(): void {
    $map = xray_access_outbounds(xray_access_entries(explode("\n", CONN_ACCESS_LOG)));
    eq($map, [
        '192.168.1.126:53219' => 'sub-1f1c7155b9f316940d161d41c03f797b',
        '192.168.1.126:53025' => 'key-abc',
        '192.168.1.77:40000'  => 'key-xyz',
        '127.0.0.1:41000'     => 'direct',
    ], '-> and >> arrows, tcp/udp, IP and domain destinations');
    $later = xray_access_outbounds(xray_access_entries(explode("\n", CONN_ACCESS_LOG
        . "2026/09/30 01:00:00.000000 from 192.168.1.126:53025 accepted tcp:1.1.1.1:443 [tproxy-in -> direct]\n")));
    eq($later['192.168.1.126:53025'], 'direct', 'a later line for the same source wins');
}

function test_xray_access_domains(): void {
    eq(xray_access_domains(xray_access_entries(explode("\n", CONN_ACCESS_LOG))), [
        '192.168.1.126:53025' => 'gemini.google.com',
        '127.0.0.1:41000'     => 'example.org',
    ], 'only sniffed/domain destinations are reported');
}

// ---- build_connections() --------------------------------------------------------

function test_build_connections_route_classification(): void {
    $flows = parse_conntrack(implode("\n", [
        CONN_CT_REDIRECTED,
        CONN_CT_UDP_FASTNAT,
        CONN_CT_LEAK,
        'udp      17 20 src=192.168.1.50 dst=9.9.9.9 sport=4000 dport=443 src=9.9.9.9 dst=95.105.78.232 sport=443 dport=4000 mark=0 use=1',
        'tcp      6 50 ESTABLISHED src=192.168.1.50 dst=192.168.1.1 sport=5000 dport=80 src=192.168.1.1 dst=192.168.1.50 sport=80 dport=5000 mark=0 use=1',
        'tcp      6 50 ESTABLISHED src=192.168.1.51 dst=77.88.8.8 sport=5001 dport=443 src=192.168.1.1 dst=192.168.1.51 sport=1083 dport=5001 mark=0 use=1',
    ]));
    $ipDomain = ['17.248.236.26' => 'icloud.com', '104.18.32.47' => 'chatgpt.com', '142.251.152.2' => 'youtube.com'];
    $access = ['192.168.1.126:53025' => 'sub-abc', '192.168.1.51:5001' => 'key-zzz'];
    $vpnSet = ['104.18.32.47' => true, '9.9.9.9' => true];
    $tested = [];
    $inVpn = function (string $ip) use ($vpnSet, &$tested): bool {
        $tested[] = $ip;
        return isset($vpnSet[$ip]);
    };
    $rows = build_connections($flows, $ipDomain, $access, $inVpn, ['sub-abc' => 'Финляндия']);

    eq(count($rows), 5, 'flow to the router itself is dropped');
    eq($rows[0], [
        'device_ip' => '192.168.1.126', 'domain' => 'icloud.com', 'dst' => '17.248.236.26',
        'dport' => 443, 'proto' => 'tcp', 'route' => 'vpn', 'server' => 'Финляндия', 'bytes' => 46020,
    ], 'redirected to :1080 -> vpn, server named from the access.log outbound');
    eq($rows[1]['route'], 'direct', 'udp/443 not in vpn set -> direct');
    eq($rows[1]['domain'], 'youtube.com', 'domain from querylog');
    eq($rows[1]['server'], null, 'direct has no server');
    eq($rows[2]['route'], 'leak', 'tcp to a vpn-set IP that went direct -> leak');
    eq($rows[3]['route'], 'leak', 'udp/443 to a vpn-set IP visible in conntrack got past the QUIC guard -> leak');
    eq($rows[3]['domain'], null, 'unknown domain -> null');
    eq($rows[4]['route'], 'vpn', 'full-VPN redirect on :1083 -> vpn');
    eq($rows[4]['server'], 'key-zzz', 'unnamed outbound tag reported as-is');
    eq(in_array('17.248.236.26', $tested, true), false, 'redirected flows never hit the ipset callable');
}

function test_build_connections_access_domain_fallback(): void {
    $flows = parse_conntrack(CONN_CT_REDIRECTED);
    $rows = build_connections($flows, [], ['192.168.1.126:53025' => 'key-abc'], fn(string $ip): bool => false,
        [], ['192.168.1.126:53025' => 'gemini.google.com']);
    eq($rows[0]['domain'], 'gemini.google.com', 'sniffed domain from access.log fills a querylog gap');
    eq($rows[0]['server'], 'key-abc', 'unknown tag falls back to the tag');
    $rows = build_connections($flows, [], [], fn(string $ip): bool => false, []);
    eq($rows[0]['server'], null, 'vpn flow without an access.log entry has no server');
}

// ---- ipset batch helpers ------------------------------------------------------

function test_ipset_batch_script_and_hits(): void {
    $script = ipset_batch_script(['1.2.3.4', 'bad;rm -rf /', '2001:db8::1', '1.2.3.4']);
    eq($script, "ipset test vpn1 '1.2.3.4' >/dev/null 2>&1 && echo '1.2.3.4'; "
        . "ipset test vpn6 '2001:db8::1' >/dev/null 2>&1 && echo '2001:db8::1'; ",
        'one test per unique valid IP, v4->vpn1, v6->vpn6, junk dropped');
    eq(ipset_batch_script([]), '', 'no IPs -> empty script');
    eq(ipset_batch_hits("1.2.3.4\n\n2001:db8::1\nnoise\n"), ['1.2.3.4' => true, '2001:db8::1' => true], 'hits parsed');
}

function test_connection_query_limit(): void {
    eq(connections_limit(null), 300, 'default');
    eq(connections_limit('50'), 50, 'explicit');
    eq(connections_limit('9999'), 500, 'capped');
    eq(connections_limit('0'), 300, 'non-positive -> default');
    eq(connections_limit('abc'), 300, 'garbage -> default');
}

function test_connections_server_names(): void {
    $names = connections_server_names(
        [['id' => 'k1', 'name' => 'Мой ключ'], ['name' => 'no id']],
        [['id' => 's1', 'name' => 'Финляндия'], ['id' => 's2']]
    );
    eq($names['key-k1'], 'Мой ключ', 'keys.json -> key-<id>');
    eq($names['sub-s1'], 'Финляндия', 'cached_servers.json -> sub-<id>');
    eq($names['sub-s2'], 's2', 'nameless server falls back to its id');
    eq($names['direct'], 'Напрямую', 'direct outbound labelled');
    eq(count($names), 5, 'entries without an id skipped');
}

function test_connections_device_names(): void {
    eq(connections_device_names([
        ['ip' => '192.168.1.126', 'mac' => 'AA', 'hostname' => 'iPhone'],
        ['ip' => '192.168.1.5', 'hostname' => ''],
        'junk',
    ]), ['192.168.1.126' => 'iPhone'], 'named hosts only');
}

function test_redirected_direct_outbound_is_direct(): void {
    $flows = parse_conntrack(CONN_CT_REDIRECTED);
    $called = false;
    $inVpn = function (string $ip) use (&$called): bool { $called = true; return true; };
    $rows = build_connections($flows, [], ['192.168.1.126:53025' => 'direct'], $inVpn,
        ['direct' => 'Напрямую']);
    eq($rows[0]['route'], 'direct', 'redirected into Xray but sent out by the direct outbound');
    eq($rows[0]['server'], null, 'direct route has no server');
    eq($called, false, 'not a leak candidate: Xray chose direct on purpose');
}

function test_udp_quic_to_vpn_set_is_leak(): void {
    $rows = build_connections(parse_conntrack(CONN_CT_UDP_FASTNAT), [], [],
        fn(string $ip): bool => $ip === '142.251.152.2', []);
    eq($rows[0]['route'], 'leak', 'live FASTNAT QUIC flow to a vpn-set IP is a leak');
    eq($rows[0]['server'], null, 'leak has no server');
}

function test_redirected_block_outbound_is_blocked(): void {
    $rows = build_connections(parse_conntrack(CONN_CT_REDIRECTED), [],
        ['192.168.1.126:53025' => 'block'], fn(string $ip): bool => false,
        ['block' => 'Заблокировано']);
    eq($rows[0]['route'], 'blocked', 'redirected into Xray and sent to the block outbound');
    eq($rows[0]['server'], null, 'blocked has no server');
}

function test_build_connections_routes_are_closed_set(): void {
    $text = implode("\n", [CONN_CT_REDIRECTED, CONN_CT_UDP_FASTNAT, CONN_CT_LEAK]);
    $rows = build_connections(parse_conntrack($text), [], [], fn(string $ip): bool => true, []);
    foreach ($rows as $r) {
        eq(in_array($r['route'], ['vpn', 'direct', 'leak', 'blocked'], true), true, 'route ' . $r['route']);
    }
}

// ---- connections_collect(): deadline + partial flag via an injected clock ------------

// I/O fakes that advance a fake clock by the given seconds per call and record calls.
function _conn_io(array &$clock, array &$calls, array $cost, string $conntrack): array {
    $step = function (string $name) use (&$clock, &$calls, $cost): void {
        $calls[] = $name;
        $clock['t'] += $cost[$name] ?? 0.0;
    };
    return [
        'conntrack' => function (float $timeout) use ($step, $conntrack): ?iterable {
            $step('conntrack');
            return explode("\n", $conntrack);
        },
        'ipset' => function (string $script, float $timeout) use ($step): ?string {
            $step('ipset');
            return "104.18.32.47\n";
        },
        'access_log' => function () use ($step): iterable {
            $step('access_log');
            yield "2026/09/30 00:50:16.000001 from 192.168.1.126:53025 accepted tcp:gemini.google.com:443 [tproxy-in -> key-abc]\n";
        },
        'querylog' => function () use ($step): iterable {
            $step('querylog');
            yield _conn_ql_line('chatgpt.com', ['104.18.32.47']) . "\n";
        },
        'server_names' => ['key-abc' => 'Финляндия'],
        'device_names' => ['192.168.1.50' => 'MacBook'],
    ];
}

function test_connections_collect_fast_path_not_partial(): void {
    $clock = ['t' => 100.0];
    $calls = [];
    $io = _conn_io($clock, $calls, ['conntrack' => 0.2, 'ipset' => 0.1],
        CONN_CT_REDIRECTED . "\n" . CONN_CT_LEAK . "\n");
    $r = connections_collect(300, $io, function () use (&$clock): float { return $clock['t']; });
    eq($r['partial'], false, 'within budget -> not partial');
    eq($r['total'], 2, 'total');
    eq($calls, ['conntrack', 'ipset', 'access_log', 'querylog'], 'every step ran once');
    eq($r['connections'][0]['server'], 'Финляндия', 'server from access.log + names');
    eq($r['connections'][0]['domain'], 'gemini.google.com', 'access.log domain fallback');
    eq($r['connections'][1]['route'], 'leak', 'ipset hit -> leak');
    eq($r['connections'][1]['domain'], 'chatgpt.com', 'querylog domain');
    eq($r['connections'][1]['device_name'], 'MacBook', 'device name attached');
    eq($r['connections'][0]['device_name'], null, 'unknown device -> null');
}

function test_connections_collect_slow_conntrack_is_partial(): void {
    $clock = ['t' => 0.0];
    $calls = [];
    $io = _conn_io($clock, $calls, ['conntrack' => 2.7], CONN_CT_REDIRECTED . "\n" . CONN_CT_LEAK . "\n");
    $r = connections_collect(300, $io, function () use (&$clock): float { return $clock['t']; });
    eq($r['partial'], true, 'conntrack hit its timeout -> partial');
    eq($calls, ['conntrack'], 'no time left: ipset and log tails skipped');
    eq(count($r['connections']), 2, 'flows still returned');
    eq($r['connections'][1]['route'], 'direct', 'untested IP is reported direct');
}

function test_connections_collect_slow_ipset_skips_querylog(): void {
    $clock = ['t' => 0.0];
    $calls = [];
    $io = _conn_io($clock, $calls, ['conntrack' => 0.3, 'ipset' => 1.2, 'access_log' => 0.5],
        CONN_CT_LEAK . "\n");
    $r = connections_collect(300, $io, function () use (&$clock): float { return $clock['t']; });
    eq($r['partial'], true, 'ipset ran into its timeout -> partial');
    eq(in_array('querylog', $calls, true), false, 'querylog tail skipped once the budget is gone');
}

function test_connections_collect_conntrack_errors(): void {
    $clock = ['t' => 0.0];
    $calls = [];
    $now = function () use (&$clock): float { return $clock['t']; };
    $io = _conn_io($clock, $calls, [], 'sh: conntrack: not found');
    eq(connections_collect(300, $io, $now)['error'] ?? null,
        'Не удалось прочитать таблицу соединений (conntrack)', 'missing binary -> error');
    $io = _conn_io($clock, $calls, [], 'conntrack v1.4.6 (conntrack-tools): 0 flow entries have been shown.');
    $r = connections_collect(300, $io, $now);
    eq([$r['connections'], $r['total'], $r['partial']], [[], 0, false], 'empty table -> empty list');
}

function test_connections_collect_limit(): void {
    $clock = ['t' => 0.0];
    $calls = [];
    $io = _conn_io($clock, $calls, [], CONN_CT_REDIRECTED . "\n" . CONN_CT_LEAK . "\n" . CONN_CT_UDP_FASTNAT);
    $r = connections_collect(2, $io, function () use (&$clock): float { return $clock['t']; });
    eq([count($r['connections']), $r['total'], $r['limit']], [2, 3, 2], 'limit applied, total counts all');
}

function test_connections_collect_conntrack_line_cap_is_partial(): void {
    $clock = ['t' => 0.0];
    $calls = [];
    $io = _conn_io($clock, $calls, [], '');
    $io['conntrack'] = function (float $timeout): iterable {
        for ($i = 0; $i < CONNECTIONS_CONNTRACK_MAX_LINES + 5; $i++) yield CONN_CT_LEAK;
    };
    $r = connections_collect(3, $io, function () use (&$clock): float { return $clock['t']; });
    eq([$r['partial'], $r['total'], count($r['connections'])],
        [true, CONNECTIONS_CONNTRACK_MAX_LINES, 3], 'reading stops at the line cap, flagged partial');
}

// ---- lan_devices cache policy ------------------------------------------------

function test_lan_devices_cache_should_write(): void {
    $named = [['ip' => '192.168.1.5', 'hostname' => 'iPhone']];
    $nameless = [['ip' => '192.168.1.5', 'hostname' => '']];
    eq(lan_devices_cache_should_write([], $named), false, 'empty result never overwrites');
    eq(lan_devices_cache_should_write($named, $named), true, 'named result always written');
    eq(lan_devices_cache_should_write($nameless, $named), false, 'nameless fallback keeps a named cache');
    eq(lan_devices_cache_should_write($nameless, null), true, 'nameless result seeds an empty cache');
    eq(lan_devices_cache_should_write($nameless, $nameless), true, 'nameless replaces nameless');
}
