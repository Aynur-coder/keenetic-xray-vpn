<?php
declare(strict_types=1);

// Guards the constraint that sent us here: the router's PHP 8.4 build has no
// filter, ctype, mbstring or intl extension (see
// .superpowers/sdd/2026-09-30-ui-overhaul-phase3-5/implementer-rules.md).
// filter_var()/filter_input()/ctype_*() are NEVER available there — calling
// any of them is an instant fatal ("Call to undefined function"), not a
// warning — so a static scan for them is cheap insurance against a
// regression that would otherwise only show up on the real router.
// mb_*/idn_* ARE sometimes present, so those stay allowed as long as every
// call is guarded by function_exists() nearby, same pattern
// normalize_rule_input() (lib/rules.php) and apply_error_line() (lib/apply.php)
// already use.
//
// Two checks, both driven by tokenizing every real *.php file under src/web
// (token_get_all(), not text search, so comments/strings — including this
// file's own doc comments — never trigger a false positive):
//   1. no filter_var(/filter_input(/ctype_* identifier anywhere in real code.
//   2. every mb_*/idn_* identifier has a function_exists( call within a few
//      source lines above it (covers a multi-line guarded if/ternary, not
//      just the literal previous line).
// Plus a runtime check (fixtures/portability_smoke.php) that loads every
// src/web/lib/*.php file and exercises route_explain()/normalize_rule_input()/
// build_connections() in a subprocess with the equivalent extension
// functions disabled via -d disable_functions — belt and suspenders for
// anything the static scan can't see (e.g. a call built from a variable).

const PORTABILITY_SRC_DIR = __DIR__ . '/../../src/web';

// How many source lines above an mb_*/idn_* call to search for its
// function_exists() guard. 1 would be the literal "same or previous line",
// but existing guarded code (e.g. rules.php's idn_to_ascii() ternary) puts
// the guard a few lines above a multi-line if body, so this is generous
// enough to accept real patterns while still catching a call with no guard
// anywhere near it.
const PORTABILITY_GUARD_LOOKBACK = 5;

// Stray filesystem "conflicted copy" duplicates (e.g. "system 2.php",
// "api 3.php") that can appear alongside a cloud-synced working copy of this
// repo. They are untracked, not in the deploy manifest, and not real source
// — never meant to be scanned, loaded, or fixed.
function portability_is_stray_duplicate(string $path): bool {
    return (bool)preg_match('/ \d+\.php$/', basename($path));
}

// Every real *.php file under $dir, recursively.
function portability_php_files(string $dir): array {
    $out = [];
    $items = scandir($dir);
    if ($items === false) return $out;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $out = array_merge($out, portability_php_files($path));
        } elseif (substr($item, -4) === '.php' && !portability_is_stray_duplicate($path)) {
            $out[] = $path;
        }
    }
    return $out;
}

// Tokenizes $file and returns the two kinds of violation:
//   banned: [[line, identifier], ...] — filter_var/filter_input/ctype_* used
//           as a real identifier (never a comment or a string's contents).
//   unguarded: [[line, identifier], ...] — an mb_*/idn_* identifier with no
//              function_exists( within PORTABILITY_GUARD_LOOKBACK lines above it.
function portability_scan(string $file): array {
    $src = file_get_contents($file);
    if ($src === false) return ['banned' => [], 'unguarded' => []];
    $lines = explode("\n", $src);
    $tokens = token_get_all($src);

    $banned = [];
    $unguarded = [];
    foreach ($tokens as $tok) {
        if (!is_array($tok)) continue; // punctuation: single chars, never an identifier
        [$id, $text, $line] = $tok;
        if ($id !== T_STRING) continue; // skips comments/strings automatically (different token types)

        if (preg_match('/^(filter_var|filter_input)$/i', $text) || preg_match('/^ctype_[a-z0-9_]*$/i', $text)) {
            $banned[] = [$line, $text];
            continue;
        }
        if (!preg_match('/^(mb_|idn_)[a-z0-9_]*$/i', $text)) continue;

        $guarded = false;
        for ($ln = max(1, $line - PORTABILITY_GUARD_LOOKBACK); $ln <= $line; $ln++) {
            if (strpos($lines[$ln - 1] ?? '', 'function_exists(') !== false) { $guarded = true; break; }
        }
        if (!$guarded) $unguarded[] = [$line, $text];
    }
    return ['banned' => $banned, 'unguarded' => $unguarded];
}

function portability_violations(string $kind): array {
    $violations = [];
    foreach (portability_php_files(PORTABILITY_SRC_DIR) as $file) {
        $rel = substr($file, strlen(PORTABILITY_SRC_DIR) + 1);
        foreach (portability_scan($file)[$kind] as [$line, $what]) {
            $violations[] = "$rel:$line $what(...)";
        }
    }
    return $violations;
}

function test_no_filter_or_ctype_calls_in_src_web(): void {
    $violations = portability_violations('banned');
    eq($violations, [], 'router has no filter/ctype extension — found: ' . implode(', ', $violations));
}

function test_mb_and_idn_calls_are_function_exists_guarded(): void {
    $violations = portability_violations('unguarded');
    eq($violations, [], 'mb_*/idn_* call with no nearby function_exists() guard: '
        . implode(', ', $violations));
}

// ============================================================================
// Runtime check: every lib file loads, and three previously filter_var()-
// dependent functions still work, with the router's missing extensions'
// functions disabled for real (not just statically absent from the code).
// ============================================================================

function test_lib_files_survive_disabled_filter_ctype_mb_idn_functions(): void {
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $script = __DIR__ . '/fixtures/portability_smoke.php';
    $disable = 'filter_var,filter_input,ctype_digit,ctype_alpha,mb_strtolower,idn_to_ascii';
    $cmd = escapeshellarg($php) . ' -d disable_functions=' . escapeshellarg($disable) . ' '
        . escapeshellarg($script) . ' 2>&1';
    $out = trim((string)shell_exec($cmd));
    eq($out, 'SMOKE_OK', 'lib/*.php + route_explain/normalize_rule_input/build_connections '
        . 'must not fatal without filter/ctype/mbstring/intl: ' . $out);
}
