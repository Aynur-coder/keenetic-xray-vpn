<?php
declare(strict_types=1);

// Child process for memory_test.php, run as `php -n -d memory_limit=8M memory_child.php
// <fixture dir>`: runs connections_collect() and site_check_run() over the generated
// fixtures with the production readers (file_tail_lines() for the querylog/access.log
// tails, shell_lines_timeout() piping the conntrack table through `cat`), then prints
// one JSON object with the results and memory_get_peak_usage(true). A memory fatal
// prints PHP's own error instead, which the parent reports as "not JSON".

$lib = __DIR__ . '/../../../src/web/lib';
require_once "$lib/system.php";
require_once "$lib/connections.php";
require_once "$lib/sitecheck.php";

$dir = $argv[1] ?? '';

try {
    $io = [
        'conntrack'  => fn(float $t): ?iterable
            => shell_lines_timeout('exec cat ' . escapeshellarg("$dir/conntrack.txt"), $t),
        'ipset'      => fn(string $script, float $t): ?string => '',
        'access_log' => fn(): iterable
            => file_tail_lines("$dir/access.log", CONNECTIONS_ACCESS_TAIL),
        'querylog'   => fn(): iterable
            => file_tail_lines("$dir/querylog.json", CONNECTIONS_QUERYLOG_TAIL),
        'server_names' => [],
        'device_names' => [],
    ];
    // A generous budget: the point here is memory, not a slow CI machine's clock.
    $collect = fn(int $limit): array
        => connections_collect($limit, $io, fn(): float => microtime(true), 30.0);

    $conn = $collect(CONNECTIONS_DEFAULT_LIMIT);
    if (isset($conn['error'])) throw new RuntimeException($conn['error']);
    $rows = $conn['connections'];

    $site = site_check_run('cdn1.edge1.example-service.com', [
        'resolve_ips'   => fn(string $d): array => ['104.16.0.3'],
        'ip_in_vpn_set' => fn(?string $ip): ?bool => false,
        'explain'       => fn(string $d, callable $inVpnSet): array => ['target' => 'direct'],
        'active_id'     => fn(): ?string => null,
        'server_name'   => fn(string $id): ?string => null,
        'probe_server'  => fn(string $id): array => ['error' => 'not probed here'],
        // A youtube.com-sized page (~1 MB) with the country marker near the end, as the
        // real direct fetch returns it.
        'fetch_direct'  => fn(): array => ['youtube' => str_repeat('<div>x</div>', 87000)
            . '"countryCode":"RU"', 'trace' => "ip=95.105.78.232\nloc=RU\n"],
        'connections'   => fn(): array => $collect(CONNECTIONS_MAX_LIMIT)['connections'] ?? [],
    ]);

    echo json_encode([
        'connections_total' => $conn['total'],
        'connections_rows' => count($rows),
        'connections_with_domain' => count(array_filter($rows, fn(array $r): bool
            => $r['domain'] !== null)),
        'connections_with_server' => count(array_filter($rows, fn(array $r): bool
            => $r['server'] !== null)),
        'site_check_ok' => is_int($site['direct_flows']) && $site['google_country'] === 'RU',
        'peak_real' => memory_get_peak_usage(true),
        'peak' => memory_get_peak_usage(),
    ]), "\n";
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e) . ': ' . $e->getMessage()]), "\n";
}
