<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/sitecheck.php';

// ---- parse_nslookup_ips() -----------------------------------------------------

function test_parse_nslookup_ips_multiple_answers(): void {
    $out = "Server:    127.0.0.1\nAddress 1: 127.0.0.1\n\n"
        . "Name:      example.com\nAddress 1: 93.184.216.34\nAddress 2: 2606:2800:220:1:248:1893:25c8:1946\n";
    eq(
        parse_nslookup_ips($out),
        ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
        'both A and AAAA answers collected, resolver\'s own address excluded'
    );
}

function test_parse_nslookup_ips_no_name_block(): void {
    eq(parse_nslookup_ips("Server:    127.0.0.1\nAddress 1: 127.0.0.1\n"), [], 'no Name: block -> no answers');
    eq(parse_nslookup_ips(''), [], 'empty output -> no answers');
}

function test_parse_nslookup_ips_dedupes(): void {
    $out = "Name:      example.com\nAddress 1: 1.2.3.4\nAddress 2: 1.2.3.4\n";
    eq(parse_nslookup_ips($out), ['1.2.3.4'], 'duplicate answers collapsed');
}

// ---- site_check_count_flows() -------------------------------------------------

function test_site_check_count_flows_counts_direct_and_quic_leak(): void {
    $connections = [
        ['dst' => '1.1.1.1', 'route' => 'direct', 'proto' => 'tcp', 'dport' => 443],
        ['dst' => '1.1.1.1', 'route' => 'direct', 'proto' => 'tcp', 'dport' => 80],
        ['dst' => '1.1.1.1', 'route' => 'leak', 'proto' => 'udp', 'dport' => 443],
        ['dst' => '1.1.1.1', 'route' => 'leak', 'proto' => 'tcp', 'dport' => 443], // leak but not udp/443
        ['dst' => '2.2.2.2', 'route' => 'direct', 'proto' => 'tcp', 'dport' => 443], // different IP, ignored
        ['dst' => '1.1.1.1', 'route' => 'vpn', 'proto' => 'tcp', 'dport' => 443],
    ];
    $r = site_check_count_flows($connections, ['1.1.1.1']);
    eq($r, ['direct_flows' => 2, 'quic_leaks' => 1], 'only rows matching the site\'s IPs are counted');
}

function test_site_check_count_flows_no_matching_ips(): void {
    eq(site_check_count_flows([['dst' => '9.9.9.9', 'route' => 'direct', 'proto' => 'tcp', 'dport' => 443]], []),
        ['direct_flows' => 0, 'quic_leaks' => 0], 'no site IPs -> nothing counted');
}

// ---- site_check_run() ----------------------------------------------------------

const _SC_YT_RU = '<html>ytcfg.set({"INNERTUBE_CONTEXT":{"client":{"countryCode":"RU"}}});</html>';

function _sc_explain(string $target, string $name = ''): callable {
    return fn(string $d, callable $inVpnSet): array => [
        'input' => $d, 'kind' => 'domain', 'rule' => null, 'source' => null,
        'target' => $target, 'target_name' => $name, 'in_vpn_set' => false,
    ];
}

// Every exit fetcher fails the test unless the case under test overrides it, so a case
// proves which fetcher site_check_run() picked, not just what it returned.
function _sc_io(array $overrides = []): array {
    $never = function (string $what): callable {
        return function () use ($what): array {
            throw new RuntimeException("$what must not be called");
        };
    };
    return $overrides + [
        'resolve_ips'   => fn(string $d): array => ['1.1.1.1'],
        'ip_in_vpn_set' => fn(?string $ip): ?bool => false,
        'explain'       => _sc_explain('direct', 'Напрямую'),
        'active_id'     => fn(): ?string => throw new RuntimeException('active_id not expected'),
        'server_name'   => fn(string $id): ?string => ['k-ee' => '🇪🇪 Tallinn',
            'k-nl' => '🇳🇱 Amsterdam'][$id] ?? null,
        'probe_server'  => $never('probe_server'),
        'fetch_direct'  => $never('fetch_direct'),
        'connections'   => fn(): array => [],
    ];
}

function _sc_probe_ok(string $gc, string $cc, string $ip): array {
    return ['ok' => true, 'delay_ms' => 120, 'exit_ip' => $ip, 'google_country' => $gc,
            'exit_country' => $cc, 'error' => null];
}

function test_site_check_run_direct_route_measures_without_proxy(): void {
    $io = _sc_io(['fetch_direct' => fn(): array => [
        'youtube' => _SC_YT_RU, 'trace' => "ip=95.105.78.232\nloc=RU\n"]]);
    $r = site_check_run('ya.ru', $io);
    eq($r['domain'], 'ya.ru', 'domain echoed back');
    eq($r['ips'], ['1.1.1.1'], 'resolved ips passed through');
    eq($r['in_vpn_set'], false, 'in_vpn_set passed through');
    eq($r['via'], 'direct', 'explain target direct -> via direct');
    eq($r['google_country'], 'RU', 'direct: the router\'s own country as Google sees it');
    eq($r['exit_country'], 'RU', 'direct: Cloudflare loc of the direct fetch');
    eq($r['exit_ip'], '95.105.78.232', 'direct: exit ip from the trace');
    eq($r['country_via'], 'Напрямую', 'measured directly');
    eq($r['country_error'], null, 'no error');
    eq([$r['direct_flows'], $r['quic_leaks']], [0, 0], 'no connections -> zero');
}

function test_site_check_run_direct_fetch_failure_is_null_with_reason(): void {
    $io = _sc_io(['fetch_direct' => fn(): array => ['youtube' => null, 'trace' => null]]);
    $r = site_check_run('ya.ru', $io);
    eq([$r['google_country'], $r['exit_country'], $r['exit_ip']], [null, null, null],
        'failed fetches -> nulls');
    eq($r['country_via'], 'Напрямую', 'still says where it tried');
    eq(is_string($r['country_error']) && $r['country_error'] !== '', true, 'reason given');
}

function test_site_check_run_server_target_probes_that_server(): void {
    $probed = [];
    $io = _sc_io([
        'explain' => _sc_explain('k-ee', '🇪🇪 Tallinn'),
        'probe_server' => function (string $id) use (&$probed): array {
            $probed[] = $id;
            return _sc_probe_ok('EE', 'EE', '203.0.113.7');
        },
    ]);
    $r = site_check_run('gemini.google.com', $io);
    eq($probed, ['k-ee'], 'the rule\'s own server is probed, once');
    eq($r['via'], 'vpn', 'server target -> via vpn');
    eq($r['google_country'], 'EE', 'country from that server\'s probe');
    eq($r['exit_country'], 'EE', 'cloudflare country from that server\'s probe');
    eq($r['exit_ip'], '203.0.113.7', 'exit ip from that server\'s probe');
    eq($r['country_via'], '🇪🇪 Tallinn', 'names the server the country came from');
    eq($r['country_error'], null, 'no error');
    eq($r['explain']['target'], 'k-ee', 'route_explain result passed through untouched');
}

function test_site_check_run_proxy_target_probes_the_active_server(): void {
    $probed = [];
    $io = _sc_io([
        'explain' => _sc_explain('proxy', 'VPN (активный сервер)'),
        'active_id' => fn(): ?string => 'k-nl',
        'probe_server' => function (string $id) use (&$probed): array {
            $probed[] = $id;
            return _sc_probe_ok('NL', 'NL', '198.51.100.4');
        },
    ]);
    $r = site_check_run('youtube.com', $io);
    eq($probed, ['k-nl'], "'proxy' resolves to the active server id");
    eq($r['via'], 'vpn', 'proxy target -> via vpn');
    eq($r['google_country'], 'NL', 'country from the active server');
    eq($r['country_via'], '🇳🇱 Amsterdam', 'active server named');
}

function test_site_check_run_proxy_without_active_server_is_null_with_reason(): void {
    $io = _sc_io([
        'explain' => _sc_explain('proxy', 'VPN (активный сервер)'),
        'active_id' => fn(): ?string => null,
    ]);
    $r = site_check_run('youtube.com', $io);
    eq([$r['google_country'], $r['exit_country'], $r['country_via']], [null, null, null],
        'nothing measured, nothing named');
    eq(is_string($r['country_error']) && $r['country_error'] !== '', true, 'reason given');
}

function test_site_check_run_probe_failure_is_null_with_reason(): void {
    foreach ([
        ['error' => 'Проверка уже идёт'],
        ['ok' => false, 'delay_ms' => null, 'exit_ip' => null, 'google_country' => null,
         'exit_country' => null, 'error' => 'Сервер не отвечает через прокси'],
    ] as $row) {
        $io = _sc_io([
            'explain' => _sc_explain('k-ee', '🇪🇪 Tallinn'),
            'probe_server' => fn(string $id): array => $row,
        ]);
        $r = site_check_run('gemini.google.com', $io);
        eq($r['google_country'], null, 'probe failed -> google_country null, never a guess');
        eq([$r['exit_country'], $r['exit_ip']], [null, null], 'probe failed -> no exit data');
        eq($r['country_error'], $row['error'], 'the probe\'s own reason passed on');
        eq($r['country_via'], '🇪🇪 Tallinn', 'still says which server was tried');
    }
}

function test_site_check_run_probe_ok_without_google_marker_gives_reason(): void {
    $io = _sc_io([
        'explain' => _sc_explain('k-ee', '🇪🇪 Tallinn'),
        'probe_server' => fn(string $id): array => ['ok' => true, 'delay_ms' => 90,
            'exit_ip' => '203.0.113.7', 'google_country' => null, 'exit_country' => 'EE',
            'error' => null],
    ]);
    $r = site_check_run('gemini.google.com', $io);
    eq([$r['google_country'], $r['exit_country']], [null, 'EE'], 'cloudflare still reported');
    eq(is_string($r['country_error']) && $r['country_error'] !== '', true, 'reason for Google');
}

function test_site_check_run_unknown_server_name_falls_back_to_id(): void {
    $io = _sc_io([
        'explain' => _sc_explain('k-gone', ''),
        'probe_server' => fn(string $id): array => ['error' => 'Сервер не найден'],
    ]);
    $r = site_check_run('example.com', $io);
    eq($r['country_via'], 'k-gone', 'no name -> the id, not null');
    eq($r['country_error'], 'Сервер не найден', 'reason passed on');
}

function test_site_check_exit_target(): void {
    eq(site_check_exit_target('direct', fn(): ?string => 'x'), 'direct', 'direct stays direct');
    eq(site_check_exit_target('k-ee', fn(): ?string => 'x'), 'k-ee', 'server id stays itself');
    eq(site_check_exit_target('proxy', fn(): ?string => 'k-nl'), 'k-nl', 'proxy -> active id');
    eq(site_check_exit_target('proxy', fn(): ?string => null), null, 'no active -> null');
    eq(site_check_exit_target('proxy', fn(): ?string => ''), null, 'empty active -> null');
}

function test_site_check_first_ipv4(): void {
    eq(site_check_first_ipv4(['2a00::1', '1.2.3.4', '5.6.7.8']), '1.2.3.4', 'first IPv4 in order');
    eq(site_check_first_ipv4(['2a00::1']), null, 'IPv6 only -> null');
    eq(site_check_first_ipv4([]), null, 'no answers -> null');
}

function test_site_check_run_resolves_once_and_reuses_ips(): void {
    $resolves = 0;
    $probed = [];
    $explainProbe = null;
    $io = _sc_io([
        'resolve_ips' => function (string $d) use (&$resolves): array {
            $resolves++;
            return ['2a00::1', '9.9.9.9'];
        },
        'ip_in_vpn_set' => function (?string $ip) use (&$probed): ?bool {
            $probed[] = $ip;
            return true;
        },
        'explain' => function (string $d, callable $inVpnSet) use (&$explainProbe): array {
            $explainProbe = $inVpnSet('domain', $d);
            return ['input' => $d, 'kind' => 'domain', 'rule' => null, 'source' => null,
                    'target' => 'k-ee', 'target_name' => 'VPN', 'in_vpn_set' => $explainProbe];
        },
        'probe_server' => fn(string $id): array => _sc_probe_ok('EE', 'EE', '203.0.113.7'),
        'connections' => fn(): array => [
            ['dst' => '9.9.9.9', 'route' => 'direct', 'proto' => 'tcp', 'dport' => 443],
            ['dst' => '2a00::1', 'route' => 'leak', 'proto' => 'udp', 'dport' => 443],
        ],
    ]);
    $r = site_check_run('example.com', $io);
    eq($resolves, 1, 'domain resolved exactly once');
    eq($probed, ['9.9.9.9'], 'ipset probed once, with the first IPv4 answer');
    eq($explainProbe, true, 'route_explain reuses the computed in_vpn_set');
    eq($r['in_vpn_set'], true, 'in_vpn_set from the resolved IPs');
    eq([$r['direct_flows'], $r['quic_leaks']], [1, 1], 'flows matched against the same IPs');
}

function test_site_check_run_without_connections_skips_flows(): void {
    $r = site_check_run('example.com', _sc_io(['connections' => null,
        'fetch_direct' => fn(): array => ['youtube' => null, 'trace' => null]]));
    eq($r['direct_flows'], null, 'connections tool off -> direct_flows null');
    eq($r['quic_leaks'], null, 'connections tool off -> quic_leaks null');
    eq(array_keys($r), ['domain', 'ips', 'in_vpn_set', 'explain', 'via', 'google_country',
        'exit_country', 'exit_ip', 'country_via', 'country_error', 'direct_flows', 'quic_leaks'],
        'result shape: only gained exit_ip/country_via/country_error');
}

// ---- log_source_command() -----------------------------------------------------

function test_log_source_command_known_sources(): void {
    eq(log_source_command('xray_error', 50), "tail -n '50' '/opt/var/log/xray/error.log' 2>/dev/null",
        'xray_error maps to error.log');
    eq(log_source_command('xray_access', 50), "tail -n '50' '/opt/var/log/xray/access.log' 2>/dev/null",
        'xray_access maps to access.log');
    eq(log_source_command('adguard', 50), "tail -n '50' '/opt/var/log/AdGuardHome.log' 2>/dev/null",
        'adguard maps to AdGuardHome.log');
    eq(log_source_command('update', 50), "tail -n '50' '/opt/var/log/xray/update.log' 2>/dev/null",
        'update maps to update.log');
    eq(log_source_command('manager', 50),
        "(logread -e xray-mgr 2>/dev/null || logread 2>/dev/null | grep xray-mgr) | tail -n '50'",
        'manager falls back to plain logread + grep when -e is unsupported');
}

function test_log_source_command_unknown_rejected(): void {
    eq(log_source_command('bogus', 50), null, 'unknown source is rejected, not shelled out');
    eq(log_source_command('', 50), null, 'empty source rejected');
    eq(log_source_command('../../etc/passwd', 50), null, 'path-traversal-looking source rejected');
}

function test_log_source_command_lines_floor(): void {
    eq(log_source_command('xray_error', 0), "tail -n '1' '/opt/var/log/xray/error.log' 2>/dev/null",
        'zero/negative lines floored to 1');
    eq(log_source_command('xray_error', -5), "tail -n '1' '/opt/var/log/xray/error.log' 2>/dev/null",
        'negative lines floored to 1');
}
