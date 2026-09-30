<?php
// Pure auth decisions, kept free of globals/IO so they can be unit-tested.

/**
 * May the UI password be changed by this request?
 * - no password set yet: anyone who reached the action may set it (onboarding);
 * - local (trusted LAN) request: `current` is optional — the LAN is already trusted and this
 *   is the way back in after a forgotten password — but a supplied one must be right;
 * - remote request: `current` is required and must verify.
 * $verify(string $password): bool checks a candidate against the stored password.
 */
function ui_password_change_allowed(bool $hasPassword, bool $isLocal, ?string $current,
                                    callable $verify): bool {
    if (!$hasPassword) return true;
    if ($current === null) return $isLocal;
    return (bool)$verify($current);
}

// Actions a caller from outside the trusted LAN may use without a session: just enough to log
// in, log out and learn that a login is needed.
const AUTH_REMOTE_OPEN_ACTIONS = ['login', 'logout', 'auth_status'];

/**
 * Must this request log in before $action runs?
 * - trusted LAN (or an existing session): never;
 * - anyone else: yes, for everything except AUTH_REMOTE_OPEN_ACTIONS — reads included, since
 *   keys/raw_config carry credentials and connections/site_check expose browsing and load
 *   the router.
 */
function auth_required_for(string $action, bool $isLocal, bool $authed): bool {
    if ($isLocal || $authed) return false;
    return !in_array($action, AUTH_REMOTE_OPEN_ACTIONS, true);
}
