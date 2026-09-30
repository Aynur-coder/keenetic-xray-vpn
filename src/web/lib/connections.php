<?php
// Live connections: which LAN device talks to which host, by which route.
//
// Joins three router-side sources, each read once per request by the API:
//   - `conntrack -L`            -> the flows themselves (parse_conntrack)
//   - AdGuard querylog.json     -> IP -> domain from recent DNS answers (querylog_ip_map)
//   - Xray access.log           -> which outbound served a redirected flow (xray_access_outbounds)
// and classifies each flow (build_connections). Pure logic plus one bounded file reader;
// no globals, no shelling out — the API runs `conntrack`/`ipset` and passes results in.

require_once __DIR__ . '/routing.php';

// Destinations that are never "a connection to the internet" (LAN, router, loopback,
// multicast…) — dropped from the list so it isn't flooded with DNS/UI traffic to the router.
const CONNECTIONS_LOCAL_NETS = [
    '0.0.0.0/8', '10.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
    '192.168.0.0/16', '224.0.0.0/4', '240.0.0.0/4',
    '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8',
];

// Xray's transparent inbounds on the router (xray-manager.sh REDIR_PORT/FULLVPN_PORT): a flow
// whose reply comes from one of these ports was redirected into Xray.
const CONNECTIONS_XRAY_PORTS = [1080, 1083];

const CONNECTIONS_DEFAULT_LIMIT = 300;
const CONNECTIONS_MAX_LIMIT = 500;

function connections_limit(?string $raw): int {
    $n = ($raw !== null && preg_match('/^\d+$/', $raw)) ? (int)$raw : 0;
    if ($n <= 0) return CONNECTIONS_DEFAULT_LIMIT;
    return min($n, CONNECTIONS_MAX_LIMIT);
}

// Last $bytes of $path without ever loading the whole file (querylog.json is ~1 GB).
// When the read starts mid-file the first, partial line is dropped. '' on any error.
function read_file_tail(string $path, int $bytes): string {
    $fh = @fopen($path, 'rb');
    if ($fh === false) return '';
    $size = @filesize($path);
    if ($size === false || $size <= 0 || $bytes <= 0) { fclose($fh); return ''; }
    $start = max(0, $size - $bytes);
    if (@fseek($fh, $start) !== 0) { fclose($fh); return ''; }
    $data = (string)stream_get_contents($fh, $size - $start);
    fclose($fh);
    if ($start > 0) {
        $nl = strpos($data, "\n");
        $data = $nl === false ? '' : substr($data, $nl + 1);
    }
    return $data;
}

// Offset just past the (possibly compressed) domain name at $pos, or null if it runs off
// the end. A compression pointer always ends a name, so this never follows pointers and
// can't loop.
function _dns_skip_name(string $msg, int $pos): ?int {
    $len = strlen($msg);
    while ($pos < $len) {
        $b = ord($msg[$pos]);
        if ($b === 0) return $pos + 1;
        if (($b & 0xC0) === 0xC0) return $pos + 2 <= $len ? $pos + 2 : null;
        if (($b & 0xC0) !== 0) return null; // reserved label types
        $pos += 1 + $b;
    }
    return null;
}

// IPs from the A/AAAA answer records of a base64 DNS wire message; other record types
// (CNAME, TXT…) are skipped. Compressed names are skipped, not resolved — owner names
// aren't needed to pull out A/AAAA data, and never following pointers rules out loops.
// Malformed/truncated input yields what parsed before the break.
function dns_wire_answers(string $wireB64): array {
    $msg = base64_decode($wireB64, true);
    if ($msg === false || strlen($msg) < 12) return [];
    $h = unpack('nid/nflags/nqd/nan', substr($msg, 0, 8));
    $len = strlen($msg);
    $pos = 12;
    for ($i = 0; $i < $h['qd']; $i++) {
        $pos = _dns_skip_name($msg, $pos);
        if ($pos === null || $pos + 4 > $len) return [];
        $pos += 4;
    }
    $ips = [];
    for ($i = 0; $i < $h['an']; $i++) {
        $pos = _dns_skip_name($msg, $pos);
        if ($pos === null || $pos + 10 > $len) break;
        $rr = unpack('ntype/nclass/Nttl/nrdlen', substr($msg, $pos, 10));
        $pos += 10;
        if ($pos + $rr['rdlen'] > $len) break;
        if (($rr['type'] === 1 && $rr['rdlen'] === 4) || ($rr['type'] === 28 && $rr['rdlen'] === 16)) {
            $ip = inet_ntop(substr($msg, $pos, $rr['rdlen']));
            if ($ip !== false) $ips[] = $ip;
        }
        $pos += $rr['rdlen'];
    }
    return $ips;
}

// [ip => domain] from querylog.json lines (JSONL, oldest first), so a later answer for the
// same IP overwrites an earlier one. Lines that don't decode (the cut first line of a tail
// read, a half-flushed last line) are skipped. Blocked answers (0.0.0.0 / ::) are ignored.
function querylog_ip_map(string $jsonl): array {
    $map = [];
    foreach (explode("\n", $jsonl) as $line) {
        if ($line === '' || $line[0] !== '{') continue;
        $row = json_decode($line, true);
        if (!is_array($row) || !is_string($row['QH'] ?? null) || !is_string($row['Answer'] ?? null)) {
            continue;
        }
        $host = rtrim(strtolower($row['QH']), '.');
        if ($host === '') continue;
        foreach (dns_wire_answers($row['Answer']) as $ip) {
            if ($ip === '0.0.0.0' || $ip === '::') continue;
            $map[$ip] = $host;
        }
    }
    return $map;
}

function _conn_is_lan_source(string $ip): bool {
    return (bool)preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip);
}

// conntrack -L output -> flows from LAN sources. The first src/dst/sport/dport group is the
// original direction, the second the reply; packets/bytes are summed over both directions
// (null when the kernel doesn't account them). UDP lines carry no state (null).
function parse_conntrack(string $text): array {
    $rows = [];
    foreach (explode("\n", $text) as $line) {
        $tok = preg_split('/\s+/', trim($line));
        if (count($tok) < 4 || ($tok[0] !== 'tcp' && $tok[0] !== 'udp')) continue;
        $dir = [[], []];
        $n = -1;
        $state = null;
        foreach ($tok as $i => $t) {
            $eq = strpos($t, '=');
            if ($eq === false) {
                if ($i === 3 && preg_match('/^[A-Z_]+$/', $t)) $state = $t;
                continue;
            }
            $k = substr($t, 0, $eq);
            if ($k === 'src') $n++;
            if ($n < 0 || $n > 1) continue;
            if (in_array($k, ['src', 'dst', 'sport', 'dport', 'packets', 'bytes'], true)) {
                $dir[$n][$k] = substr($t, $eq + 1);
            }
        }
        [$o, $r] = $dir;
        if (!isset($o['src'], $o['dst'], $o['sport'], $o['dport'], $r['src'], $r['sport'])) continue;
        if (!_conn_is_lan_source($o['src'])) continue;
        $sum = function (string $k) use ($o, $r): ?int {
            if (!isset($o[$k]) && !isset($r[$k])) return null;
            return (int)($o[$k] ?? 0) + (int)($r[$k] ?? 0);
        };
        $rows[] = [
            'proto' => $tok[0], 'src' => $o['src'], 'dst' => $o['dst'],
            'sport' => (int)$o['sport'], 'dport' => (int)$o['dport'],
            'reply_src' => $r['src'], 'reply_sport' => (int)$r['sport'],
            'state' => $state, 'packets' => $sum('packets'), 'bytes' => $sum('bytes'),
        ];
    }
    return $rows;
}

// access.log lines "… from IP:PORT accepted tcp:HOST:PORT [inbound -> outbound]" (Xray also
// writes ">>" for some routes) -> ["IP:PORT" => ['tag' => outbound, 'host' => HOST]].
// Later lines win for the same source.
function _xray_access_entries(string $log): array {
    $out = [];
    $re = '/ from (?:tcp:|udp:)?(\S+):(\d+) accepted (?:tcp|udp):(\S+):\d+ '
        . '\[[^\]]*?(?:->|>>)\s*([^\]\s]+)\s*\]/';
    foreach (explode("\n", $log) as $line) {
        if (!preg_match($re, $line, $m)) continue;
        $src = trim($m[1], '[]');
        $out[$src . ':' . $m[2]] = ['tag' => $m[4], 'host' => trim($m[3], '[]')];
    }
    return $out;
}

function xray_access_outbounds(string $log): array {
    return array_map(fn(array $e): string => $e['tag'], _xray_access_entries($log));
}

// ["IP:PORT" => domain] for access.log lines whose destination is a hostname (sniffed by
// Xray) — fills the gap for the newest flows that AdGuard hasn't flushed to querylog.json yet.
function xray_access_domains(string $log): array {
    $out = [];
    foreach (_xray_access_entries($log) as $key => $e) {
        if (filter_var($e['host'], FILTER_VALIDATE_IP) !== false) continue;
        $out[$key] = strtolower($e['host']);
    }
    return $out;
}

function connections_is_local_dst(string $ip): bool {
    foreach (CONNECTIONS_LOCAL_NETS as $net) {
        if (ip_token_matches($net, $ip)) return true;
    }
    return false;
}

function _conn_is_redirected(array $f): bool {
    return connections_is_local_dst($f['reply_src'])
        && in_array($f['reply_sport'], CONNECTIONS_XRAY_PORTS, true);
}

// Flows whose destination IP must be looked up in the vpn ipset for classification:
// not redirected into Xray, and either tcp (a leak candidate) or udp/443 (QUIC).
function connections_ipset_candidates(array $flows): array {
    $ips = [];
    foreach ($flows as $f) {
        if (connections_is_local_dst($f['dst']) || _conn_is_redirected($f)) continue;
        if ($f['proto'] === 'tcp' || $f['dport'] === 443) $ips[$f['dst']] = true;
    }
    return array_keys($ips);
}

// Classifies parse_conntrack() flows:
//   vpn     — reply comes from the router's Xray port (:1080 selective, :1083 full-VPN);
//             server = serverNames[outbound tag from access.log] ?? the tag itself
//   blocked — redirected into Xray, which sent it to its 'block' outbound
//   direct  — redirected into Xray, which sent it to its 'direct' outbound; or not
//             redirected and the destination is not in the vpn set
//   leak    — not redirected, yet the destination IS in the vpn set: tcp that bypassed the
//             redirect, or udp/443. The QUIC guard REJECTs in FORWARD, before conntrack
//             confirms the entry, so a udp/443 flow that shows up here got through.
// $inVpn(string $ip): bool is only called for non-redirected tcp and udp/443 flows.
// $accessDomains (xray_access_domains()) is a fallback when the querylog has no answer.
function build_connections(
    array $flows, array $ipDomain, array $accessMap, callable $inVpn, array $serverNames,
    array $accessDomains = []
): array {
    $rows = [];
    foreach ($flows as $f) {
        if (connections_is_local_dst($f['dst'])) continue;
        $key = $f['src'] . ':' . $f['sport'];
        $server = null;
        if (_conn_is_redirected($f)) {
            // Xray's own 'direct'/'block' outbounds mean the traffic was not proxied.
            $tag = $accessMap[$key] ?? null;
            if ($tag === 'direct') {
                $route = 'direct';
            } elseif ($tag === 'block') {
                $route = 'blocked';
            } else {
                $route = 'vpn';
                if ($tag !== null) $server = $serverNames[$tag] ?? $tag;
            }
        } elseif ($f['proto'] === 'tcp' || $f['dport'] === 443) {
            $route = $inVpn($f['dst']) ? 'leak' : 'direct';
        } else {
            $route = 'direct';
        }
        $rows[] = [
            'device_ip' => $f['src'],
            'domain'    => $ipDomain[$f['dst']] ?? $accessDomains[$key] ?? null,
            'dst'       => $f['dst'],
            'dport'     => $f['dport'],
            'proto'     => $f['proto'],
            'route'     => $route,
            'server'    => $server,
            'bytes'     => $f['bytes'],
        ];
    }
    return $rows;
}

// Shell script testing each unique IP against the vpn ipsets (vpn1 for IPv4, vpn6 for IPv6)
// in ONE process, printing the IPs that are members. Anything that isn't a valid IP literal
// is dropped before it can reach the shell.
function ipset_batch_script(array $ips): string {
    $script = '';
    foreach (array_unique($ips) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) continue;
        $set = strpos($ip, ':') === false ? 'vpn1' : 'vpn6';
        $q = escapeshellarg($ip);
        $script .= "ipset test $set $q >/dev/null 2>&1 && echo $q; ";
    }
    return $script;
}

function ipset_batch_hits(string $out): array {
    $hits = [];
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line !== '' && filter_var($line, FILTER_VALIDATE_IP) !== false) $hits[$line] = true;
    }
    return $hits;
}

// Outbound tag -> human name, with the same tag scheme build_outbound_tags() emits
// ('key-<id>' for keys.json, 'sub-<id>' for cached subscription servers).
function connections_server_names(array $keys, array $cached): array {
    $names = ['direct' => 'Напрямую', 'block' => 'Заблокировано'];
    foreach ($keys as $k) {
        if (!empty($k['id'])) $names['key-' . $k['id']] = (string)($k['name'] ?? $k['id']);
    }
    foreach ($cached as $s) {
        if (!empty($s['id'])) $names['sub-' . $s['id']] = (string)($s['name'] ?? $s['id']);
    }
    return $names;
}

// [ip => name] from a saved lan_devices result (keenetic_get_devices() rows). Hosts
// without a name are left out so the UI falls back to the IP.
function connections_device_names(array $devices): array {
    $names = [];
    foreach ($devices as $d) {
        if (!is_array($d)) continue;
        $ip = (string)($d['ip'] ?? '');
        $name = trim((string)($d['hostname'] ?? ''));
        if ($ip !== '' && $name !== '') $names[$ip] = $name;
    }
    return $names;
}

const CONNECTIONS_CONNTRACK_ERROR = 'Не удалось прочитать таблицу соединений (conntrack)';

// The whole `connections` request, with every I/O step injected so the time budget is
// testable. $io: conntrack(float $timeout): ?string, ipset(string $script, float $timeout):
// ?string, access_log(): string, querylog(): string, server_names: array, device_names: array.
// $now(): float is the clock (microtime(true) in production).
//
// All steps share one $budget-second deadline, in order of importance: conntrack (the rows
// themselves), ipset (leak detection), access.log (server + fallback domain), querylog
// (domains; the costliest CPU step). A step that hits its timeout, or is skipped because too
// little time is left, sets 'partial' => true instead of letting the request overrun.
function connections_collect(int $limit, array $io, callable $now, float $budget = 2.8): array {
    $start = $now();
    $left = fn(): float => $budget - ($now() - $start);
    $partial = false;
    // Runs one step with a timeout: [its output, whether it used (almost) all of it].
    $timed = function (callable $fn, float $timeout) use ($now): array {
        $t0 = $now();
        $out = $fn($timeout);
        return [$out, $now() - $t0 >= $timeout - 0.01];
    };

    [$ct, $timedOut] = $timed($io['conntrack'], min(1.5, $left()));
    if ($timedOut) $partial = true;
    if ($ct === null || !preg_match('/^(tcp|udp) |flow entries/m', $ct)) {
        return ['error' => CONNECTIONS_CONNTRACK_ERROR];
    }
    $flows = array_values(array_filter(
        parse_conntrack($ct),
        fn(array $f): bool => !connections_is_local_dst($f['dst'])
    ));
    unset($ct);
    $total = count($flows);
    $flows = array_slice($flows, 0, $limit);

    // ipset leaves 1 s for the log steps; a timeout keeps the hits printed so far and
    // untested IPs count as "not in set".
    $hits = [];
    $script = ipset_batch_script(connections_ipset_candidates($flows));
    if ($script !== '') {
        $t = min(1.2, $left() - 1.0);
        if ($t < 0.1) {
            $partial = true;
        } else {
            $ipset = $io['ipset'];
            [$ipsetOut, $timedOut] = $timed(fn(float $to) => $ipset($script, $to), $t);
            if ($timedOut) $partial = true;
            $hits = ipset_batch_hits((string)$ipsetOut);
        }
    }

    $access = '';
    if ($left() > 0.3) $access = $io['access_log'](); else $partial = true;
    $ipDomain = [];
    if ($left() > 1.0) $ipDomain = querylog_ip_map($io['querylog']()); else $partial = true;

    $rows = build_connections(
        $flows, $ipDomain, xray_access_outbounds($access),
        fn(string $ip): bool => isset($hits[$ip]),
        $io['server_names'], xray_access_domains($access)
    );
    foreach ($rows as &$r) {
        $r['device_name'] = $io['device_names'][$r['device_ip']] ?? null;
    }
    unset($r);

    return ['connections' => $rows, 'total' => $total, 'limit' => $limit, 'partial' => $partial];
}

// Whether a fresh lan_devices result should replace the cache: never an empty one, and
// never a nameless one (the ARP fallback) over a cache that has hostnames.
function lan_devices_cache_should_write(array $new, ?array $old): bool {
    if (!$new) return false;
    if (connections_device_names($new)) return true;
    return !connections_device_names($old ?? []);
}
