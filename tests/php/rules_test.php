<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/rules.php';

function test_batch_delete_domains(): void {
    $domains = ['domain:a.example', 'full:b.example', 'c.example', 'domain:keep.example'];
    $ips = ['1.1.1.1'];
    $targets = ['domain:a.example' => 'direct', 'domain:b.example' => 'srv1', 'ip:1.1.1.1' => 'direct'];
    $ops = [
        ['op' => 'delete', 'kind' => 'domain', 'value' => 'a.example'],
        ['op' => 'delete', 'kind' => 'domain', 'value' => 'b.example'],
        ['op' => 'delete', 'kind' => 'domain', 'value' => 'c.example'],
    ];
    $r = apply_rule_ops($ops, $domains, $ips, $targets);

    eq($r['changed'], 3, 'three deletes counted');
    eq($r['domains'], ['domain:keep.example'], 'three domains removed, one kept');
    eq(isset($r['targets']['domain:a.example']), false, 'a.example target cleaned');
    eq(isset($r['targets']['domain:b.example']), false, 'b.example target cleaned');
    eq($r['targets']['ip:1.1.1.1'] ?? null, 'direct', 'unrelated ip target untouched');
    eq($r['ips'], ['1.1.1.1'], 'ips untouched by domain deletes');
}

function test_batch_delete_ips(): void {
    $ips = ['1.1.1.1', '2.2.2.2', '3.3.3.3'];
    $targets = ['ip:1.1.1.1' => 'direct', 'ip:2.2.2.2' => 'srv1'];
    $ops = [
        ['op' => 'delete', 'kind' => 'ip', 'value' => '1.1.1.1'],
        ['op' => 'delete', 'kind' => 'ip', 'value' => '2.2.2.2'],
    ];
    $r = apply_rule_ops($ops, [], $ips, $targets);

    eq($r['changed'], 2, 'two ip deletes counted');
    eq($r['ips'], ['3.3.3.3'], 'two ips removed, one kept');
    eq($r['targets'], [], 'both ip targets cleaned');
}

function test_batch_set_target(): void {
    $ops = [
        ['op' => 'target', 'kind' => 'domain', 'value' => 'a.example', 'arg' => 'srv1'],
        ['op' => 'target', 'kind' => 'ip', 'value' => '1.1.1.1', 'arg' => 'direct'],
        ['op' => 'target', 'kind' => 'list', 'value' => 'ads', 'arg' => 'direct'],
        // Clearing back to proxy removes the override entirely.
        ['op' => 'target', 'kind' => 'domain', 'value' => 'b.example', 'arg' => 'proxy'],
    ];
    $targets = ['domain:b.example' => 'srv2'];
    $r = apply_rule_ops($ops, ['domain:a.example', 'domain:b.example'], ['1.1.1.1'], $targets);

    eq($r['changed'], 4, 'four target ops counted');
    eq($r['targets']['domain:a.example'], 'srv1', 'domain target set');
    eq($r['targets']['ip:1.1.1.1'], 'direct', 'ip target set');
    eq($r['targets']['list:ads'], 'direct', 'list target set');
    eq(isset($r['targets']['domain:b.example']), false, 'proxy clears the override');
}

function test_batch_match_full(): void {
    $domains = ['domain:a.example', 'domain:b.example'];
    $ops = [
        ['op' => 'match', 'kind' => 'domain', 'value' => 'a.example', 'arg' => 'full'],
        ['op' => 'match', 'kind' => 'domain', 'value' => 'missing.example', 'arg' => 'full'],
    ];
    $r = apply_rule_ops($ops, $domains, [], []);

    eq($r['changed'], 1, 'only the present domain counts');
    eq($r['domains'], ['full:a.example', 'domain:b.example'], 'a.example switched to full match');
}

function test_batch_match_back_to_suffix(): void {
    $domains = ['full:a.example'];
    $ops = [['op' => 'match', 'kind' => 'domain', 'value' => 'a.example', 'arg' => 'suffix']];
    $r = apply_rule_ops($ops, $domains, [], []);

    eq($r['changed'], 1, 'match change counted');
    eq($r['domains'], ['domain:a.example'], 'a.example switched back to suffix match');
}

function test_unknown_op_ignored(): void {
    $domains = ['domain:a.example'];
    $ops = [
        ['op' => 'delete', 'kind' => 'domain', 'value' => 'a.example'],
        ['op' => 'bogus', 'kind' => 'domain', 'value' => 'x.example'],
        ['op' => 'delete', 'kind' => 'list', 'value' => 'ads'],   // out of scope, ignored
        ['op' => 'target', 'kind' => 'domain', 'value' => ''],    // empty value, ignored
        'not-an-array',
    ];
    $r = apply_rule_ops($ops, $domains, [], []);

    eq($r['changed'], 1, 'only the one valid delete counts');
    eq($r['domains'], [], 'the valid delete still applied');
}

// ---------- normalize_rule_input ----------

function test_normalize_url_to_host(): void {
    $r = normalize_rule_input('https://Sub.Example.com/path?q');
    eq($r, ['kind' => 'domain', 'value' => 'sub.example.com', 'reason' => null], 'URL → lowercase host');
}

function test_normalize_url_with_port_and_user(): void {
    $r = normalize_rule_input('http://user@example.com:8080/x');
    eq($r['kind'], 'domain', 'URL with userinfo/port is a domain');
    eq($r['value'], 'example.com', 'userinfo and port dropped');
}

function test_normalize_wildcard(): void {
    eq(normalize_rule_input('*.example.com')['value'], 'example.com', '*. prefix dropped');
    eq(normalize_rule_input('.example.com.')['value'], 'example.com', 'leading/trailing dots dropped');
}

function test_normalize_xray_prefix(): void {
    $r = normalize_rule_input('full:Example.com');
    eq([$r['kind'], $r['value']], ['domain', 'example.com'], 'full: prefix stripped');
}

function test_normalize_ipv4(): void {
    eq(normalize_rule_input(' 1.2.3.4 '), ['kind' => 'ip', 'value' => '1.2.3.4', 'reason' => null], 'plain IPv4');
}

function test_normalize_cidr(): void {
    eq(normalize_rule_input('10.0.0.0/8'), ['kind' => 'ip', 'value' => '10.0.0.0/8', 'reason' => null], 'IPv4 CIDR');
    eq(normalize_rule_input('2001:DB8::/32'), ['kind' => 'ip', 'value' => '2001:db8::/32', 'reason' => null], 'IPv6 CIDR');
}

function test_normalize_bad_cidr(): void {
    $r = normalize_rule_input('10.0.0.0/33');
    eq($r['kind'], null, 'prefix > 32 rejected');
    eq(is_string($r['reason']) && $r['reason'] !== '', true, 'bad prefix has a reason');
}

function test_normalize_url_with_ip_host(): void {
    eq(normalize_rule_input('http://1.2.3.4:81/a')['kind'], 'ip', 'URL with an IP host is an IP');
}

function test_normalize_space_inside(): void {
    $r = normalize_rule_input('exa mple');
    eq($r['kind'], null, 'space inside rejected');
    eq(is_string($r['reason']) && $r['reason'] !== '', true, 'space inside has a reason');
}

function test_normalize_garbage(): void {
    eq(normalize_rule_input('foo_bar!.com')['kind'], null, 'bad characters rejected');
    eq(normalize_rule_input('')['kind'], null, 'empty rejected');
    eq(normalize_rule_input('a..b')['kind'], null, 'empty label rejected');
}

function test_normalize_idn(): void {
    $r = normalize_rule_input('пример.рф');
    eq($r['kind'], 'domain', 'IDN is a domain');
    $expected = function_exists('idn_to_ascii') ? 'xn--e1afmkfd.xn--p1ai' : 'пример.рф';
    eq($r['value'], $expected, 'IDN → punycode when idn_to_ascii exists');
}

function test_collect_rule_inputs_domains(): void {
    $r = collect_rule_inputs("https://A.com/x\n\n*.b.com, c.com; a.com\nexa mple\n1.2.3.4", 'domain');
    eq($r['values'], ['a.com', 'b.com', 'c.com'], 'valid domains, deduplicated, in order');
    eq(array_column($r['invalid'], 'line'), ['exa mple', '1.2.3.4'], 'bad line and IP reported');
    eq($r['invalid'][1]['reason'], 'это IP-адрес — добавьте его как IP', 'IP in a domain list has its own reason');
}

function test_collect_rule_inputs_ips(): void {
    $r = collect_rule_inputs("1.2.3.4\n10.0.0.0/8, example.com", 'ip');
    eq($r['values'], ['1.2.3.4', '10.0.0.0/8'], 'valid IPs');
    eq(array_column($r['invalid'], 'line'), ['example.com'], 'domain in an IP list reported');
}

function test_normalize_ipv6_canonical(): void {
    eq(normalize_rule_input('2001:0DB8:0000:0000:0000:0000:0000:0001')['value'], '2001:db8::1',
        'IPv6 written in full becomes the compressed form');
    eq(normalize_rule_input('2001:db8:0:0::/48')['value'], '2001:db8::/48',
        'IPv6 CIDR address part is canonicalised too');
    eq(normalize_rule_input('[2001:DB8:0::1]:443')['value'], '2001:db8::1',
        'bracketed IPv6 with port canonicalised');
    eq(normalize_rule_input('::FFFF:1.2.3.4')['value'], '::ffff:1.2.3.4', 'IPv4-mapped kept readable');
}

function test_collect_rule_inputs_ipv6_duplicates(): void {
    $r = collect_rule_inputs("2001:db8::1\n2001:0db8:0:0:0:0:0:1, 2001:DB8::1", 'ip');
    eq($r['values'], ['2001:db8::1'], 'spellings of one IPv6 address collapse into one rule');
}

function test_canonical_ip_rule(): void {
    eq(canonical_ip_rule('2001:0db8::0001/64'), '2001:db8::1/64', 'existing IPv6 entry canonicalised');
    eq(canonical_ip_rule('1.2.3.4'), '1.2.3.4', 'IPv4 unchanged');
    eq(canonical_ip_rule('not-an-ip'), 'not-an-ip', 'anything else passed through');
}
