<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/system.php';

function test_xray_pid_found(): void {
    eq(xray_pid('echo 1552'), 1552, 'single pid parsed as int');
}

function test_xray_pid_multiple(): void {
    eq(xray_pid('echo 12 34'), 12, 'first pid of several');
}

function test_xray_pid_none(): void {
    eq(xray_pid('true'), null, 'no output means no pid');
    eq(xray_running('true'), false, 'not running when pidof finds nothing');
}

function test_shell_run_no_double_redirect(): void {
    eq(shell_run('echo x 2>/dev/null'), 'x', 'existing redirect is not doubled');
}

// ---- shell_lines_timeout() ------------------------------------------------------

function test_shell_lines_timeout_streams_lines(): void {
    $lines = shell_lines_timeout("exec printf 'a\\nbb\\n\\nlast'", 2.0);
    eq(iterator_to_array($lines, false), ['a', 'bb', '', 'last'],
        'every line, the unterminated last one too');
    $many = 0;
    foreach (shell_lines_timeout('exec seq 1 20000', 5.0) as $line) $many++;
    eq($many, 20000, 'output larger than the pipe buffer arrives whole');
}

// Early stop / deadline must kill the process (pid via `exec`, as in the test below).
function _sl_pid_gone(string $pidFile): bool {
    usleep(200000);
    $pid = (int)trim((string)@file_get_contents($pidFile));
    @unlink($pidFile);
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) return !@posix_kill($pid, 0);
    return trim((string)shell_exec('ps -p ' . $pid . ' -o pid= 2>/dev/null')) === '';
}

function test_shell_lines_timeout_deadline_and_early_stop_kill(): void {
    $pidFile = tempnam(sys_get_temp_dir(), 'sl_pid_');
    $start = microtime(true);
    $got = iterator_to_array(shell_lines_timeout(
        'echo $$ > ' . escapeshellarg($pidFile) . '; echo first; exec sleep 91713', 0.3), false);
    eq(microtime(true) - $start < 2.0, true, 'returns at the deadline');
    eq($got, ['first'], 'lines before the deadline are kept');
    eq(_sl_pid_gone($pidFile), true, 'process killed at the deadline');

    $pidFile = tempnam(sys_get_temp_dir(), 'sl_pid_');
    $lines = shell_lines_timeout('echo $$ > ' . escapeshellarg($pidFile) . '; exec yes', 5.0);
    $start = microtime(true);
    foreach ($lines as $line) break;
    unset($lines);
    eq(microtime(true) - $start < 2.0, true, 'stopping early does not wait for the deadline');
    eq(_sl_pid_gone($pidFile), true, 'process killed once the caller drops the generator');
}

// ---- shell_run_parallel_timeout() ---------------------------------------------

function test_shell_run_parallel_timeout_collects_both_outputs_in_order(): void {
    $out = shell_run_parallel_timeout(['exec echo one', 'exec echo two'], 2.0);
    eq($out, ['one', 'two'], 'both commands run, stdout collected in $cmds order');
}

function test_shell_run_parallel_timeout_runs_concurrently(): void {
    $start = microtime(true);
    $out = shell_run_parallel_timeout(['exec sleep 0.3', 'exec sleep 0.3'], 2.0);
    $elapsed = microtime(true) - $start;
    eq($out, ['', ''], 'sleep produces no stdout');
    eq($elapsed < 0.6, true, 'both sleeps overlap under one shared deadline: total time is '
        . '~max(0.3,0.3), not their sum');
}

// A killed command must leave no child process running — unlike backgrounding several
// commands inside one shell script and killing only the wrapping shell (the bug this
// function replaces; see its doc comment), every command here is proc_open()'d
// directly, so proc_terminate() reaches the real process. `exec` at the end of each
// fake command keeps proc_open()'s pid identical to the eventual `sleep` process's pid
// (this codebase's own convention), which is what lets the test's pid file name the
// process that's actually still running (or not) after the call returns.
function test_shell_run_parallel_timeout_kills_children_on_deadline(): void {
    $pidFile1 = tempnam(sys_get_temp_dir(), 'sc_pid1_');
    $pidFile2 = tempnam(sys_get_temp_dir(), 'sc_pid2_');
    @unlink($pidFile1);
    @unlink($pidFile2);
    // Unique, implausible durations so a stray unrelated `sleep` on the test machine
    // can never be mistaken for one of these in the pgrep fallback below.
    $cmd1 = 'echo $$ > ' . escapeshellarg($pidFile1) . '; exec sleep 91711';
    $cmd2 = 'echo $$ > ' . escapeshellarg($pidFile2) . '; exec sleep 91712';

    $start = microtime(true);
    $out = shell_run_parallel_timeout([$cmd1, $cmd2], 0.3);
    $elapsed = microtime(true) - $start;
    eq($elapsed < 2.0, true, 'returns close to the shared deadline, not after sleep 91711s');
    eq($out, ['', ''], 'a killed command with no stdout returns an empty string, not null');

    // Give the SIGKILL a brief moment to land before checking.
    usleep(200000);
    foreach ([$pidFile1, $pidFile2] as $f) {
        $pid = (int)trim((string)@file_get_contents($f));
        eq($pid > 0, true, 'pid file was written before the process was killed');
        if (function_exists('posix_kill')) {
            eq(@posix_kill($pid, 0), false, 'process is gone: posix_kill with signal 0 fails');
        } else {
            $ps = trim((string)shell_exec("pgrep -f 'sleep 9171[12]' 2>/dev/null"));
            eq($ps, '', 'no leftover sleep process matching the unique test argument');
        }
        @unlink($f);
    }
}

// ---- ip_is_v4() / ip_is_v6() / ip_is_valid() -----------------------------------
//
// The router's PHP 8.4 build has no `filter` extension, so these replace every
// filter_var($s, FILTER_VALIDATE_IP[, FILTER_FLAG_IPV4|FILTER_FLAG_IPV6]) call.

function test_ip_is_v4_accepts_valid_ipv4(): void {
    eq(ip_is_v4('1.2.3.4'), true, 'plain dotted-quad');
    eq(ip_is_v4('0.0.0.0'), true, 'all-zero is still a valid literal');
    eq(ip_is_v4('255.255.255.255'), true, 'max octet value');
}

function test_ip_is_v4_rejects_invalid(): void {
    eq(ip_is_v4('1.2.3'), false, 'too few octets');
    eq(ip_is_v4('1.2.3.4.5'), false, 'too many octets');
    eq(ip_is_v4('256.1.1.1'), false, 'octet over 255');
    eq(ip_is_v4('1.2.3.4x'), false, 'trailing junk');
    eq(ip_is_v4('x1.2.3.4'), false, 'leading junk');
    eq(ip_is_v4('010.0.0.1'), false, 'leading zero octet (octal ambiguity)');
    eq(ip_is_v4('1.2.3.4/24'), false, 'CIDR suffix not accepted');
    eq(ip_is_v4('::1'), false, 'an IPv6 literal is not IPv4');
    eq(ip_is_v4(''), false, 'empty string');
    eq(ip_is_v4('1.2.3.-4'), false, 'negative octet');
}

function test_ip_is_v6_accepts_valid_ipv6(): void {
    eq(ip_is_v6('::1'), true, 'loopback shorthand');
    eq(ip_is_v6('2001:db8::1'), true, 'compressed form');
    eq(ip_is_v6('2001:0DB8:0000:0000:0000:0000:0000:0001'), true, 'full uppercase form');
    eq(ip_is_v6('::ffff:1.2.3.4'), true, 'IPv4-mapped IPv6');
}

function test_ip_is_v6_rejects_invalid(): void {
    eq(ip_is_v6('gg::1'), false, 'non-hex segment');
    eq(ip_is_v6('1.2.3.4'), false, 'plain IPv4 has no colon');
    eq(ip_is_v6('2001:db8::1/64'), false, 'CIDR suffix not accepted');
    eq(ip_is_v6(''), false, 'empty string');
    eq(ip_is_v6(':::1'), false, 'triple colon is not valid IPv6');
}

function test_ip_is_valid_accepts_v4_and_v6(): void {
    eq(ip_is_valid('1.2.3.4'), true, 'IPv4 accepted');
    eq(ip_is_valid('::1'), true, 'IPv6 accepted');
    eq(ip_is_valid('2001:db8::1'), true, 'IPv6 compressed accepted');
}

function test_ip_is_valid_rejects_invalid_and_cidr(): void {
    eq(ip_is_valid('1.2.3'), false, 'incomplete IPv4');
    eq(ip_is_valid('256.1.1.1'), false, 'IPv4 octet out of range');
    eq(ip_is_valid('1.2.3.4x'), false, 'IPv4 with trailing junk');
    eq(ip_is_valid('gg::1'), false, 'invalid IPv6');
    eq(ip_is_valid('1.2.3.4/24'), false, 'IPv4 CIDR string not accepted');
    eq(ip_is_valid('2001:db8::1/64'), false, 'IPv6 CIDR string not accepted');
    eq(ip_is_valid('not an ip'), false, 'garbage');
}
