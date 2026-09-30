<?php
// Live connections: which LAN device talks to which host, by which route.
//
// Joins three router-side sources, each read once per request by the API:
//   - `conntrack -L`            -> the flows themselves (parse_conntrack_line)
//   - AdGuard querylog.json     -> IP -> domain from recent DNS answers (querylog_ip_map)
//   - Xray access.log           -> which outbound served a redirected flow (xray_access_outbounds)
// and classifies each flow (build_connections). Pure logic plus one streaming file-tail
// reader; no globals, no shelling out — the API runs `conntrack`/`ipset` and passes results
// in. Every source is consumed line by line: the router's PHP memory_limit is 8M.

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

// Tail windows read per `connections` request. Each is streamed line by line, never held
// whole: the router's PHP memory_limit is 8M and querylog.json alone is ~1 GB.
const CONNECTIONS_QUERYLOG_TAIL = 512 * 1024;
const CONNECTIONS_ACCESS_TAIL = 512 * 1024;
// querylog_ip_map() keeps at most this many IPs (the newest answers win).
const QUERYLOG_MAP_MAX = 4000;
// conntrack lines read at most; past it the result is flagged partial.
const CONNECTIONS_CONNTRACK_MAX_LINES = 20000;
// Longest line file_tail_lines() hands over; a longer one arrives in pieces that no
// parser here accepts.
const FILE_TAIL_LINE_MAX = 65536;

// The lines (without "\n") of the last $bytes of $path, oldest first, read one at a time
// with fgets — the tail is never loaded as one string. When the read starts mid-file the
// first, partial line is dropped. Nothing on any error (missing/unreadable file).
function file_tail_lines(string $path, int $bytes): Generator {
    $fh = @fopen($path, 'rb');
    if ($fh === false) return;
    try {
        $size = @filesize($path);
        if ($size === false || $size <= 0 || $bytes <= 0) return;
        $start = max(0, $size - $bytes);
        if (@fseek($fh, $start) !== 0) return;
        if ($start > 0) {
            // Skip the partial first line (it may be longer than one fgets chunk).
            do {
                $skip = fgets($fh, FILE_TAIL_LINE_MAX);
            } while ($skip !== false && substr($skip, -1) !== "\n");
        }
        // Stop at the size seen above: a log still being appended to can't stretch the read.
        while (ftell($fh) < $size && ($line = fgets($fh, FILE_TAIL_LINE_MAX)) !== false) {
            yield rtrim($line, "\r\n");
        }
    } finally {
        fclose($fh);
    }
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
// same IP overwrites an earlier one. Each line is decoded on its own as it streams in.
// Lines that don't decode (a cut first line, a half-flushed last line) are skipped. Blocked
// answers (0.0.0.0 / ::) are ignored. $onlyIps (a set, [ip => true]) keeps just the IPs
// the caller will look up. The map holds at most $max IPs, the newest answers winning.
function querylog_ip_map(
    iterable $lines, ?array $onlyIps = null, int $max = QUERYLOG_MAP_MAX
): array {
    $map = [];
    // Trimmed in batches (not per insert) so a full map doesn't cost O($max) per line.
    $slack = max(1, intdiv($max, 4));
    foreach ($lines as $line) {
        if ($line === '' || $line[0] !== '{') continue;
        $row = json_decode($line, true);
        if (!is_array($row) || !is_string($row['QH'] ?? null) || !is_string($row['Answer'] ?? null)) {
            continue;
        }
        $host = rtrim(strtolower($row['QH']), '.');
        if ($host === '') continue;
        foreach (dns_wire_answers($row['Answer']) as $ip) {
            if ($ip === '0.0.0.0' || $ip === '::') continue;
            if ($onlyIps !== null && !isset($onlyIps[$ip])) continue;
            unset($map[$ip]); // re-inserted at the end: the newest answers sit last
            $map[$ip] = $host;
        }
        if (count($map) > $max + $slack) $map = array_slice($map, -$max, null, true);
    }
    return count($map) > $max ? array_slice($map, -$max, null, true) : $map;
}

function _conn_is_lan_source(string $ip): bool {
    return (bool)preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip);
}

// One `conntrack -L` line -> a flow from a LAN source, or null (other sources, the
// summary line, anything unparsable). The first src/dst/sport/dport group is the original
// direction, the second the reply; packets/bytes are summed over both directions (null
// when the kernel doesn't account them). UDP lines carry no state (null).
function parse_conntrack_line(string $line): ?array {
    $tok = preg_split('/\s+/', trim($line));
    if (count($tok) < 4 || ($tok[0] !== 'tcp' && $tok[0] !== 'udp')) return null;
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
    if (!isset($o['src'], $o['dst'], $o['sport'], $o['dport'], $r['src'], $r['sport'])) return null;
    if (!_conn_is_lan_source($o['src'])) return null;
    $sum = function (string $k) use ($o, $r): ?int {
        if (!isset($o[$k]) && !isset($r[$k])) return null;
        return (int)($o[$k] ?? 0) + (int)($r[$k] ?? 0);
    };
    return [
        'proto' => $tok[0], 'src' => $o['src'], 'dst' => $o['dst'],
        'sport' => (int)$o['sport'], 'dport' => (int)$o['dport'],
        'reply_src' => $r['src'], 'reply_sport' => (int)$r['sport'],
        'state' => $state, 'packets' => $sum('packets'), 'bytes' => $sum('bytes'),
    ];
}

// Whole `conntrack -L` output -> parse_conntrack_line() flows (small inputs only; the
// `connections` request streams the table instead).
function parse_conntrack(string $text): array {
    $rows = [];
    foreach (explode("\n", $text) as $line) {
        $f = parse_conntrack_line($line);
        if ($f !== null) $rows[] = $f;
    }
    return $rows;
}

// access.log lines "… from IP:PORT accepted tcp:HOST:PORT [inbound -> outbound]" (Xray also
// writes ">>" for some routes) -> ["IP:PORT" => ['tag' => outbound, 'host' => HOST]].
// Later lines win for the same source. $onlyKeys (a set, ["IP:PORT" => true]) keeps just
// the sources the caller will look up, so a long tail can't grow a big map.
function xray_access_entries(iterable $lines, ?array $onlyKeys = null): array {
    $out = [];
    $re = '/ from (?:tcp:|udp:)?(\S+):(\d+) accepted (?:tcp|udp):(\S+):\d+ '
        . '\[[^\]]*?(?:->|>>)\s*([^\]\s]+)\s*\]/';
    foreach ($lines as $line) {
        if (!preg_match($re, $line, $m)) continue;
        $key = trim($m[1], '[]') . ':' . $m[2];
        if ($onlyKeys !== null && !isset($onlyKeys[$key])) continue;
        $out[$key] = ['tag' => $m[4], 'host' => trim($m[3], '[]')];
    }
    return $out;
}

// ["IP:PORT" => outbound tag] from xray_access_entries().
function xray_access_outbounds(array $entries): array {
    return array_map(fn(array $e): string => $e['tag'], $entries);
}

// ["IP:PORT" => domain] for access.log entries whose destination is a hostname (sniffed by
// Xray) — fills the gap for the newest flows that AdGuard hasn't flushed to querylog.json yet.
function xray_access_domains(array $entries): array {
    $out = [];
    foreach ($entries as $key => $e) {
        if (ip_is_valid($e['host'])) continue;
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
        if (!ip_is_valid($ip)) continue;
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
        if ($line !== '' && ip_is_valid($line)) $hits[$line] = true;
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
// testable. $io: conntrack(float $timeout): ?iterable (output lines; null = could not
// run), ipset(string $script, float $timeout): ?string, access_log(): iterable (lines),
// querylog(): iterable (lines), server_names: array, device_names: array.
// $now(): float is the clock (microtime(true) in production).
//
// All steps share one $budget-second deadline, in order of importance: conntrack (the rows
// themselves), ipset (leak detection), access.log (server + fallback domain), querylog
// (domains; the costliest CPU step). A step that hits its timeout, or is skipped because too
// little time is left, sets 'partial' => true instead of letting the request overrun.
// Memory stays flat whatever the table/log sizes: only the first $limit flows are kept
// (the rest are just counted), and the log tails keep only entries for those flows.
function connections_collect(int $limit, array $io, callable $now, float $budget = 2.8): array {
    $start = $now();
    $left = fn(): float => $budget - ($now() - $start);
    $partial = false;

    $t0 = $now();
    $ctTimeout = min(1.5, $left());
    $lines = $io['conntrack']($ctTimeout);
    if ($lines === null) return ['error' => CONNECTIONS_CONNTRACK_ERROR];
    $recognized = false; // a flow line or the "N flow entries" summary: conntrack really ran
    $flows = [];
    $total = 0;
    $read = 0;
    foreach ($lines as $line) {
        if (++$read > CONNECTIONS_CONNTRACK_MAX_LINES) { $partial = true; break; }
        if (!$recognized && preg_match('/^(tcp|udp) |flow entries/', $line)) $recognized = true;
        $f = parse_conntrack_line($line);
        if ($f === null || connections_is_local_dst($f['dst'])) continue;
        $total++;
        if (count($flows) < $limit) $flows[] = $f;
    }
    unset($lines); // a generator: dropping it ends the conntrack process now
    if ($now() - $t0 >= $ctTimeout - 0.01) $partial = true;
    if (!$recognized) return ['error' => CONNECTIONS_CONNTRACK_ERROR];

    // ipset leaves 1 s for the log steps; a timeout keeps the hits printed so far and
    // untested IPs count as "not in set".
    $hits = [];
    $script = ipset_batch_script(connections_ipset_candidates($flows));
    if ($script !== '') {
        $t = min(1.2, $left() - 1.0);
        if ($t < 0.1) {
            $partial = true;
        } else {
            $t1 = $now();
            $ipsetOut = $io['ipset']($script, $t);
            if ($now() - $t1 >= $t - 0.01) $partial = true;
            $hits = ipset_batch_hits((string)$ipsetOut);
        }
    }

    $flowKeys = [];
    $flowDsts = [];
    foreach ($flows as $f) {
        $flowKeys[$f['src'] . ':' . $f['sport']] = true;
        $flowDsts[$f['dst']] = true;
    }
    $access = [];
    if ($left() > 0.3) {
        $access = xray_access_entries($io['access_log'](), $flowKeys);
    } else {
        $partial = true;
    }
    $ipDomain = [];
    if ($left() > 1.0) {
        $ipDomain = querylog_ip_map($io['querylog'](), $flowDsts);
    } else {
        $partial = true;
    }

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
