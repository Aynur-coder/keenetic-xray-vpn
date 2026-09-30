<?php
declare(strict_types=1);

// Guards the router's PHP memory_limit (8M, the Entware php.ini default): the
// `connections` and `site_check` paths stream the AdGuard querylog tail, the Xray
// access.log tail and `conntrack -L` line by line instead of holding multi-MB strings.
// Generates realistic fixtures (2 MB querylog, 3000-line conntrack table, 1 MB
// access.log), then runs connections_collect() and site_check_run() in a child
// process under `php -d memory_limit=8M` (fixtures/memory_child.php) — a fatal there
// can't take down the rest of the suite — and asserts the child's real peak.

const MEMORY_CHILD = __DIR__ . '/fixtures/memory_child.php';
const MEMORY_PEAK_CEILING = 6 * 1024 * 1024;

// DNS response for $host with A answers $ips, question included, as AdGuard stores it.
function _mem_dns_wire(string $host, array $ips): string {
    $qname = '';
    foreach (explode('.', $host) as $label) $qname .= chr(strlen($label)) . $label;
    $msg = pack('nnnnnn', random_int(0, 0xFFFF), 0x8180, 1, count($ips), 0, 0);
    $msg .= $qname . "\x00" . pack('nn', 1, 1);
    foreach ($ips as $ip) {
        $msg .= pack('n', 0xC00C) . pack('nnNn', 1, 1, 300, 4) . inet_pton($ip);
    }
    return base64_encode($msg);
}

function _mem_ip(int $i): string {
    return sprintf('104.%d.%d.%d', 16 + intdiv($i, 65536) % 16, intdiv($i, 256) % 256, $i % 256);
}

// Writes the three fixtures into $dir; the conntrack flows point at IPs the querylog
// resolves, so the domain join does real work.
function _mem_write_fixtures(string $dir): void {
    $ql = fopen("$dir/querylog.json", 'wb');
    $written = 0;
    for ($i = 0; $written < 2 * 1024 * 1024; $i++) {
        $host = "cdn$i.edge" . ($i % 97) . '.example-service.com';
        $ips = [_mem_ip($i * 3), _mem_ip($i * 3 + 1), _mem_ip($i * 3 + 2)];
        $line = json_encode([
            'T' => '2026-09-30T03:08:18.' . str_pad((string)$i, 9, '0', STR_PAD_LEFT) . '+03:00',
            'QH' => $host, 'QT' => 'A', 'QC' => 'IN', 'CP' => '',
            'Answer' => _mem_dns_wire($host, $ips), 'IP' => '192.168.1.' . (100 + $i % 50),
            'Result' => ['Rules' => [], 'Reason' => 1, 'IsFiltered' => false],
            'Upstream' => 'https://dns.google:443/dns-query', 'Elapsed' => 1531320 + $i,
        ]) . "\n";
        $written += fwrite($ql, $line);
    }
    fclose($ql);
    $qlLines = $i;

    $ct = fopen("$dir/conntrack.txt", 'wb');
    for ($i = 0; $i < 3000; $i++) {
        $src = '192.168.1.' . (100 + $i % 50);
        $dst = _mem_ip(($qlLines - 1 - $i % $qlLines) * 3);
        $sport = 40000 + $i;
        if ($i % 3 === 0) {
            fwrite($ct, "tcp      6 431999 ESTABLISHED src=$src dst=$dst sport=$sport dport=443 "
                . "packets=25 bytes=38069 src=192.168.1.1 dst=$src sport=1080 dport=$sport "
                . "packets=23 bytes=7951 [ASSURED] mark=0 use=1\n");
        } elseif ($i % 3 === 1) {
            fwrite($ct, "udp      17 179 src=$src dst=$dst sport=$sport dport=443 packets=97 "
                . "bytes=27079 src=$dst dst=95.105.78.232 sport=443 dport=$sport packets=81 "
                . "bytes=33186 [ASSURED] [FASTNAT] mark=0 nmark=0 sc=0 ifw=36 ifl=38 "
                . "mac=ec:2c:0d:34:18:c5 slan attrs= use=3\n");
        } else {
            fwrite($ct, "tcp      6 100 ESTABLISHED src=$src dst=$dst sport=$sport dport=443 "
                . "packets=10 bytes=2000 src=$dst dst=95.105.78.232 sport=443 dport=$sport "
                . "packets=12 bytes=9000 [ASSURED] mark=0 use=1\n");
        }
    }
    fwrite($ct, "conntrack v1.4.6 (conntrack-tools): 3000 flow entries have been shown.\n");
    fclose($ct);

    $al = fopen("$dir/access.log", 'wb');
    $written = 0;
    for ($i = 0; $written < 1024 * 1024; $i++) {
        $src = '192.168.1.' . (100 + $i % 50);
        $written += fwrite($al, sprintf(
            "2026/09/30 00:50:%02d.%06d from %s:%d accepted tcp:host%d.example-service.com:443 "
                . "[tproxy-in -> key-%08x]\n",
            $i % 60, $i % 1000000, $src, 40000 + $i % 3000, $i, $i % 7
        ));
    }
    fclose($al);
}

function test_connections_and_site_check_fit_in_8m(): void {
    $dir = sys_get_temp_dir() . '/xray-mem-' . getmypid();
    @mkdir($dir);
    try {
        _mem_write_fixtures($dir);
        eq(filesize("$dir/querylog.json") >= 2 * 1024 * 1024, true, '2 MB querylog fixture');
        $cmd = escapeshellarg(PHP_BINARY) . ' -n -d memory_limit=8M '
            . escapeshellarg(MEMORY_CHILD) . ' ' . escapeshellarg($dir) . ' 2>&1';
        $out = (string)shell_exec($cmd);
    } finally {
        foreach (glob("$dir/*") ?: [] as $f) unlink($f);
        @rmdir($dir);
    }
    $res = json_decode(trim($out), true);
    eq(is_array($res), true, 'child printed JSON (output: ' . substr($out, 0, 300) . ')');
    eq($res['error'] ?? null, null, 'child ran without error');
    eq($res['connections_total'], 3000, 'every conntrack row counted');
    eq($res['connections_rows'], 300, 'rows capped at the limit');
    eq($res['connections_with_domain'] > 0, true, 'querylog tail joined domains');
    eq($res['connections_with_server'] > 0, true, 'access.log tail joined servers');
    eq($res['site_check_ok'], true, 'site_check_run completed with flow counts');
    eq($res['peak_real'] < MEMORY_PEAK_CEILING, true,
        'peak real memory under 6 MB (got ' . $res['peak_real'] . ')');
}
