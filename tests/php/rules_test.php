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
