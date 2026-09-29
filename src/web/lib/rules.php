<?php
// Domain/IP/list rule helpers, shared by the single-row rule endpoints and the
// batch endpoint (rules_batch).
//
// Pure logic only: no globals, no file I/O beyond what's passed in, so this
// file can be require_once'd standalone from tests.

// Strip Xray domain match-type prefixes -> bare hostname.
function bare_domain(string $token): string {
    foreach (['domain:', 'full:', 'keyword:', 'regexp:'] as $p) {
        if (strncmp($token, $p, strlen($p)) === 0) return substr($token, strlen($p));
    }
    return $token;
}

/**
 * Apply a batch of rule ops to in-memory domains/ips/targets, mirroring the
 * exact semantics of the single-row endpoints (delete_domain, delete_ip,
 * set_rule_target(s_bulk), set_domain_match) so a batch commit behaves the
 * same as doing each edit one at a time — just with one Xray restart at
 * the end instead of one per row.
 *
 * Op shape: ['op' => 'delete'|'target'|'match', 'kind' => 'domain'|'ip'|'list',
 * 'value' => string, 'arg' => ?string].
 * - delete/domain, delete/ip: drop the matching line + its rule_targets entry
 *   (same match rule as delete_domain/delete_ip: bare-domain / exact-ip).
 *   delete/list is out of scope: ignored, not counted (no bulk list removal yet).
 * - target/{domain,ip,list}: set rule_targets['<kind>:<value>'] = arg, or clear
 *   it when arg is '' or 'proxy' (same as set_rule_target).
 * - match/domain: rewrite the domain's match-mode prefix ("full:" when
 *   arg === 'full', else "domain:"); a domain not present is a no-op.
 * Any other/unknown op, kind, or an empty value is ignored and does not count
 * toward 'changed'.
 *
 * Callers are responsible for any side effects outside these three arrays
 * (e.g. `ipset del` for a deleted IP) and for persisting the result.
 *
 * @return array{domains: string[], ips: string[], targets: array<string,string>, changed: int}
 */
function apply_rule_ops(array $ops, array $domains, array $ips, array $targets): array {
    $changed = 0;
    foreach ($ops as $op) {
        if (!is_array($op)) continue;
        $action = $op['op'] ?? '';
        $kind = $op['kind'] ?? '';
        $value = is_string($op['value'] ?? null) ? trim($op['value']) : '';
        $arg = isset($op['arg']) && is_string($op['arg']) ? trim($op['arg']) : null;
        if ($value === '') continue;

        if ($action === 'delete' && $kind === 'domain') {
            $bare = strtolower(bare_domain($value));
            $before = count($domains);
            $domains = array_values(array_filter($domains, fn($x) => strtolower(bare_domain($x)) !== $bare));
            if (count($domains) === $before) continue;
            unset($targets['domain:' . $bare]);
            $changed++;
        } elseif ($action === 'delete' && $kind === 'ip') {
            $before = count($ips);
            $ips = array_values(array_filter($ips, fn($x) => $x !== $value));
            if (count($ips) === $before) continue;
            unset($targets['ip:' . $value]);
            $changed++;
        } elseif ($action === 'target' && in_array($kind, ['domain', 'ip', 'list'], true)) {
            $bare = $kind === 'domain' ? strtolower(bare_domain($value)) : $value;
            $key = $kind . ':' . $bare;
            $target = $arg ?? '';
            if ($target === '' || $target === 'proxy') unset($targets[$key]);
            else $targets[$key] = $target;
            $changed++;
        } elseif ($action === 'match' && $kind === 'domain') {
            $bare = strtolower(bare_domain($value));
            $mode = $arg === 'full' ? 'full:' : 'domain:';
            $found = false;
            foreach ($domains as &$d) {
                if (strtolower(bare_domain($d)) === $bare) { $d = $mode . $bare; $found = true; }
            }
            unset($d);
            if (!$found) continue;
            $changed++;
        }
        // else: unknown op/kind combination (including delete/list) -> ignored, not counted.
    }
    return ['domains' => $domains, 'ips' => $ips, 'targets' => $targets, 'changed' => $changed];
}
