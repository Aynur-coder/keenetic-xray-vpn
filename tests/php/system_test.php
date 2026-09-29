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
