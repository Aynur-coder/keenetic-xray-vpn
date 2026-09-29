<?php
// Per-server TCP ping and the on-demand "country probe" (temporary Xray
// instance dialing out through the real server so the UI can show delay,
// exit IP and which country Google/Cloudflare think the traffic comes from).
//
// Pure logic only: no globals, no header()/session file I/O beyond what's
// passed in, so this file can be require_once'd standalone from tests. The
// two orchestrators (ping_servers_run, server_probe_run) do real process/
// filesystem I/O, but every side effect is overridable via $opt, exactly
// like apply_changes() in apply.php, so tests never touch /opt or the
// network.

require_once __DIR__ . '/links.php';
require_once __DIR__ . '/system.php';

// ============================================================================
// Parsing (pure)
// ============================================================================

// YouTube's homepage embeds ytcfg with the visitor's detected country, e.g.
// `"countryCode":"FI"`. null when the marker isn't present (blocked page,
// unexpected markup, empty body).
function parse_youtube_country(string $html): ?string {
    if (preg_match('/"countryCode":"([A-Z]{2})"/', $html, $m)) return $m[1];
    return null;
}

// Cloudflare's /cdn-cgi/trace responds with one "key=value" pair per line
// (fl=..., ip=..., loc=..., colo=..., ...). Parsed generically so every field
// is available, though callers only rely on 'ip' and 'loc' (the two-letter
// country Cloudflare's edge resolved the connection to).
function parse_cf_trace(string $text): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v);
    }
    return $out;
}

// curl's `-w '%{time_connect}'` / `-w '%{time_total}'` print seconds with
// fractional precision ("0.021340"), or "0.000000" when the metric was never
// reached (connection failed before that phase). Per the router ruling,
// exactly-zero (or empty/non-numeric) means unreachable, not "0 ms".
function probe_time_to_ms(?string $s): ?int {
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '' || !is_numeric($s)) return null;
    $f = (float)$s;
    if ($f <= 0) return null;
    return (int)round($f * 1000);
}

// ============================================================================
// ping_servers
// ============================================================================

// One BusyBox-sh-compatible script that backgrounds up to $maxParallel curls
// at a time (each appending "id time_connect" to $resultsFile), waiting
// between batches so no more than $maxParallel run concurrently, and once
// more at the end for the last (possibly partial) batch. No arrays, no
// fractional sleep — only what BusyBox ash supports.
function ping_build_script(
    array $targets, string $resultsFile, int $maxParallel = 8,
    string $curlBin = '/opt/bin/curl', int $connectTimeout = 3, int $maxTime = 4
): string {
    $lines = [];
    $count = 0;
    $resultsFileQ = escapeshellarg($resultsFile);
    foreach ($targets as $t) {
        $url = 'http://' . $t['host'] . ':' . $t['port'] . '/';
        $idQ = escapeshellarg((string)$t['id']);
        $urlQ = escapeshellarg($url);
        $lines[] = '(t=$(' . $curlBin . ' -s -o /dev/null --connect-timeout ' . $connectTimeout
            . ' -m ' . $maxTime . " -w '%{time_connect}' $urlQ 2>/dev/null); echo $idQ \"\$t\" >> $resultsFileQ) &";
        $count++;
        if ($maxParallel > 0 && $count % $maxParallel === 0) $lines[] = 'wait';
    }
    if ($count > 0 && ($maxParallel <= 0 || $count % $maxParallel !== 0)) $lines[] = 'wait';
    return implode("\n", $lines) . "\n";
}

// Parses ping_build_script()'s output file ("id time_connect" lines, one per
// target) into id => ms|null, defaulting every id in $ids to null first so a
// target curl never got to run for (killed script, crash) still comes back
// as unreachable instead of being silently dropped.
function ping_parse_results(string $raw, array $ids): array {
    $out = array_fill_keys($ids, null);
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = preg_split('/\s+/', $line, 2);
        $id = $parts[0] ?? '';
        if ($id === '' || !array_key_exists($id, $out)) continue;
        $out[$id] = probe_time_to_ms(trim($parts[1] ?? ''));
    }
    return $out;
}

function _ping_default_options(): array {
    return [
        'tmp_file'        => '/opt/tmp/xray-ping-work-' . getmypid() . '.txt',
        'max_parallel'    => 8,
        'curl_bin'        => '/opt/bin/curl',
        'connect_timeout' => 3,
        'max_time'        => 4,
        // Runs the built script; default backgrounds it via the real shell.
        // Tests replace this with a fake that writes canned lines to the
        // results file instead of spawning curl.
        'run'             => function (string $script): void { shell_exec($script); },
    ];
}

/**
 * Pings every target and returns id => ms|null for all of them.
 *
 * $targets: list of ['id' => string, 'host' => string, 'port' => int,
 * 'skip' => bool]. A target is resolved to null without ever running curl
 * when 'skip' is true (hysteria2 is UDP/QUIC — a TCP connect measures
 * nothing meaningful) or its host/port couldn't be parsed.
 *
 * $opt: see _ping_default_options().
 */
function ping_servers_run(array $targets, array $opt = []): array {
    $opt += _ping_default_options();

    $results = [];
    $pingable = [];
    foreach ($targets as $t) {
        $host = (string)($t['host'] ?? '');
        $port = (int)($t['port'] ?? 0);
        if (!empty($t['skip']) || $host === '' || $port <= 0) {
            $results[(string)$t['id']] = null;
        } else {
            $pingable[] = ['id' => (string)$t['id'], 'host' => $host, 'port' => $port];
        }
    }
    if (empty($pingable)) return $results;

    $tmp = $opt['tmp_file'];
    @file_put_contents($tmp, '');
    $script = ping_build_script(
        $pingable, $tmp, (int)$opt['max_parallel'], $opt['curl_bin'],
        (int)$opt['connect_timeout'], (int)$opt['max_time']
    );
    ($opt['run'])($script);

    $raw = (string)(@file_get_contents($tmp) ?: '');
    @unlink($tmp);

    return $results + ping_parse_results($raw, array_column($pingable, 'id'));
}

// ============================================================================
// server_probe
// ============================================================================

// The temporary Xray config: a loopback-only socks inbound plus the single
// outbound under test, tagged 'probe' by the caller
// (build_outbound_from_link($link, 'probe')).
function probe_build_config(array $outbound, int $port = 10899): array {
    return [
        'inbounds'  => [['port' => $port, 'listen' => '127.0.0.1', 'protocol' => 'socks']],
        'outbounds' => [$outbound],
    ];
}

function _probe_default_options(): array {
    return [
        'xray_bin'        => 'xray',
        'tmp_config'      => '/opt/tmp/xray-probe-' . getmypid() . '.json',
        'socks_port'      => 10899,
        'sleep_fn'        => 'sleep',
        'sleep_seconds'   => 2,
        'curl_bin'        => '/opt/bin/curl',
        'connect_timeout' => 3,
        'max_time'        => 5,
        'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        // Starts the temp Xray in the background and returns its pid (or null
        // if it couldn't be started). Tests replace this to avoid spawning a
        // real Xray.
        'start_cmd' => function (string $bin, string $conf): ?int {
            $out = shell_exec(escapeshellarg($bin) . ' run -config ' . escapeshellarg($conf)
                . ' > /dev/null 2>&1 & echo $!');
            $pid = (int)trim((string)$out);
            return $pid > 0 ? $pid : null;
        },
        // Kills only the specific pid this probe started — never killall xray:
        // that would take down the real, running Xray instance too.
        'kill_cmd' => function (int $pid): void {
            shell_exec('kill ' . $pid . ' 2>/dev/null');
        },
        // Runs one curl command and returns its stdout, or null/''. Tests
        // replace this with a fake keyed on the target URL instead of
        // actually dialing out through a live proxy.
        'curl_run' => 'shell_run',
    ];
}

/**
 * Spins up a temporary Xray instance for $link's outbound, measures delay
 * and exit identity through it, and tears it down again on every path.
 *
 * @return array{ok: bool, delay_ms: ?int, exit_ip: ?string,
 *   google_country: ?string, exit_country: ?string, error: ?string}
 */
function server_probe_run(string $link, array $opt = []): array {
    $opt += _probe_default_options();

    $outbound = build_outbound_from_link($link, 'probe');
    if (!$outbound) {
        return _probe_error('Сервер не поддерживается для проверки');
    }

    $conf = $opt['tmp_config'];
    $config = probe_build_config($outbound, (int)$opt['socks_port']);
    if (@file_put_contents($conf, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        return _probe_error('Не удалось записать временный конфиг');
    }

    $pid = ($opt['start_cmd'])($opt['xray_bin'], $conf);
    try {
        ($opt['sleep_fn'])((int)$opt['sleep_seconds']);

        $proxy = 'socks5h://127.0.0.1:' . (int)$opt['socks_port'];
        $run = $opt['curl_run'];
        $curlBin = $opt['curl_bin'];
        $ct = (int)$opt['connect_timeout'];
        $mt = (int)$opt['max_time'];
        $base = "$curlBin -s -x " . escapeshellarg($proxy) . " --connect-timeout $ct -m $mt ";

        $delayRaw = $run($base . "-o /dev/null -w '%{time_total}' " . escapeshellarg('https://www.gstatic.com/generate_204'));
        $delay_ms = probe_time_to_ms($delayRaw);
        if ($delay_ms === null) {
            return _probe_error('Сервер не отвечает через прокси');
        }

        $exit_ip = trim((string)$run($base . escapeshellarg('https://api.ipify.org')));
        $exit_ip = $exit_ip !== '' ? $exit_ip : null;

        $ytHtml = (string)$run($base . '-A ' . escapeshellarg($opt['user_agent']) . ' '
            . escapeshellarg('https://www.youtube.com/'));
        $google_country = parse_youtube_country($ytHtml);

        $traceText = (string)$run($base . escapeshellarg('https://www.cloudflare.com/cdn-cgi/trace'));
        $trace = parse_cf_trace($traceText);
        $exit_country = ($trace['loc'] ?? '') !== '' ? $trace['loc'] : null;

        return [
            'ok' => true, 'delay_ms' => $delay_ms, 'exit_ip' => $exit_ip,
            'google_country' => $google_country, 'exit_country' => $exit_country, 'error' => null,
        ];
    } finally {
        if ($pid !== null) ($opt['kill_cmd'])($pid);
        @unlink($conf);
    }
}

function _probe_error(string $message): array {
    return [
        'ok' => false, 'delay_ms' => null, 'exit_ip' => null,
        'google_country' => null, 'exit_country' => null, 'error' => $message,
    ];
}
