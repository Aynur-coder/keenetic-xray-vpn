<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/routing.php';

// Scratch dir holding one v2fly list file, cleaned up by the caller.
function _routing_v2fly_dir(string $listName, array $lines): string {
    $dir = sys_get_temp_dir() . '/xray_routing_' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/$listName.txt", implode("\n", $lines) . "\n");
    return $dir;
}

function _routing_no_vpn_set(string $kind, string $input): ?bool {
    return null;
}

// ---- domain_token_matches() -------------------------------------------------

function test_domain_token_suffix_match(): void {
    eq(domain_token_matches('domain:google.com', 'sub.google.com'), true, 'subdomain matches domain: suffix');
    eq(domain_token_matches('domain:google.com', 'google.com'), true, 'exact host matches domain: suffix too');
    eq(domain_token_matches('domain:google.com', 'notgoogle.com'), false, 'no label-boundary match for a mere substring');
}

function test_domain_token_plain_same_as_suffix(): void {
    eq(domain_token_matches('google.com', 'sub.google.com'), true, 'bare token behaves like domain:');
    eq(domain_token_matches('google.com', 'notgoogle.com'), false, 'bare token still respects label boundary');
}

function test_domain_token_full_exact_only(): void {
    eq(domain_token_matches('full:google.com', 'google.com'), true, 'full: matches the exact host');
    eq(domain_token_matches('full:google.com', 'sub.google.com'), false, 'full: does not match a subdomain');
}

function test_domain_token_keyword_substring(): void {
    eq(domain_token_matches('keyword:ads', 'ads.example.com'), true, 'keyword: matches a substring anywhere');
    eq(domain_token_matches('keyword:ads', 'notads.example.com'), true, 'keyword: is a substring match, not label-bound');
    eq(domain_token_matches('keyword:ads', 'example.com'), false, 'keyword: absent when the substring is not present');
}

function test_domain_token_regexp(): void {
    eq(domain_token_matches('regexp:^www\\.example\\.com$', 'www.example.com'), true, 'regexp: matches the pattern');
    eq(domain_token_matches('regexp:^www\\.example\\.com$', 'notwww.example.com'), false, 'regexp: anchors are respected');
}

// ---- ip_token_matches() -----------------------------------------------------

function test_ip_token_ipv4_cidr(): void {
    eq(ip_token_matches('1.2.3.0/24', '1.2.3.42'), true, 'address inside the /24 matches');
    eq(ip_token_matches('1.2.3.0/24', '1.2.4.1'), false, 'address outside the /24 does not match');
}

function test_ip_token_ipv6_cidr(): void {
    eq(ip_token_matches('2001:db8::/32', '2001:db8:1234::1'), true, 'address inside the /32 matches');
    eq(ip_token_matches('2001:db8::/32', '2001:db9::1'), false, 'address outside the /32 does not match');
}

function test_ip_token_exact_no_cidr(): void {
    eq(ip_token_matches('1.2.3.4', '1.2.3.4'), true, 'bare IP is an exact match');
    eq(ip_token_matches('1.2.3.4', '1.2.3.5'), false, 'bare IP does not match a neighbour');
}

function test_ip_token_family_mismatch(): void {
    eq(ip_token_matches('1.2.3.0/24', '::1'), false, 'a v4 net never matches a v6 literal');
    eq(ip_token_matches('::/0', '1.2.3.4'), false, 'a v6 net never matches a v4 literal');
}

// ---- tag_to_target() --------------------------------------------------------

function test_tag_to_target(): void {
    $id_to_tag = ['srv-1' => 'sub-srv-1'];
    eq(tag_to_target('sub-active', $id_to_tag, 'sub-active'), 'proxy', 'the active tag reports as proxy');
    eq(tag_to_target('direct', $id_to_tag, 'sub-active'), 'direct', 'the direct tag reports as direct');
    eq(tag_to_target('sub-srv-1', $id_to_tag, 'sub-active'), 'srv-1', 'a pinned, non-active tag reports its server id');
}

// ---- route_target_name() -----------------------------------------------------

function test_route_target_name(): void {
    $keys = [['id' => 'k1', 'name' => 'My Key']];
    $cached = [['id' => 's1', 'name' => 'Cached Server']];
    eq(route_target_name('direct', $keys, $cached), 'Напрямую', 'direct gets the fixed label');
    eq(route_target_name('proxy', $keys, $cached), 'VPN (активный сервер)', 'proxy gets the fixed label');
    eq(route_target_name('k1', $keys, $cached), 'My Key', 'a key id resolves to its name');
    eq(route_target_name('s1', $keys, $cached), 'Cached Server', 'a cached server id resolves to its name');
    eq(route_target_name('gone', $keys, $cached), null, 'an unknown server id resolves to null, not a guess');
}

// ---- route_explain(): unmatched defaults ------------------------------------

function test_route_explain_unmatched_defaults_direct(): void {
    $r = route_explain('unrouted.example.com', [], [], '_routing_no_vpn_set');
    eq($r['input'], 'unrouted.example.com', 'input echoed back');
    eq($r['kind'], 'domain', 'a non-IP literal is classified as a domain');
    eq($r['rule'], null, 'no rule matched');
    eq($r['source'], null, 'no source when nothing matched');
    eq($r['target'], 'direct', 'unmatched falls through to the generator\'s own default outbound');
}

function test_route_explain_unmatched_ip_defaults_direct(): void {
    $r = route_explain('9.9.9.9', [], [], '_routing_no_vpn_set');
    eq($r['kind'], 'ip', 'a dotted-quad literal is classified as an ip');
    eq($r['rule'], null, 'no rule matched');
    eq($r['target'], 'direct', 'unmatched ip falls through to direct');
}

// ---- route_explain(): domain/ip bucket matching, precedence and target ------

function test_route_explain_domain_match_reports_target(): void {
    $domainBuckets = [
        'direct' => [['token' => 'domain:ads.example', 'rule' => 'domain:ads.example', 'source' => 'manual']],
        'srv-1'  => [['token' => 'full:vip.example', 'rule' => 'full:vip.example', 'source' => 'manual']],
    ];
    $r1 = route_explain('sub.ads.example', $domainBuckets, [], '_routing_no_vpn_set');
    eq($r1['rule'], 'domain:ads.example', 'matched the direct bucket\'s domain rule');
    eq($r1['source'], 'manual', 'manual source reported');
    eq($r1['target'], 'direct', 'target is the bucket key it matched under');

    $r2 = route_explain('vip.example', $domainBuckets, [], '_routing_no_vpn_set');
    eq($r2['rule'], 'full:vip.example', 'matched the pinned server\'s full: rule');
    eq($r2['target'], 'srv-1', 'target is the pinned server id');
}

function test_route_explain_first_bucket_wins(): void {
    // Two buckets both hold a rule that could match — first-match by array
    // order (the caller's ksort'd tag order, relabeled) wins, mirroring how
    // Xray evaluates its generated rules in order.
    $domainBuckets = [
        'proxy'  => [['token' => 'domain:example.com', 'rule' => 'domain:example.com', 'source' => 'manual']],
        'direct' => [['token' => 'domain:example.com', 'rule' => 'domain:example.com', 'source' => 'manual']],
    ];
    $r = route_explain('example.com', $domainBuckets, [], '_routing_no_vpn_set');
    eq($r['target'], 'proxy', 'the first bucket in iteration order wins');
}

function test_route_explain_ip_cidr_match(): void {
    $ipBuckets = ['srv-2' => [['token' => '10.20.0.0/16', 'rule' => '10.20.0.0/16', 'source' => 'manual']]];
    $r = route_explain('10.20.5.5', [], $ipBuckets, '_routing_no_vpn_set');
    eq($r['rule'], '10.20.0.0/16', 'matched the CIDR rule');
    eq($r['target'], 'srv-2', 'target is that bucket\'s server id');
}

// ---- route_explain(): in_vpn_set injection -----------------------------------

function test_route_explain_in_vpn_set_passthrough(): void {
    $calls = [];
    $probe = function (string $kind, string $input) use (&$calls): ?bool {
        $calls[] = [$kind, $input];
        return true;
    };
    $r = route_explain('1.2.3.4', [], [], $probe);
    eq($r['in_vpn_set'], true, 'the callable\'s answer is passed through');
    eq($calls, [['ip', '1.2.3.4']], 'the callable is invoked with kind and the (trimmed) input');
}

// ---- domain_rule_entries(): reuse of the config generator's bucketing -------

function test_domain_rule_entries_list_membership_reports_list_name(): void {
    $dir = _routing_v2fly_dir('ads', ['tracker.example']);
    try {
        $lists = [['name' => 'ads', 'enabled' => true, 'source' => 'v2fly']];
        $entries = domain_rule_entries([], $lists, $dir, [], [], 'proxy');
        eq(isset($entries['proxy']), true, 'the v2fly domain lands in the active-tag bucket');
        $e = $entries['proxy'][0];
        eq($e['rule'], 'list:ads', 'rule reports the list name, not the collapsed domain: token');
        eq($e['source'], 'list', 'source is "list" for a v2fly-derived entry');
        eq($e['token'], 'domain:tracker.example', 'the emitted token still matches what generate_xray_config() writes');
    } finally {
        unlink("$dir/ads.txt");
        rmdir($dir);
    }
}

function test_domain_rule_entries_manual_override_beats_list(): void {
    $dir = _routing_v2fly_dir('ads', ['tracker.example']);
    try {
        $lists = [['name' => 'ads', 'enabled' => true, 'source' => 'v2fly']];
        $targets = ['domain:tracker.example' => 'direct'];
        $entries = domain_rule_entries(['domain:tracker.example'], $lists, $dir, $targets, [], 'proxy');
        eq(isset($entries['proxy']), false, 'the list-bucket assignment is overridden away');
        eq($entries['direct'][0]['source'], 'manual', 'the manual override wins and reports as manual');
        eq($entries['direct'][0]['rule'], 'domain:tracker.example', 'rule is the manual token, not list:ads');
    } finally {
        unlink("$dir/ads.txt");
        rmdir($dir);
    }
}

function test_domain_rule_entries_redundant_manual_yields_to_list(): void {
    $dir = _routing_v2fly_dir('ads', ['tracker.example']);
    try {
        $lists = [['name' => 'ads', 'enabled' => true, 'source' => 'v2fly']];
        // A manual "domain:tracker.example" with no override is redundant: the
        // v2fly list is authoritative and wins, exactly like all_domains_with_target() did.
        $entries = domain_rule_entries(['domain:tracker.example'], $lists, $dir, [], [], 'proxy');
        eq($entries['proxy'][0]['source'], 'list', 'the v2fly list wins over a redundant manual duplicate');
    } finally {
        unlink("$dir/ads.txt");
        rmdir($dir);
    }
}

// ---- all_domains_with_target() / ip_buckets_with_target(): generator wrappers -

function test_all_domains_with_target_wrapper_matches_generator_shape(): void {
    $dir = _routing_v2fly_dir('ads', ['tracker.example']);
    try {
        $lists = [['name' => 'ads', 'enabled' => true, 'source' => 'v2fly']];
        $out = all_domains_with_target(['full:manual.example'], $lists, $dir, [], [], 'proxy');
        eq($out['proxy'], ['full:manual.example', 'domain:tracker.example'], 'flat tag => token list, manual then v2fly');
    } finally {
        unlink("$dir/ads.txt");
        rmdir($dir);
    }
}

function test_ip_buckets_with_target_wrapper(): void {
    $out = ip_buckets_with_target(['1.2.3.4', '5.6.7.8'], ['ip:5.6.7.8' => 'direct'], [], 'proxy');
    eq($out['proxy'], ['1.2.3.4'], 'unpinned ip falls into the active bucket');
    eq($out['direct'], ['5.6.7.8'], 'pinned-direct ip falls into the direct bucket');
}

// ---- regroup_rule_entries_by_target(): end-to-end with route_explain --------

function test_route_explain_end_to_end_via_regroup(): void {
    $dir = _routing_v2fly_dir('ads', ['tracker.example']);
    try {
        $id_to_tag = ['srv-1' => 'sub-srv-1'];
        $active_tag = 'sub-active';
        $lists = [['name' => 'ads', 'enabled' => true, 'source' => 'v2fly']];
        $targets = ['domain:pinned.example' => 'srv-1'];
        $domainLines = ['domain:pinned.example'];

        $entries = domain_rule_entries($domainLines, $lists, $dir, $targets, $id_to_tag, $active_tag);
        ksort($entries);
        $buckets = regroup_rule_entries_by_target($entries, $id_to_tag, $active_tag);

        $r1 = route_explain('tracker.example', $buckets, [], '_routing_no_vpn_set');
        eq($r1['rule'], 'list:ads', 'the v2fly list match is reported by name');
        eq($r1['target'], 'proxy', 'the list is unpinned, so it rides the active outbound');

        $r2 = route_explain('pinned.example', $buckets, [], '_routing_no_vpn_set');
        eq($r2['rule'], 'domain:pinned.example', 'the manual pin is reported');
        eq($r2['target'], 'srv-1', 'the pin resolves to the server id, not its internal tag');
    } finally {
        unlink("$dir/ads.txt");
        rmdir($dir);
    }
}
