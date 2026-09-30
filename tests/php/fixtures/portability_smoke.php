<?php
declare(strict_types=1);

// Smoke-loads every real src/web/lib/*.php file and exercises three
// representative, previously filter_var()-dependent functions, run under the
// exact -d disable_functions set portability_test.php uses to emulate the
// router's missing filter/ctype/mbstring/intl extensions (see
// .superpowers/sdd/2026-09-30-ui-overhaul-phase3-5/implementer-rules.md).
// Invoked as its own process (php -d disable_functions=... this-file), not
// require_once'd from the test runner, so a fatal here can't take down the
// rest of the suite.
//
// Prints SMOKE_OK and exits 0 on success. Prints SMOKE_FAIL: <message> and
// exits 1 on any Throwable, including "Call to undefined function" from a
// disabled function (PHP raises that as a catchable \Error, not a bare
// fatal, so try/catch(Throwable) is enough — no need to parse stderr).

$libDir = __DIR__ . '/../../../src/web/lib';

try {
    $files = glob($libDir . '/*.php');
    if ($files === false || $files === []) {
        throw new RuntimeException("no lib files found under $libDir");
    }
    sort($files);
    foreach ($files as $file) {
        // Stray filesystem "conflicted copy" duplicates (e.g. "system 2.php"):
        // not real source, see portability_test.php's own filter for the same.
        if (preg_match('/ \d+\.php$/', basename($file))) continue;
        require_once $file;
    }

    // normalize_rule_input() (lib/rules.php): domain and IPv4 literal.
    $domain = normalize_rule_input('Example.COM');
    if (($domain['kind'] ?? null) !== 'domain' || $domain['value'] !== 'example.com') {
        throw new RuntimeException('normalize_rule_input(domain) failed: ' . var_export($domain, true));
    }
    $ip = normalize_rule_input('192.168.1.10');
    if (($ip['kind'] ?? null) !== 'ip' || $ip['value'] !== '192.168.1.10') {
        throw new RuntimeException('normalize_rule_input(ip) failed: ' . var_export($ip, true));
    }

    // route_explain() (lib/routing.php): a domain matching one proxy bucket entry.
    $explain = route_explain(
        'blocked.example',
        ['proxy' => [['token' => 'domain:blocked.example', 'rule' => 'blocked.example', 'source' => 'domains.txt']]],
        ['proxy' => [['token' => '10.0.0.0/8', 'rule' => '10.0.0.0/8', 'source' => 'ips.txt']]],
        fn(string $kind, string $input): ?bool => null
    );
    if (($explain['kind'] ?? null) !== 'domain' || ($explain['target'] ?? null) !== 'proxy') {
        throw new RuntimeException('route_explain() failed: ' . var_export($explain, true));
    }
    $explainIp = route_explain('10.1.2.3', [], ['proxy' => [['token' => '10.0.0.0/8', 'rule' => '10.0.0.0/8', 'source' => 'ips.txt']]],
        fn(string $kind, string $input): ?bool => null);
    if (($explainIp['kind'] ?? null) !== 'ip' || ($explainIp['target'] ?? null) !== 'proxy') {
        throw new RuntimeException('route_explain(ip) failed: ' . var_export($explainIp, true));
    }

    // build_connections() (lib/connections.php): one redirected (vpn) flow via
    // parse_conntrack() on a realistic conntrack -L line.
    $flows = parse_conntrack(
        'tcp      6 431999 ESTABLISHED src=192.168.1.50 dst=93.184.216.34 sport=50111 dport=443 '
        . 'packets=10 bytes=2000 src=192.168.1.1 dst=192.168.1.50 sport=1080 dport=50111 [ASSURED] mark=0 use=1'
    );
    if (count($flows) !== 1) {
        throw new RuntimeException('parse_conntrack() fixture failed: ' . var_export($flows, true));
    }
    $rows = build_connections($flows, ['93.184.216.34' => 'example.com'], [], fn(string $ip): bool => false, []);
    if (count($rows) !== 1 || $rows[0]['route'] !== 'vpn' || $rows[0]['domain'] !== 'example.com') {
        throw new RuntimeException('build_connections() failed: ' . var_export($rows, true));
    }

    echo "SMOKE_OK\n";
    exit(0);
} catch (Throwable $e) {
    echo 'SMOKE_FAIL: ' . $e->getMessage() . "\n";
    exit(1);
}
