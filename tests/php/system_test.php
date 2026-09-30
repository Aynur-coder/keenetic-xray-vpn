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
