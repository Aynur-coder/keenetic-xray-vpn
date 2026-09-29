<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/probe.php';

// --- parse_youtube_country ---------------------------------------------------

function test_parse_youtube_country(): void {
    $html = '<html>...ytcfg.set({"INNERTUBE_CONTEXT":{"client":{"countryCode":"FI","hl":"en"}}});...</html>';
    eq(parse_youtube_country($html), 'FI', 'countryCode extracted from ytcfg blob');
    eq(parse_youtube_country('<html>no marker here</html>'), null, 'no match returns null');
    eq(parse_youtube_country(''), null, 'empty body returns null');
}

// --- parse_cf_trace -----------------------------------------------------------

function test_parse_cf_trace(): void {
    $text = "fl=1f2\nh=www.cloudflare.com\nip=203.0.113.5\nts=1600000000.123\n"
        . "visit_scheme=https\nuag=curl/8.0\ncolo=FRA\nloc=DE\ntls=TLSv1.3\n";
    $trace = parse_cf_trace($text);
    eq($trace['ip'], '203.0.113.5', 'ip extracted');
    eq($trace['loc'], 'DE', 'loc (country) extracted');
    eq($trace['colo'], 'FRA', 'other fields parsed too');
    eq(parse_cf_trace(''), [], 'empty text yields empty array');
    eq(parse_cf_trace("not a kv line\n\nloc=RU\n")['loc'], 'RU', 'lines without = are skipped, not fatal');
}

// --- probe_time_to_ms ---------------------------------------------------------

function test_probe_time_to_ms(): void {
    eq(probe_time_to_ms('0.123456'), 123, 'seconds converted and rounded to ms');
    eq(probe_time_to_ms('0.000000'), null, 'exactly zero is unreachable');
    eq(probe_time_to_ms('0'), null, 'zero (no decimals) is unreachable');
    eq(probe_time_to_ms(''), null, 'empty string is unreachable');
    eq(probe_time_to_ms(null), null, 'null is unreachable');
    eq(probe_time_to_ms('not-a-number'), null, 'garbage curl output is unreachable');
    eq(probe_time_to_ms('-0.5'), null, 'negative is unreachable');
}

// --- ping_build_script ---------------------------------------------------------

function test_ping_build_script_batches_by_max_parallel(): void {
    $targets = [
        ['id' => 'a', 'host' => 'h1.example', 'port' => 443],
        ['id' => 'b', 'host' => 'h2.example', 'port' => 8443],
        ['id' => 'c', 'host' => 'h3.example', 'port' => 80],
    ];
    $script = ping_build_script($targets, '/tmp/results.txt', 2, '/opt/bin/curl', 3, 4);

    eq(substr_count($script, ') &'), 3, 'one backgrounded job per target');
    // 3 targets, batch size 2: job, job, wait (batch boundary), job, wait (trailing partial batch).
    $lines = explode("\n", rtrim($script, "\n"));
    eq(count($lines), 5, '3 jobs + 2 waits, one line each');
    eq($lines[2], 'wait', 'wait after the first batch of 2');
    eq($lines[4], 'wait', 'trailing wait for the last partial batch');
    eq(strpos($script, 'h1.example:443') !== false, true, 'host:port url built for each target');
    eq(strpos($script, "--connect-timeout 3 -m 4") !== false, true, 'timeouts passed through');
    eq(strpos($script, "'/tmp/results.txt'") !== false, true, 'results file path is shell-quoted');
    eq(strpos($script, "'a'") !== false, true, 'target id is shell-quoted');
}

function test_ping_build_script_exact_multiple_of_batch_no_double_wait(): void {
    $targets = [
        ['id' => 'a', 'host' => 'h1', 'port' => 1],
        ['id' => 'b', 'host' => 'h2', 'port' => 2],
    ];
    $script = ping_build_script($targets, '/tmp/results.txt', 2, '/opt/bin/curl', 3, 4);
    // Exactly one batch of 2: the batch-boundary wait already covers it, no extra trailing wait.
    eq(substr_count($script, 'wait'), 1, 'no duplicate wait when count is an exact multiple of max_parallel');
}

function test_ping_build_script_empty_targets(): void {
    eq(ping_build_script([], '/tmp/results.txt'), "\n", 'no targets produces an empty (no-op) script');
}

// --- ping_parse_results ---------------------------------------------------------

function test_ping_parse_results(): void {
    $raw = "a 0.021340\nb 0.000000\nc \n";
    $out = ping_parse_results($raw, ['a', 'b', 'c', 'd']);
    eq($out['a'], 21, 'reachable target converted to ms');
    eq($out['b'], null, 'time_connect 0 is unreachable');
    eq($out['c'], null, 'empty time (curl produced nothing) is unreachable');
    eq($out['d'], null, 'a target with no line at all defaults to null, not missing');
}

function test_ping_parse_results_ignores_unknown_ids(): void {
    // A stray/duplicate line for an id we didn't ask about must not leak into the map.
    $out = ping_parse_results("a 0.05\nghost 0.05\n", ['a']);
    eq($out, ['a' => 50], 'unknown id lines are ignored');
}

// --- ping_servers_run (orchestrator, fully injected) -----------------------------

function test_ping_servers_run_skips_marked_targets_without_running_curl(): void {
    $ran = false;
    $targets = [
        ['id' => 'hy', 'host' => 'hy.example', 'port' => 443, 'skip' => true],
        ['id' => 'noaddr', 'host' => '', 'port' => 0],
    ];
    $opt = [
        'tmp_file' => sys_get_temp_dir() . '/probe_test_ping_' . bin2hex(random_bytes(4)) . '.txt',
        'run' => function (string $script) use (&$ran): void { $ran = true; },
    ];
    $result = ping_servers_run($targets, $opt);
    eq($result, ['hy' => null, 'noaddr' => null], 'skip flag and empty host/port both resolve to null');
    eq($ran, false, 'curl runner never invoked when nothing is pingable');
}

function test_ping_servers_run_uses_injected_runner(): void {
    $tmp = sys_get_temp_dir() . '/probe_test_ping_' . bin2hex(random_bytes(4)) . '.txt';
    $targets = [
        ['id' => 'a', 'host' => 'h1.example', 'port' => 443],
        ['id' => 'hy', 'host' => 'hy.example', 'port' => 443, 'skip' => true],
    ];
    $opt = [
        'tmp_file' => $tmp,
        // Simulate what the real script would have written, instead of calling curl.
        'run' => function (string $script) use ($tmp): void {
            file_put_contents($tmp, "a 0.100000\n");
        },
    ];
    $result = ping_servers_run($targets, $opt);
    eq($result, ['hy' => null, 'a' => 100], 'pinged target measured, skipped target forced null');
    eq(file_exists($tmp), false, 'work file cleaned up after the run');
}

// --- probe_build_config ---------------------------------------------------------

function test_probe_build_config(): void {
    $outbound = ['tag' => 'probe', 'protocol' => 'vless', 'settings' => []];
    $config = probe_build_config($outbound);
    eq($config['inbounds'], [['port' => 10899, 'listen' => '127.0.0.1', 'protocol' => 'socks']],
        'default socks inbound on 10899/loopback');
    eq($config['outbounds'], [$outbound], 'outbound passed through unchanged');

    $config2 = probe_build_config($outbound, 12345);
    eq($config2['inbounds'][0]['port'], 12345, 'port overridable');
}

// --- server_probe_run (orchestrator, fully injected) -----------------------------

function _probe_vless_link(): string {
    return 'vless://11111111-1111-1111-1111-111111111111@vpn.example:443'
        . '?security=reality&sni=example.com&fp=chrome&pbk=PUBKEY&sid=abcd#Test';
}

function test_server_probe_run_unsupported_link_never_starts_xray(): void {
    $started = false;
    $tmp = sys_get_temp_dir() . '/probe_test_cfg_' . bin2hex(random_bytes(4)) . '.json';
    $opt = [
        'tmp_config' => $tmp,
        'start_cmd' => function (string $bin, string $conf) use (&$started): ?int {
            $started = true; return 1234;
        },
    ];
    // vmess:// has no outbound builder — build_outbound_from_link() returns null for it.
    $vmess = 'vmess://' . base64_encode(json_encode(['add' => 'v.example', 'port' => 443]));
    $row = server_probe_run($vmess, $opt);

    eq($row['ok'], false, 'unsupported link is not ok');
    eq($row['error'], 'Сервер не поддерживается для проверки', 'unsupported link error message');
    eq($row['delay_ms'], null, 'no delay measured');
    eq($started, false, 'xray never started for a link with no outbound');
    eq(file_exists($tmp), false, 'no temp config left behind');
}

function test_server_probe_run_success(): void {
    $tmp = sys_get_temp_dir() . '/probe_test_cfg_' . bin2hex(random_bytes(4)) . '.json';
    $killedPid = null;
    $sleptSeconds = null;
    $seenCmds = [];
    $configAtStart = null;

    $opt = [
        'tmp_config' => $tmp,
        'start_cmd' => function (string $bin, string $conf) use ($tmp, &$configAtStart): ?int {
            eq($conf, $tmp, 'start_cmd gets the temp config path');
            $configAtStart = json_decode((string)file_get_contents($conf), true);
            return 4321;
        },
        'kill_cmd' => function (int $pid) use (&$killedPid): void { $killedPid = $pid; },
        'sleep_fn' => function (int $s) use (&$sleptSeconds): void { $sleptSeconds = $s; },
        'curl_run' => function (string $cmd) use (&$seenCmds): string {
            $seenCmds[] = $cmd;
            if (strpos($cmd, 'generate_204') !== false) return '0.150000';
            if (strpos($cmd, 'api.ipify.org') !== false) return "203.0.113.9\n";
            if (strpos($cmd, 'youtube.com') !== false) return '{"countryCode":"FI"}';
            if (strpos($cmd, 'cdn-cgi/trace') !== false) return "ip=203.0.113.9\nloc=DE\n";
            return '';
        },
    ];

    $row = server_probe_run(_probe_vless_link(), $opt);

    eq($row, [
        'ok' => true, 'delay_ms' => 150, 'exit_ip' => '203.0.113.9',
        'google_country' => 'FI', 'exit_country' => 'DE', 'error' => null,
    ], 'full successful probe row (google_country and exit_country from distinct sources)');

    eq($sleptSeconds, 2, 'sleeps 2s after starting the temp xray');
    eq($killedPid, 4321, 'temp xray killed by its own pid, not killall');
    eq(file_exists($tmp), false, 'temp config deleted after the run');
    eq(count($seenCmds), 4, 'exactly the four documented probes were made');
    eq(strpos($seenCmds[0], 'socks5h://127.0.0.1:10899') !== false, true, 'proxied through the temp socks inbound');
    eq($configAtStart['inbounds'][0]['protocol'], 'socks', 'temp config has a socks inbound');
    eq($configAtStart['outbounds'][0]['tag'], 'probe', 'temp config outbound tagged probe');
}

function test_server_probe_run_cleans_up_when_delay_fails(): void {
    $tmp = sys_get_temp_dir() . '/probe_test_cfg_' . bin2hex(random_bytes(4)) . '.json';
    $killedPid = null;
    $calls = 0;
    $opt = [
        'tmp_config' => $tmp,
        'start_cmd'  => fn(string $bin, string $conf): ?int => 999,
        'kill_cmd'   => function (int $pid) use (&$killedPid): void { $killedPid = $pid; },
        'sleep_fn'   => function (int $s): void {},
        'curl_run'   => function (string $cmd) use (&$calls): string { $calls++; return '0.000000'; },
    ];

    $row = server_probe_run(_probe_vless_link(), $opt);

    eq($row['ok'], false, 'unreachable server is not ok');
    eq($row['error'], 'Сервер не отвечает через прокси', 'delay failure message');
    eq($row['exit_ip'], null, 'no further probes attempted once delay fails');
    eq($calls, 1, 'stops after the first (delay) curl call');
    eq($killedPid, 999, 'temp xray still killed even when the probe itself failed');
    eq(file_exists($tmp), false, 'temp config still cleaned up on failure');
}

function test_server_probe_run_kills_xray_even_if_curl_throws(): void {
    // A crash mid-probe must not leak the temp Xray process or its config —
    // finally{} must run regardless of how the try block exits.
    $tmp = sys_get_temp_dir() . '/probe_test_cfg_' . bin2hex(random_bytes(4)) . '.json';
    $killedPid = null;
    $opt = [
        'tmp_config' => $tmp,
        'start_cmd'  => fn(string $bin, string $conf): ?int => 555,
        'kill_cmd'   => function (int $pid) use (&$killedPid): void { $killedPid = $pid; },
        'sleep_fn'   => function (int $s): void {},
        'curl_run'   => function (string $cmd): string { throw new RuntimeException('boom'); },
    ];

    try {
        server_probe_run(_probe_vless_link(), $opt);
        throw new RuntimeException('expected server_probe_run to rethrow');
    } catch (RuntimeException $e) {
        eq($e->getMessage(), 'boom', 'original exception propagates');
    }
    eq($killedPid, 555, 'temp xray killed despite the exception');
    eq(file_exists($tmp), false, 'temp config cleaned up despite the exception');
}
