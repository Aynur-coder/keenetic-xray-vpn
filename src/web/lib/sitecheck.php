<?php
declare(strict_types=1);
// "Site check" diagnostic: for one domain, shows which IPs it resolves to,
// whether the active routing sends it through the VPN or direct
// (route_explain(), reused from lib/routing.php via the caller's injected
// 'explain'), what Google/Cloudflare think the exit country is when fetched
// through Xray's own socks inbound (127.0.0.1:1081, so the real routing
// rules apply), and how many recent flows to that domain's IPs went direct
// or leaked as QUIC (from one connections_collect() snapshot, lib/connections.php).
//
// Also home to the `logs?source=` whitelist: which shell command reads each
// named log source, so an unknown source is rejected before anything reaches
// the shell.
//
// Pure logic only: no globals, no file/network I/O beyond what's passed in
// via $io, so this file can be require_once'd standalone from tests. The API
// wires real nslookup/curl/connections_collect() calls into $io.

require_once __DIR__ . '/probe.php'; // parse_youtube_country(), parse_cf_trace()

// Desktop Chrome UA for the google_country fetch — youtube.com serves a
// different (marker-free) page to some mobile/bot UAs.
const SITE_CHECK_DESKTOP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

// ============================================================================
// nslookup parsing (pure)
// ============================================================================

// BusyBox `nslookup <host> 127.0.0.1` output: "Server:"/"Address 1:" for the
// resolver itself first, then a "Name:"/"Address N:" block per answer. Only
// addresses inside a "Name:" block are real answers for the queried host —
// the resolver's own address (127.0.0.1, printed before the first "Name:"
// line) is never included. Collects both A and AAAA answers, deduplicated,
// in the order they appear.
function parse_nslookup_ips(string $out): array {
    $ips = [];
    $seenName = false;
    foreach (explode("\n", $out) as $ln) {
        $ln = trim($ln);
        if (stripos($ln, 'Name:') === 0) { $seenName = true; continue; }
        if (!$seenName) continue;
        if (!preg_match('/^Address\s*\d*:\s*(\S+)/i', $ln, $m)) continue;
        if (filter_var($m[1], FILTER_VALIDATE_IP) !== false) $ips[$m[1]] = true;
    }
    return array_keys($ips);
}

// ============================================================================
// site_check
// ============================================================================

// Flows from one connections_collect() snapshot (lib/connections.php's
// 'connections' rows) whose destination is one of the site's resolved IPs:
// direct_flows — routed 'direct' (bypassed the VPN outright); quic_leaks —
// routed 'leak' as udp/443 specifically (a QUIC flow that got past the
// redirect — the leak bucket also covers tcp bypasses, which aren't QUIC).
function site_check_count_flows(array $connections, array $ips): array {
    $ipSet = array_flip($ips);
    $direct = 0;
    $quic = 0;
    foreach ($connections as $c) {
        if (!isset($ipSet[$c['dst'] ?? ''])) continue;
        $route = $c['route'] ?? '';
        if ($route === 'direct') $direct++;
        $isQuic = $route === 'leak' && ($c['proto'] ?? '') === 'udp' && ($c['dport'] ?? 0) === 443;
        if ($isQuic) $quic++;
    }
    return ['direct_flows' => $direct, 'quic_leaks' => $quic];
}

// The whole `site_check` request, with every I/O step injected so this stays
// testable without touching the network or the router.
//
// $io:
//   resolve_ips(string $domain): string[] — the domain's current A/AAAA answers
//   in_vpn_set(string $kind, string $input): ?bool — route_explain()'s own probe
//     (route_in_vpn_set() in production), called here with kind='domain' so the
//     result reflects the domain, not any one resolved IP
//   explain(string $domain): array — route_explain()'s result (target_name filled),
//     built by the caller from the SAME domain/ip buckets the config generator uses
//   fetch(): array{youtube: ?string, trace: ?string} — raw bodies fetched THROUGH
//     Xray's socks inbound (so routing applies); null means the fetch failed/timed out
//   connections(): array — one connections_collect() snapshot's 'connections' rows
function site_check_run(string $domain, array $io): array {
    $ips = $io['resolve_ips']($domain);
    $inVpnSet = $io['in_vpn_set']('domain', $domain);
    $explain = $io['explain']($domain);
    $via = ($explain['target'] ?? 'direct') === 'direct' ? 'direct' : 'vpn';

    $probe = $io['fetch']();
    $googleCountry = !empty($probe['youtube']) ? parse_youtube_country($probe['youtube']) : null;
    $trace = !empty($probe['trace']) ? parse_cf_trace($probe['trace']) : [];
    $exitCountry = ($trace['loc'] ?? '') !== '' ? $trace['loc'] : null;

    $flows = site_check_count_flows($io['connections'](), $ips);

    return [
        'domain'         => $domain,
        'ips'            => $ips,
        'in_vpn_set'     => $inVpnSet,
        'explain'        => $explain,
        'via'            => $via,
        'google_country' => $googleCountry,
        'exit_country'   => $exitCountry,
        'direct_flows'   => $flows['direct_flows'],
        'quic_leaks'     => $flows['quic_leaks'],
    ];
}

// ============================================================================
// logs?source= whitelist
// ============================================================================

// source => log file path. Deliberately NOT including 'manager', which has
// no single file (it reads the syslog ring buffer via logread) — handled as
// its own case in log_source_command() below.
const LOG_SOURCE_FILES = [
    'xray_error'  => '/opt/var/log/xray/error.log',
    'xray_access' => '/opt/var/log/xray/access.log',
    'adguard'     => '/opt/var/log/AdGuardHome.log',
    'update'      => '/opt/var/log/xray/update.log',
];

// Builds the shell command that prints the last $lines lines of $source, or
// null when $source isn't in the whitelist — checked here, before anything
// reaches the shell, so an unknown/malicious source value can never be
// interpolated into a command. $lines is floored to 1 and always passed
// through escapeshellarg.
//
// 'manager' has no log file of its own: xray-manager.sh logs via
// `logger -t xray-mgr`, landing in the syslog ring buffer BusyBox exposes
// through `logread`. Not every BusyBox build's logread supports the `-e`
// filter, so the command tries `logread -e xray-mgr` first and falls back to
// piping the unfiltered log through `grep xray-mgr` if that produces nothing.
function log_source_command(string $source, int $lines): ?string {
    $n = escapeshellarg((string)max(1, $lines));
    if ($source === 'manager') {
        return "(logread -e xray-mgr 2>/dev/null || logread 2>/dev/null | grep xray-mgr)"
            . " | tail -n $n";
    }
    $file = LOG_SOURCE_FILES[$source] ?? null;
    if ($file === null) return null;
    return "tail -n $n " . escapeshellarg($file) . ' 2>/dev/null';
}
