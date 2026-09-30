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

/**
 * One user-typed rule line → what it means, for add_domains/add_ips.
 * Accepts what people paste: URLs (scheme, userinfo, port, path, query dropped),
 * "*.example.com", Xray "domain:"/"full:" prefixes, IPv4/IPv6 with optional CIDR.
 * An internationalised domain becomes punycode when idn_to_ascii() exists
 * (it is not on every router PHP build; then it is kept as typed).
 *
 * @return array{kind: 'domain'|'ip'|null, value: string, reason: ?string}
 *         kind null = not usable; reason says why (Russian, shown in the UI).
 */
function normalize_rule_input(string $line): array {
    $bad = fn(string $reason): array => ['kind' => null, 'value' => trim($line), 'reason' => $reason];
    $s = trim($line);
    if ($s === '') return $bad('пустая строка');
    if (preg_match('/\s/u', $s)) return $bad('пробел внутри — по одному адресу в строке');

    $ip = normalize_ip_literal($s);
    if ($ip !== null) return $ip;

    foreach (['domain:', 'full:'] as $p) {
        if (strncasecmp($s, $p, strlen($p)) === 0) { $s = substr($s, strlen($p)); break; }
    }
    $s = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $s);
    $s = preg_split('#[/?\#]#', $s, 2)[0];
    $at = strrpos($s, '@');
    if ($at !== false) $s = substr($s, $at + 1);
    if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $s, $m)) $s = $m[1];   // [ipv6]:port
    elseif (substr_count($s, ':') === 1) $s = preg_replace('/:\d*$/', '', $s); // host:port

    $ip = normalize_ip_literal($s);
    if ($ip !== null) return $ip;

    if (strncmp($s, '*.', 2) === 0) $s = substr($s, 2);
    $s = trim($s, '.');
    if ($s === '') return $bad('нет адреса');

    if (preg_match('/[^\x00-\x7f]/', $s)) {
        if (function_exists('idn_to_ascii')) {
            $ascii = defined('INTL_IDNA_VARIANT_UTS46')
                ? idn_to_ascii($s, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
                : idn_to_ascii($s);
            if ($ascii === false || $ascii === '') return $bad('не похоже на домен');
            $s = $ascii;
        } elseif (function_exists('mb_strtolower')) {
            $s = mb_strtolower($s, 'UTF-8');
        }
    }
    $s = strtolower($s);

    if (strlen($s) > 253) return $bad('слишком длинный домен');
    foreach (explode('.', $s) as $label) {
        // Letters/digits/'_'/'-' (and non-ASCII bytes when IDN conversion is unavailable).
        if ($label === '' || strlen($label) > 63
            || !preg_match('/^[a-z0-9_\x80-\xff]([a-z0-9_\-\x80-\xff]*[a-z0-9_\x80-\xff])?$/', $label)) {
            return $bad('не похоже на домен или IP');
        }
    }
    return ['kind' => 'domain', 'value' => $s, 'reason' => null];
}

// IPv4/IPv6 literal with optional /prefix → normalize_rule_input() result, or null
// when $s is not IP-shaped at all. A valid IP with a bad prefix is an error, not a domain.
function normalize_ip_literal(string $s): ?array {
    $parts = explode('/', $s, 2);
    $addr = strtolower($parts[0]);
    if (filter_var($addr, FILTER_VALIDATE_IP) === false) return null;
    $addr = canonical_ip($addr);
    if (count($parts) === 1) return ['kind' => 'ip', 'value' => $addr, 'reason' => null];
    $max = strpos($addr, ':') !== false ? 128 : 32;
    if (!preg_match('/^\d{1,3}$/', $parts[1]) || (int)$parts[1] > $max) {
        return ['kind' => null, 'value' => $s, 'reason' => "маска подсети должна быть от 0 до $max"];
    }
    return ['kind' => 'ip', 'value' => $addr . '/' . (int)$parts[1], 'reason' => null];
}

// One spelling per IPv6 address (2001:0DB8:0::1 → 2001:db8::1), so the same address
// typed differently doesn't become a second rule. $addr must be a valid IP.
function canonical_ip(string $addr): string {
    if (strpos($addr, ':') === false || !function_exists('inet_pton')) return $addr;
    $bin = @inet_pton($addr);
    $out = $bin === false ? false : @inet_ntop($bin);
    return $out === false ? $addr : strtolower($out);
}

// An ips.txt entry (IP or IP/prefix) in canonical form, for comparing entries written
// before canonicalisation; anything else comes back unchanged.
function canonical_ip_rule(string $rule): string {
    $parts = explode('/', trim($rule), 2);
    if (filter_var($parts[0], FILTER_VALIDATE_IP) === false) return $rule;
    $addr = canonical_ip(strtolower($parts[0]));
    return count($parts) === 2 ? $addr . '/' . $parts[1] : $addr;
}

/**
 * Text from the «add rules» field → normalized values of one kind plus the lines that
 * were not usable. Lines are split on newlines, ',' and ';' (not on spaces, so a
 * mistyped "exa mple" is reported instead of becoming two bogus one-label rules).
 * Empty lines are skipped silently; duplicates within the text are collapsed.
 *
 * @param 'domain'|'ip' $want
 * @return array{values: string[], invalid: array{line: string, reason: string}[]}
 */
function collect_rule_inputs(string $text, string $want): array {
    $values = [];
    $invalid = [];
    foreach (preg_split('/[\r\n,;]+/', $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $n = normalize_rule_input($line);
        if ($n['kind'] === $want) {
            $values[] = $n['value'];
        } elseif ($n['kind'] === null) {
            $invalid[] = ['line' => $line, 'reason' => (string)$n['reason']];
        } else {
            $invalid[] = ['line' => $line, 'reason' => $n['kind'] === 'ip'
                ? 'это IP-адрес — добавьте его как IP' : 'это домен — добавьте его как домен'];
        }
    }
    return ['values' => array_values(array_unique($values)), 'invalid' => $invalid];
}
