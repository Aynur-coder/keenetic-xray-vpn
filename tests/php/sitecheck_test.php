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

function _sc_io(array $overrides = []): array {
    return $overrides + [
        'resolve_ips'  => fn(string $d): array => ['1.1.1.1'],
        'in_vpn_set'   => fn(string $kind, string $input): ?bool => false,
        'explain'      => fn(string $d): array => [
            'input' => $d, 'kind' => 'domain', 'rule' => null, 'source' => null,
            'target' => 'direct', 'target_name' => 'Напрямую', 'in_vpn_set' => false,
        ],
        'fetch'        => fn(): array => ['youtube' => null, 'trace' => null],
        'connections'  => fn(): array => [],
    ];
}

function test_site_check_run_direct_route(): void {
    $r = site_check_run('example.com', _sc_io());
    eq($r['domain'], 'example.com', 'domain echoed back');
    eq($r['ips'], ['1.1.1.1'], 'resolved ips passed through');
    eq($r['in_vpn_set'], false, 'in_vpn_set passed through');
    eq($r['via'], 'direct', 'explain target direct -> via direct');
    eq($r['google_country'], null, 'no youtube html -> null');
    eq($r['exit_country'], null, 'no trace text -> null');
    eq($r['direct_flows'], 0, 'no connections -> zero');
    eq($r['quic_leaks'], 0, 'no connections -> zero');
}

function test_site_check_run_vpn_route_with_ru_country(): void {
    $io = _sc_io([
        'explain' => fn(string $d): array => [
            'input' => $d, 'kind' => 'domain', 'rule' => 'domain:example.com', 'source' => 'manual',
            'target' => 'srv-1', 'target_name' => 'Мой сервер', 'in_vpn_set' => true,
        ],
        'in_vpn_set' => fn(string $kind, string $input): ?bool => true,
        'fetch' => fn(): array => [
            'youtube' => '<html>ytcfg.set({"INNERTUBE_CONTEXT":{"client":{"countryCode":"RU"}}});</html>',
            'trace'   => "ip=203.0.113.9\nloc=RU\n",
        ],
        'connections' => fn(): array => [
            ['dst' => '1.1.1.1', 'route' => 'leak', 'proto' => 'udp', 'dport' => 443],
        ],
    ]);
    $r = site_check_run('example.com', $io);
    eq($r['via'], 'vpn', 'explain target is a server id, not direct -> via vpn');
    eq($r['google_country'], 'RU', 'country parsed from injected youtube html');
    eq($r['exit_country'], 'RU', 'country parsed from injected cf trace');
    eq($r['quic_leaks'], 1, 'udp/443 leak to a resolved ip counted');
    eq($r['explain']['target'], 'srv-1', 'route_explain result passed through untouched');
}

function test_site_check_run_proxy_target_counts_as_vpn(): void {
    $io = _sc_io(['explain' => fn(string $d): array => [
        'input' => $d, 'kind' => 'domain', 'rule' => null, 'source' => null,
        'target' => 'proxy', 'target_name' => 'VPN (активный сервер)', 'in_vpn_set' => null,
    ]]);
    $r = site_check_run('example.com', $io);
    eq($r['via'], 'vpn', 'any non-direct target counts as vpn');
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
