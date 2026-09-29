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
