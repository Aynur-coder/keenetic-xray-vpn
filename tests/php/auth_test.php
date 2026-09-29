<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/auth.php';

function _auth_verify_secret(): callable {
    return fn(string $p): bool => $p === 'secret';
}

function test_ui_password_change_no_password_set(): void {
    eq(ui_password_change_allowed(false, false, null, _auth_verify_secret()), true,
        'first password may be set without a current one, even remotely');
}

function test_ui_password_change_local_without_current(): void {
    eq(ui_password_change_allowed(true, true, null, _auth_verify_secret()), true,
        'trusted LAN may change the password without the current one');
}

function test_ui_password_change_local_wrong_current(): void {
    eq(ui_password_change_allowed(true, true, 'nope', _auth_verify_secret()), false,
        'a supplied current password must still be right on the LAN');
}

function test_ui_password_change_remote_without_current(): void {
    eq(ui_password_change_allowed(true, false, null, _auth_verify_secret()), false,
        'remote change requires the current password');
}

function test_ui_password_change_remote_wrong_current(): void {
    eq(ui_password_change_allowed(true, false, 'nope', _auth_verify_secret()), false,
        'remote change with a wrong current password is refused');
}

function test_ui_password_change_remote_right_current(): void {
    eq(ui_password_change_allowed(true, false, 'secret', _auth_verify_secret()), true,
        'remote change with the right current password is allowed');
}
