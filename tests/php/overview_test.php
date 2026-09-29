<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/overview.php';

function _ov_servers(): array {
    return [
        ['id' => 'k1', 'name' => 'Key One', 'proto' => 'vless', 'enabled' => true],
        ['id' => 's1', 'name' => '🇸🇪 Стокгольм', 'proto' => 'vless', 'enabled' => true],
        ['id' => 's2', 'name' => 'Нюрнберг', 'proto' => 'hysteria2', 'enabled' => true],
    ];
}

function _ov_base(array $over = []): array {
    return array_merge([
        'xray_running'        => true,
        'watchdog'            => '',
        'state'               => ['active_outbound' => 's1', 'effective_outbound' => 's1',
            'effective_reason' => 'selected'],
        'servers'             => _ov_servers(),
        'subscription_health' => null,
        'probe'               => null,
        'mem'                 => [512, 256],
        'wg_up'               => false,
        'version'             => '1.2.3',
        'update_available'    => false,
        'features'            => ['theme' => 'auto'],
    ], $over);
}

function test_state_running(): void {
    $r = build_overview(_ov_base());
    eq($r['state'], 'running', 'xray running, no watchdog pause, no config error');
    eq($r['reason'], null, 'no reason when running cleanly');
    eq($r['active'], ['id' => 's1', 'name' => '🇸🇪 Стокгольм', 'proto' => 'vless'], 'active server resolved');
    eq($r['effective'], ['id' => 's1', 'name' => '🇸🇪 Стокгольм', 'proto' => 'vless'],
        'effective server resolved from state');
    eq($r['effective_reason'], 'selected', 'effective_reason taken from state');
    eq($r['warnings'], [], 'no warnings when everything is healthy');
    eq($r['mem_used'], 512, 'mem_used passed through');
    eq($r['mem_total'], 256, 'mem_total passed through');
    eq($r['wg_up'], false, 'wg_up passed through');
    eq($r['version'], '1.2.3', 'version passed through');
    eq($r['update_available'], false, 'update_available passed through');
    eq($r['features'], ['theme' => 'auto'], 'features passed through');
    eq($r['xray_running'], true, 'xray_running passed through when running');
}

function test_state_stopped(): void {
    $r = build_overview(_ov_base(['xray_running' => false]));
    eq($r['state'], 'stopped', 'xray not running and no watchdog pause means stopped');
    eq($r['xray_running'], false, 'xray_running passed through when stopped');
}

function test_state_paused(): void {
    $r = build_overview(_ov_base(['watchdog' => 'paused']));
    eq($r['state'], 'paused', 'watchdog paused overrides running');
    $codes = array_column($r['warnings'], 'code');
    eq(in_array('watchdog_paused', $codes, true), true, 'watchdog_paused warning present');
}

function test_state_config_error_wins(): void {
    // Even with Xray running and watchdog paused, a rejected config wins.
    $r = build_overview(_ov_base([
        'watchdog' => 'paused',
        'state' => ['active_outbound' => 's1', 'last_apply_error' => 'unknown host: bad.example'],
    ]));
    eq($r['state'], 'config_error', 'last_apply_error wins over paused/running');
    eq($r['reason'], 'unknown host: bad.example', 'reason is the stored last_apply_error text');
    eq($r['xray_running'], true, 'config_error still reports Xray running on the old config');
}

function test_state_config_error_xray_stopped(): void {
    // The UI picks Старт vs Стоп from xray_running, so it must be exact in config_error too.
    $r = build_overview(_ov_base([
        'xray_running' => false,
        'state' => ['active_outbound' => 's1', 'last_apply_error' => 'unknown host: bad.example'],
    ]));
    eq($r['state'], 'config_error', 'config_error even when Xray is down');
    eq($r['xray_running'], false, 'config_error with Xray down reports xray_running=false');
}

function test_warning_selected_missing(): void {
    // No effective_outbound recorded yet (never applied) and the selected id
    // ('gone') no longer exists among servers: resolve_active() falls back.
    $r = build_overview(_ov_base([
        'state' => ['active_outbound' => 'gone'],
    ]));
    eq($r['effective_reason'], 'fallback_missing', 'resolve_active reports fallback_missing');
    eq($r['effective'], ['id' => 'k1', 'name' => 'Key One', 'proto' => 'vless'],
        'effective falls back to first enabled server');
    $names = array_column($r['warnings'], 'message');
    eq(in_array('Выбранный сервер пропал из подписки — сейчас используется Key One', $names, true), true,
        'selected_missing warning names the effective server');
    $codes = array_column($r['warnings'], 'code');
    eq(in_array('selected_missing', $codes, true), true, 'selected_missing code present');
}

function test_warning_google_ru(): void {
    $r = build_overview(_ov_base([
        'probe' => ['s1' => ['ts' => 111, 'delay_ms' => 50, 'exit_ip' => '1.2.3.4',
            'google_country' => 'RU', 'exit_country' => 'RU', 'error' => null]],
    ]));
    $codes = array_column($r['warnings'], 'code');
    eq(in_array('google_ru', $codes, true), true, 'google_ru warning present');
    $names = array_column($r['warnings'], 'message');
    eq(in_array(
        'Google видит выход этого сервера как Россию — Gemini и YouTube могут не работать', $names, true
    ), true, 'google_ru warning text matches');
}

function test_no_google_ru_warning_for_other_country(): void {
    $r = build_overview(_ov_base([
        'probe' => ['s1' => ['google_country' => 'DE']],
    ]));
    $codes = array_column($r['warnings'], 'code');
    eq(in_array('google_ru', $codes, true), false, 'no google_ru warning when exit is not RU');
}

function test_warning_subscription_expired_reused(): void {
    $health = ['code' => 'subscription_expired',
        'message' => 'Подписка истекла — сервер-заглушка вместо рабочих серверов',
        'hint' => '', 'live_keys' => 0];
    $r = build_overview(_ov_base(['subscription_health' => $health]));
    eq($r['warnings'][0], ['code' => 'subscription_expired',
        'message' => 'Подписка истекла — сервер-заглушка вместо рабочих серверов'],
        'subscription_health message reused verbatim');
}

function test_no_active_when_unset(): void {
    $r = build_overview(_ov_base(['state' => []]));
    eq($r['active'], null, 'no active_outbound recorded yet');
}
