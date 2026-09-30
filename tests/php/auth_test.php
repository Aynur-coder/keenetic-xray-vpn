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

// ---- auth_required_for() ------------------------------------------------------

function test_auth_required_for_remote_unauthenticated_reads(): void {
    foreach (['keys', 'raw_config', 'connections', 'overview', 'get_onboarding_status',
              'site_check', 'status', 'add_domains', ''] as $a) {
        eq(auth_required_for($a, false, false), true, "remote without session: $a needs login");
    }
}

function test_auth_required_for_remote_open_actions(): void {
    foreach (['login', 'logout', 'auth_status'] as $a) {
        eq(auth_required_for($a, false, false), false, "remote without session: $a stays open");
    }
}

function test_auth_required_for_local(): void {
    foreach (['keys', 'raw_config', 'connections', 'login', 'add_domains'] as $a) {
        eq(auth_required_for($a, true, false), false, "trusted LAN: $a needs no login");
    }
}

function test_auth_required_for_remote_authenticated(): void {
    foreach (['keys', 'raw_config', 'connections', 'overview', 'get_onboarding_status'] as $a) {
        eq(auth_required_for($a, false, true), false, "remote with session: $a allowed");
    }
}
