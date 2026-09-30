<?php
declare(strict_types=1);
// route_explain(): which routing rule and outbound a domain or IP literal
// resolves to.
//
// The domain/IP bucket builders here (domain_rule_entries()/ip_rule_entries()
// and their thin wrappers all_domains_with_target()/ip_buckets_with_target())
// are the SAME code generate_xray_config() uses to build its routing rules —
// moved here from api.php so an explanation can never drift from what
// config.json actually contains.
//
// Pure logic only: no globals, no file I/O beyond what's passed in (v2fly
// list files are read from the directory path given, same as the generator
// did), so this file can be require_once'd standalone from tests.

require_once __DIR__ . '/rules.php'; // bare_domain()

// Resolve a rule key ('domain:x'/'ip:x'/'list:x') from the sparse rule_targets
// map to an outbound tag. proxy/missing -> active; direct -> 'direct'; pinned
// server present -> its tag; pinned server missing/disabled -> fall back to
// active. (Moved here from api.php; still the single implementation.)
function resolve_target($key, $targets, $id_to_tag, $active_tag) {
    $t = $targets[$key] ?? 'proxy';
    if ($t === 'proxy' || $t === '') return $active_tag;
    if ($t === 'direct') return 'direct';
    if (isset($id_to_tag[$t])) return $id_to_tag[$t];
    return $active_tag;
}

/**
 * Core domain-rule builder: one entry per routed domain, grouped by outbound
 * tag and carrying enough provenance for route_explain() to report which
 * literal rule matched — a v2fly-list entry reports 'list:<name>' as its
 * rule even though the token it contributes to the generated config
 * collapses to a plain "domain:<bare>" suffix match (matching what
 * generate_xray_config() actually emits, so the explanation stays truthful
 * to the real routing rule, not a hypothetically-more-precise one).
 *
 * Precedence: v2fly lists are authoritative. A manual domain only overrides
 * a v2fly-covered host when it carries an explicit override (specific server
 * or "direct"); a plain "proxy" manual domain that duplicates a v2fly list
 * is redundant and the v2fly list wins — identical to the old
 * all_domains_with_target()'s precedence rule.
 *
 * @param string[] $domainLines lines_read($DOMAINS_FILE)
 * @param array[] $githubLists json_read($GITHUB_LISTS_FILE)
 * @return array<string, array{token:string, rule:string, source:string}[]> outbound tag => entries
 */
function domain_rule_entries(
    array $domainLines, array $githubLists, string $v2flyListsDir,
    array $targets, array $id_to_tag, string $active_tag
): array {
    // 1) v2fly domains -> their list's target tag + list name (bare host => info)
    $v2flyMap = [];
    foreach ($githubLists as $l) {
        if (empty($l['enabled']) || ($l['source'] ?? '') !== 'v2fly' || empty($l['name'])) continue;
        $f = "$v2flyListsDir/{$l['name']}.txt";
        if (!file_exists($f)) continue;
        $tag = resolve_target('list:' . $l['name'], $targets, $id_to_tag, $active_tag);
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $d) {
            $d = trim($d);
            if ($d === '' || $d[0] === '#') continue;
            $bare = strtolower(bare_domain($d));
            if ($bare === '' || isset($v2flyMap[$bare])) continue;
            $v2flyMap[$bare] = ['tag' => $tag, 'list' => $l['name']];
        }
    }

    $buckets = [];
    $seen = [];
    // 2) Manual domains: kept only if NOT redundant with v2fly, or if they carry an override
    foreach ($domainLines as $token) {
        $bare = strtolower(bare_domain($token));
        if ($bare === '' || isset($seen[$bare])) continue;
        $ov = $targets['domain:' . $bare] ?? 'proxy';
        $isOverride = ($ov !== 'proxy' && $ov !== '');
        if (!$isOverride && isset($v2flyMap[$bare])) continue; // v2fly wins; skip (do not mark seen)
        $seen[$bare] = true;
        $tag = resolve_target('domain:' . $bare, $targets, $id_to_tag, $active_tag);
        $buckets[$tag][] = ['token' => $token, 'rule' => $token, 'source' => 'manual'];
    }
    // 3) v2fly domains not overridden by a manual entry
    foreach ($v2flyMap as $bare => $info) {
        if (isset($seen[$bare])) continue;
        $seen[$bare] = true;
        $buckets[$info['tag']][] = [
            'token' => 'domain:' . $bare,
            'rule'  => 'list:' . $info['list'],
            'source' => 'list',
        ];
    }
    return $buckets;
}

// Thin wrapper matching what generate_xray_config()'s routing rules need:
// outbound tag => plain domain tokens, no provenance. Drop-in replacement
// for the old (global-reading) all_domains_with_target().
function all_domains_with_target(
    array $domainLines, array $githubLists, string $v2flyListsDir,
    array $targets, array $id_to_tag, string $active_tag
): array {
    $out = [];
    foreach (domain_rule_entries($domainLines, $githubLists, $v2flyListsDir, $targets, $id_to_tag, $active_tag) as $tag => $entries) {
        foreach ($entries as $e) $out[$tag][] = $e['token'];
    }
    return $out;
}

/**
 * Core IP-rule builder: one entry per static routed IP literal (ips.txt),
 * grouped by outbound tag. Same shape as domain_rule_entries() so both feed
 * route_explain() uniformly. IPs have no list source today, so every entry
 * is 'manual'.
 *
 * @param string[] $ipLines lines_read($IPS_FILE)
 * @return array<string, array{token:string, rule:string, source:string}[]> outbound tag => entries
 */
function ip_rule_entries(array $ipLines, array $targets, array $id_to_tag, string $active_tag): array {
    $buckets = [];
    foreach ($ipLines as $ipv) {
        $tag = resolve_target('ip:' . $ipv, $targets, $id_to_tag, $active_tag);
        $buckets[$tag][] = ['token' => $ipv, 'rule' => $ipv, 'source' => 'manual'];
    }
    return $buckets;
}

// Thin wrapper matching what generate_xray_config()'s routing rules need:
// outbound tag => plain IP tokens, no provenance. Drop-in replacement for
// the inline loop generate_xray_config() used to build $ip_buckets itself.
function ip_buckets_with_target(array $ipLines, array $targets, array $id_to_tag, string $active_tag): array {
    $out = [];
    foreach (ip_rule_entries($ipLines, $targets, $id_to_tag, $active_tag) as $tag => $entries) {
        foreach ($entries as $e) $out[$tag][] = $e['token'];
    }
    return $out;
}

// Converts an outbound tag to the target identifier route_explain() reports:
// 'proxy' for whichever tag is currently active (matches resolve_target()'s
// own "proxy means the active outbound" semantics), 'direct' for the direct
// outbound, or the server id whose tag this is.
function tag_to_target(string $tag, array $id_to_tag, string $active_tag): string {
    if ($tag === $active_tag) return 'proxy';
    if ($tag === 'direct') return 'direct';
    $id = array_search($tag, $id_to_tag, true);
    return $id !== false ? (string)$id : $tag;
}

// Regroups tag => entries[] (as produced by domain_rule_entries()/
// ip_rule_entries(), ideally ksort()ed first to mirror the generated
// config's rule order) into target => entries[] ('proxy'/'direct'/<server
// id>), preserving iteration order so route_explain()'s first-match walk
// still mirrors Xray's first-match rule order.
function regroup_rule_entries_by_target(array $tagBuckets, array $id_to_tag, string $active_tag): array {
    $out = [];
    foreach ($tagBuckets as $tag => $entries) {
        $target = tag_to_target((string)$tag, $id_to_tag, $active_tag);
        foreach ($entries as $e) $out[$target][] = $e;
    }
    return $out;
}

// Xray domain-rule matching semantics: full: exact, domain:/plain suffix on
// a label boundary, keyword: substring, regexp: preg_match. v2fly lists can
// contain any of these forms (bare_domain() already strips all four
// prefixes elsewhere in the codebase), so the matcher supports every one of
// them even though today's generated config only ever emits full:/domain:/
// plain tokens (see domain_rule_entries()).
function domain_token_matches(string $token, string $host): bool {
    $host = strtolower($host);
    if (strncmp($token, 'full:', 5) === 0) {
        return $host === strtolower(substr($token, 5));
    }
    if (strncmp($token, 'domain:', 7) === 0) {
        return _domain_suffix_match($host, strtolower(substr($token, 7)));
    }
    if (strncmp($token, 'keyword:', 8) === 0) {
        $needle = strtolower(substr($token, 8));
        return $needle !== '' && strpos($host, $needle) !== false;
    }
    if (strncmp($token, 'regexp:', 7) === 0) {
        $pattern = substr($token, 7);
        if ($pattern === '') return false;
        // v2fly stores a bare regex body (no delimiters); pick a delimiter
        // that can't collide with the pattern text itself and suppress the
        // warning a malformed list entry would otherwise raise.
        $delim = '/';
        foreach (['/', '#', '~', '%', '!'] as $d) {
            if (strpos($pattern, $d) === false) { $delim = $d; break; }
        }
        $result = @preg_match($delim . $pattern . $delim . 'i', $host);
        return $result === 1;
    }
    // Plain token (no prefix): same as "domain:" — suffix on a label boundary.
    return _domain_suffix_match($host, strtolower($token));
}

function _domain_suffix_match(string $host, string $base): bool {
    if ($base === '') return false;
    return $host === $base
        || (strlen($host) > strlen($base) && substr($host, -strlen($base) - 1) === '.' . $base);
}

// CIDR match for both IPv4 and IPv6, done in PHP via inet_pton — no ctype_*,
// no shelling out. $token may be a bare IP (treated as an exact /32 or /128
// match) or a "net/bits" CIDR literal, exactly as ips.txt stores them.
function ip_token_matches(string $token, string $ip): bool {
    $slash = strpos($token, '/');
    $net = $slash === false ? $token : substr($token, 0, $slash);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false) return false;
    if (strlen($ipBin) !== strlen($netBin)) return false; // v4 literal vs v6 net (or vice versa)

    $maxBits = strlen($ipBin) * 8;
    $bits = $slash === false ? $maxBits : (int)substr($token, $slash + 1);
    if ($bits < 0 || $bits > $maxBits) return false;
    if ($bits === $maxBits) return $ipBin === $netBin;

    $fullBytes = intdiv($bits, 8);
    $remBits = $bits % 8;
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) return false;
    if ($remBits === 0) return true;
    $mask = (0xFF << (8 - $remBits)) & 0xFF;
    return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
}

/**
 * Explains which routing rule and outbound $input (a domain or an IP
 * literal) would take, walking $domainBuckets/$ipBuckets in the order
 * given — the caller is responsible for building them in the SAME order
 * generate_xray_config() emits its rules (ksort() the tag-keyed buckets from
 * domain_rule_entries()/ip_rule_entries(), THEN regroup_rule_entries_by_target()),
 * so first-match here means the same thing it does in the generated config.
 * An unmatched input takes the generator's own default outbound: 'direct'.
 *
 * $domainBuckets / $ipBuckets: target ('proxy'/'direct'/<server id>) =>
 * list of {token, rule, source} entries.
 * $inVpnSet(string $kind, string $input): ?bool — looked up by the caller
 * (nslookup + `ipset test vpn1` in production); injectable so this function
 * stays pure and testable without touching the network or ipset.
 *
 * @return array{input:string, kind:string, rule:?string, source:?string,
 *               target:?string, target_name:?string, in_vpn_set:?bool}
 */
function route_explain(string $input, array $domainBuckets, array $ipBuckets, callable $inVpnSet): array {
    $input = trim($input);
    $isIp = filter_var($input, FILTER_VALIDATE_IP) !== false;
    $kind = $isIp ? 'ip' : 'domain';

    $rule = null;
    $source = null;
    $target = 'direct'; // generate_xray_config()'s own default outbound

    if ($kind === 'ip') {
        foreach ($ipBuckets as $tgt => $entries) {
            foreach ($entries as $e) {
                if (ip_token_matches($e['token'], $input)) {
                    $rule = $e['rule'];
                    $source = $e['source'];
                    $target = (string)$tgt;
                    break 2;
                }
            }
        }
    } else {
        $host = strtolower($input);
        foreach ($domainBuckets as $tgt => $entries) {
            foreach ($entries as $e) {
                if (domain_token_matches($e['token'], $host)) {
                    $rule = $e['rule'];
                    $source = $e['source'];
                    $target = (string)$tgt;
                    break 2;
                }
            }
        }
    }

    return [
        'input' => $input,
        'kind' => $kind,
        'rule' => $rule,
        'source' => $source,
        'target' => $target,
        'target_name' => null, // filled by the caller (needs keys/cached server names)
        'in_vpn_set' => $inVpnSet($kind, $input),
    ];
}

// Human name for a route_explain() target, for the API to attach as
// 'target_name'. 'direct'/'proxy' get a fixed label; a server id is looked
// up by name among the enabled keys/cached-subscription lists; an id that
// isn't found (removed/disabled server) reports null, not a guess.
function route_target_name(string $target, array $keys, array $cached): ?string {
    if ($target === 'direct') return 'Напрямую';
    if ($target === 'proxy') return 'VPN (активный сервер)';
    foreach ($keys as $k) {
        if (($k['id'] ?? '') === $target) return (string)($k['name'] ?? $target);
    }
    foreach ($cached as $s) {
        if (($s['id'] ?? '') === $target) return (string)($s['name'] ?? $target);
    }
    return null;
}
