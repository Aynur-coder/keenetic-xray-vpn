# UI Overhaul — Phases 1–2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the backend so status never contradicts itself and the chosen server survives subscription changes, then ship the new Preact UI shell with Overview, Servers and Settings (legacy UI kept for everything else).

**Architecture:** Pure PHP logic moves out of `api.php` into `src/web/lib/*.php` (unit-tested with php-cli); `api.php` keeps the action `switch` and calls the libs. The new UI is Preact + htm ES modules served as static files from `src/web/ui/`, no build step. Screens not yet rebuilt (Маршруты, Устройства, Диагностика, мастер) link to `legacy.php`.

**Tech Stack:** PHP 8 (Entware, no `ctype`), BusyBox sh, lighttpd, Xray 26.9.9, Preact via `htm/preact/standalone.module.js` (htm 3.1.1), vanilla CSS.

**Spec:** `docs/superpowers/specs/2026-09-29-ui-overhaul-design.md` (phases 1–2 of §8). Phases 3–5 get their own plan after this one ships.

## Global Constraints

- No external CDNs or web fonts; every asset is vendored under `src/web/ui/`.
- PHP must not use `ctype_*`; guard `mb_*` with `function_exists`.
- Shell code must run on BusyBox: no fractional `sleep`, no `paste`, no `pgrep` for Xray detection (use `pidof xray`).
- All UI copy in Russian. Existing on-router file formats only gain optional fields (legacy UI must keep working).
- PHP/shell: 4-space indent like existing code. JS/CSS: 2-space indent.
- Every mutation that touches Xray goes through `apply_changes()` (Task 5) — never `killall xray` directly.
- Test runner: `php tests/php/run.php` must exit 0 before every commit.
- Deploy to router = copy changed files (see Task 14); never deploy files with the user's unreviewed hunks — Task 0 commits them first.

## Review Focus

1. The selected subscription server's link changes on update → selection must re-match by name+proto, then host+port, and never silently fall to another server without a warning (Task 4 tests).
2. One subscription server has an unresolvable host or broken link → the rest must keep working; a config that fails `xray run -test` must never replace the running one (Task 5 tests).
3. Double-click / two tabs switching servers at once → applies serialize via lock, no parallel `killall xray` (Task 5 test).
4. Server/key names with quotes and HTML (`O'Reilly <b>x</b>`) → rendered as text, actions still work (Task 12 check).
5. Router unreachable or slow (phone on flaky Wi-Fi) → UI shows «Роутер не отвечает», keeps last data, stops polling in a hidden tab (Task 10 check).

---

### Task 0: Commit the existing working tree

The tree holds two unrelated sets of uncommitted work. Commit them separately so the router can be compared against git.

**Files:** all currently modified files (`git status`).

- [ ] **Step 1:** Build a patch of the user's full-VPN hunks: `git diff -U3 src/ > /tmp/all.patch`; the user's hunks are those mentioning `FULLVPN_PORT`, `fullvpn-in`, the removed MAC→IP lookup in `generate_config`, `add_device`/`delete_device` `quick_apply()` lines, and the index.php device-list hunks. Apply them to the index only: `git apply --cached user.patch`.
- [ ] **Step 2:** `git commit -m "feat(fullvpn): route full-VPN devices via fullvpn-in inbound"`; verify `git show --stat HEAD` lists only those hunks.
- [ ] **Step 3:** Stage the rest (`git add -A src defaults manifest*.json changelog.md`) and commit `"fix: QUIC leak guard, no ipset flush, leaked-flow reset, hysteria2, ctype"`.
- [ ] **Step 4:** Verify `git status --short` is empty except untracked backups.

### Task 1: Extract link parsing into `lib/links.php` + php test runner

**Files:**
- Create: `src/web/lib/links.php`, `tests/php/run.php`, `tests/php/links_test.php`
- Modify: `src/web/api.php` (remove moved functions, add `require_once __DIR__ . '/lib/links.php';` after the path variables), `manifest.template.json`, `manifest.json` (entry for `src/web/lib/links.php` → `/opt/share/www/xray/lib/links.php`, directory `/opt/share/www/xray/lib`)

**Interfaces:**
- Produces: `parse_vless_link(string): ?array`, `parse_ss_link(string): ?array`, `parse_hysteria2_link(string): ?array`, `is_hysteria2_link(string): bool`, `build_outbound_from_link(string $link, string $tag): ?array`, `outbound_address(array): string` (moved unchanged), and new `link_kind(string $line): ?string` returning `'subscription'` for `http(s)://`, `'key'` for `vless|ss|trojan|hysteria2|hy2://`, else `null`.
- Produces: `tests/php/run.php` — loads every `tests/php/*_test.php`; each defines functions named `test_*`; helper `eq($actual, $expected, string $msg)`; prints `PASS/FAIL name`, exits 1 on any failure.

- [ ] **Step 1: Write failing tests** in `links_test.php`: `test_link_kind` (`https://sub.x/a`→subscription, `  vless://u@h:1  `→key, `hy2://a@b:1`→key, `hello`→null); `test_hysteria2_build` (the six cases verified on 2026-09-26: `hysteria2://pw@host.example:443/?sni=s.example#Name` → address `host.example`, port 443, auth `pw`, `tlsSettings.serverName` `s.example`, `alpn` `["h3"]`; `hy2://a@h:443?insecure=1` → `null`; `hy2://a@h:443?pinSHA256=AB:CD` → `pinnedPeerCertSha256` `abcd`; `?obfs=salamander` → null; `h:443,20000-30000` → port 443; `[2001:db8::1]:443` → address `2001:db8::1`); `test_vless_reality_build` (a reality link → `realitySettings.publicKey`, `shortId`, `serverName`).
- [ ] **Step 2:** `php tests/php/run.php` → FAIL (`lib/links.php` missing).
- [ ] **Step 3:** Move the functions verbatim from `api.php`; add `link_kind`.
- [ ] **Step 4:** `php tests/php/run.php` → all PASS; `php -l src/web/api.php` → no errors.
- [ ] **Step 5:** Commit `refactor(api): move link parsing to lib/links.php with tests`.

### Task 2: One `xray_running()` for every status path

**Files:**
- Create: `src/web/lib/system.php`, `tests/php/system_test.php`
- Modify: `src/web/api.php` (`status` ~1100, `check_ips` ~1916, `test_connection` ~1778, `shell_run` ~56), manifests.

**Interfaces:**
- Produces: `xray_pid(string $pidofCmd = 'pidof xray'): ?int` (first PID or null), `xray_running(string $pidofCmd = 'pidof xray'): bool`.
- Produces: `shell_run(string $cmd): ?string` moved here; appends ` 2>&1` only when `$cmd` contains no `2>`.

- [ ] **Step 1: Failing tests:** `test_xray_pid_found` (`xray_pid('echo 1552')` → 1552), `test_xray_pid_multiple` (`'echo 12 34'` → 12), `test_xray_pid_none` (`'true'` → null, `xray_running('true')` → false), `test_shell_run_no_double_redirect` (`shell_run('echo x 2>/dev/null')` → `"x"`).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement; replace all three process checks in `api.php` with `xray_running()` (and `status.pid` with `xray_pid()`).
- [ ] **Step 4:** Tests PASS. On the router: `curl -s 'http://192.168.1.1:91/api.php?action=check_ips'` while Xray runs as `/opt/sbin/xray run …` → no `xray_down` reason.
- [ ] **Step 5:** Commit `fix(api): single xray_running() via pidof — status no longer says Xray is down while it runs`.

### Task 3: Event journal

**Files:**
- Create: `src/web/lib/events.php`, `tests/php/events_test.php`
- Modify: `src/web/api.php` (new `case 'events'` in public reads; emit points), `src/etc/xray/xray-manager.sh` (`_event` helper; calls in `_pause_firewall`, `_resume_firewall`, `_kick_leaked_flows`, `start` failure), manifests.

**Interfaces:**
- Produces: `emit_event(string $level, string $type, string $msg, array $data = [], ?string $file = null): void` — appends one JSON line `{"ts","level","type","msg","data"}` (`ts` = `date('c')`); default file `/opt/var/log/xray-vpn/events.log`; no-op when `features.diag_events === false`; before writing, if file > 262144 bytes rename to `events.log.1` (overwriting).
- Produces: `read_events(int $limit = 100, ?string $level = null, ?string $type = null, ?string $file = null): array` — newest first, reads `events.log.1` too when needed.
- Shell: `_event <level> <type> <msg>` in `xray-manager.sh`, same JSON format, escapes `"` and `\` in msg.
- API: `events?limit=&level=&type=` → `{events:[…]}`.
- Emit points (type → msg examples): `server` «Сервер: Хельсинки [Wi-Fi]»; `service` «Xray запущен» / «Xray остановлен»; `subscription` «Подписка Orris обновлена: 41 сервер (+14, −1)»; `config` (error) «Конфиг отклонён: <текст Xray>»; `watchdog` «Сервер недоступен — VPN на паузе» / «Сервер снова доступен»; `firewall` «Сброшено прямых соединений: N» (only when N>0); `rules` «Добавлено доменов: N».

- [ ] **Step 1: Failing tests:** `test_emit_and_read_newest_first`, `test_filter_level`, `test_rotation_at_256k` (write 300 KB of events to a temp file → `.1` exists, current < 256 KB, `read_events` still returns latest), `test_disabled_feature_no_write`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement lib, action and emit points; `sh -n src/etc/xray/xray-manager.sh`.
- [ ] **Step 4:** Tests PASS.
- [ ] **Step 5:** Commit `feat: human-readable event journal`.

### Task 4: Stable server selection

**Files:**
- Create: `src/web/lib/servers.php`, `tests/php/servers_test.php`
- Modify: `src/web/api.php` (`select_server`, `update_subscriptions`, `generate_xray_config` active-tag selection ~497-532), manifests.

**Interfaces:**
- Produces: `server_hint(array $srv): array` → `['name'=>string,'host'=>string,'port'=>int,'proto'=>string]` (host/port/proto from the link via `lib/links.php`).
- Produces: `rematch_server(array $hint, array $servers): ?string` — id of first enabled server with same `name` and `proto`; else same `host` and `port`; else null.
- Produces: `resolve_active(array $state, array $keys, array $cached): array` → `['id'=>?string,'reason'=>'selected'|'fallback_missing'|'fallback_disabled'|'default']`. Order of fallback: first enabled key, then first enabled cached server.
- State file gains optional keys `active_hint`, `effective_outbound`, `effective_reason`.

- [ ] **Step 1: Failing tests:** `test_rematch_by_name_proto` (old id gone, server with same name `🇸🇪 Стокгольм [Wi-Fi]` + `vless` → its id), `test_rematch_prefers_name_over_host`, `test_rematch_by_host_port`, `test_rematch_none` → null, `test_resolve_selected`, `test_resolve_missing_fallback` (reason `fallback_missing`), `test_resolve_disabled_fallback`, `test_resolve_empty_state_default`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement. `select_server` saves `active_hint` from the chosen server. `update_subscriptions` after writing the cache: if `active_outbound` not present, `rematch_server()`; on success update `active_outbound` and `emit_event('info','server','Выбранный сервер найден заново: <name>')` (Task 3). If no match, leave `active_outbound` as is — `resolve_active` reports `fallback_missing`. `generate_xray_config` uses `resolve_active()` and writes `effective_outbound`/`effective_reason` to state.
- [ ] **Step 4:** Tests PASS.
- [ ] **Step 5:** Commit `feat(api): remember selected server across subscription link changes`.

### Task 5: `apply_changes()` — locked, config-tested apply

**Files:**
- Create: `src/web/lib/apply.php`, `tests/php/apply_test.php`
- Modify: `src/web/api.php` (`generate_xray_config` gains `string $outFile` param; `quick_apply`, `gen_and_restart_xray`, `start`, `restart` call `apply_changes`), manifests.

**Interfaces:**
- Produces: `apply_changes(string $mode = 'full', array $opt = []): array` → `['ok'=>bool,'xray_running'=>bool,'error'=>?string]`. `$mode`: `'full'` (config + firewall + Xray restart + AdGuard reload + warmup — today's `quick_apply`) or `'routing'` (config + Xray restart — today's `gen_and_restart_xray`). `$opt` keys for tests: `xray_bin`, `conf`, `lock`, `restart_cmd`.
- Behaviour: `flock(LOCK_EX)` on `/opt/var/run/xray-apply.lock` (blocking, 60 s cap); generate into `config.json.new`; `xray run -test -config config.json.new`; on failure: keep old config, delete `.new`, set `state.last_apply_error`, `emit_event('error','config',…)`, return error; on success: rename over, clear `last_apply_error`, restart per mode, return `xray_running()`.

- [ ] **Step 1: Failing tests** (fake xray = temp script): `test_bad_config_keeps_old` (fake `-test` exits 1 with «illegal ip rule» → old file content unchanged, `ok` false, `error` contains «illegal ip rule»), `test_good_config_replaces`, `test_lock_serializes` (hold the lock in a child `php -r` sleeping 2 s, start `apply_changes` → returns after ≥2 s, not in parallel).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement; route existing callers through it.
- [ ] **Step 4:** Tests PASS; `php -l` clean.
- [ ] **Step 5:** Commit `feat(api): apply changes under a lock and never replace a working config with a broken one`.

### Task 6: Toggles apply, logs toggle without HUP, `rules_batch`

**Files:**
- Modify: `src/web/api.php` (`toggle_key`, `toggle_github_list`, `update_subscriptions`, `set_features_patch` ~220, new `case 'rules_batch'`)
- Test: `tests/php/rules_test.php` + extracted `apply_rule_ops()` in `src/web/lib/rules.php`

**Interfaces:**
- Produces: `apply_rule_ops(array $ops, array $domains, array $ips, array $targets): array` → `['domains'=>…, 'ips'=>…, 'targets'=>…, 'changed'=>int]`; op shape `['op'=>'delete'|'target'|'match','kind'=>'domain'|'ip'|'list','value'=>string,'arg'=>?string]`.
- API: `rules_batch` POST `ops` (JSON) → writes files once, `update_adguard_ipset()`, `apply_changes('full')` once, returns `{ok, changed}`.
- `logs_enabled` change → `apply_changes('routing')` (no `kill -HUP`).

- [ ] **Step 1: Failing tests:** `test_batch_delete_domains` (3 deletes → 3 fewer, targets cleaned), `test_batch_set_target`, `test_batch_match_full`, `test_unknown_op_ignored` (`changed` counts only valid ops).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Tests PASS.
- [ ] **Step 5:** Commit `fix(api): toggles apply immediately; batch rule edits apply once; logs toggle no longer kills Xray`.

### Task 7: `overview` action

**Files:**
- Create: `src/web/lib/overview.php`, `tests/php/overview_test.php`
- Modify: `src/web/api.php` (`case 'overview'` in public reads), manifests.

**Interfaces:**
- Produces: `build_overview(array $in): array` where `$in` = `['xray_running'=>bool,'watchdog'=>string,'state'=>array,'servers'=>array /* rows ['id','name','proto','enabled'] built from keys + cached servers — no dependency on Task 8 */,'subscription_health'=>?array,'probe'=>?array,'mem'=>[used,total],'wg_up'=>bool,'version'=>string,'update_available'=>bool,'features'=>array]`.
- Returns `['state'=>'running'|'paused'|'stopped'|'config_error','reason'=>?string,'active'=>?server,'effective'=>?server,'effective_reason'=>string,'warnings'=>[['code','message']],'mem_used','mem_total','wg_up','version','update_available','features']`.
- `config_error` wins when `state.last_apply_error` is set; warnings codes: `subscription_expired`, `no_servers`, `selected_missing` («Выбранный сервер пропал из подписки — сейчас используется X»), `watchdog_paused`, `google_ru` (probe cache for effective server says `google_country === 'RU'`).

- [ ] **Step 1: Failing tests:** `test_state_running`, `test_state_paused`, `test_state_config_error_wins`, `test_warning_selected_missing` (effective_reason `fallback_missing` → warning names effective server), `test_warning_google_ru`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** PASS.
- [ ] **Step 5:** Commit `feat(api): overview action — one consistent status for the UI`.

### Task 8: Server list API

**Files:**
- Modify: `src/web/lib/servers.php`, `tests/php/servers_test.php`, `src/web/api.php` (new `servers`, `add_link`, `set_server_flags`, `rename_key`, `toggle_subscription`)
- On-router new file: `/opt/etc/xray/subscriptions/server_flags.json` (`{id:{favorite:bool}}`).

**Interfaces:**
- Produces: `list_servers(array $keys, array $cached, array $subs, array $flags, array $pingCache, array $probeCache, ?string $activeId): array` → `['sources'=>[['id','kind'=>'subscription'|'keys','name','count','updated','error','enabled']], 'servers'=>[['id','source','name','proto','host','port','enabled','favorite','active','ping_ms','google_country','exit_country']]]`. Keys source id is `keys`.
- API `add_link` POST `text` (one or many lines) → `{added_subscriptions, added_keys, skipped:[{line, reason}]}`; subscriptions are fetched and applied once.
- API `set_server_flags` POST `id`, optional `enabled` (0/1), `favorite` (0/1); `enabled` change applies config.

- [ ] **Step 1: Failing tests:** `test_list_groups_by_source`, `test_list_marks_active_and_favorite`, `test_list_proto_from_link` (hysteria2 / vless / ss), `test_add_link_split` (pure helper `classify_lines(string $text): array` → subscriptions/keys/skipped).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS.
- [ ] **Step 5:** Commit `feat(api): unified server list and one-field add`.

### Task 9: `ping_servers` and `server_probe`

**Files:**
- Create: `src/web/lib/probe.php`, `tests/php/probe_test.php`
- Modify: `src/web/api.php`, manifests.

**Interfaces:**
- Produces: `parse_youtube_country(string $html): ?string` (regex `"countryCode":"([A-Z]{2})"`), `parse_cf_trace(string $text): array` (`ip`, `loc`).
- `ping_servers` POST optional `ids` → `{results:{id: ms|null}}`; TCP connect via `curl -o /dev/null -s -w '%{time_connect}' --connect-timeout 3 telnet://host:port`, max 8 parallel shell jobs, hysteria → null; cache `/opt/tmp/xray-ping.json` 300 s.
- `server_probe` POST `id` → `{ok, delay_ms, exit_ip, google_country, exit_country, error}`; temp config with socks inbound `127.0.0.1:10899` + that server's outbound; lock `/opt/var/run/xray-probe.lock` (non-blocking → `{error:'Проверка уже идёт'}`); kill temp Xray after ≤25 s total; delay = `time_total` of `https://www.gstatic.com/generate_204`; cache `/opt/tmp/xray-probe.json` 3600 s.

- [ ] **Step 1: Failing tests:** `test_parse_youtube_country` (`…"countryCode":"FI"…` → `FI`; no match → null), `test_parse_cf_trace`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS; on router `server_probe` for Хельсинки returns `google_country` `FI`, for Нюрнберг `RU` (as measured 2026-09-26).
- [ ] **Step 5:** Commit `feat(api): per-server ping and country probe`.

### Task 10: New UI shell

**Files:**
- Create: `src/web/ui/vendor/preact-htm.js` (download `https://cdn.jsdelivr.net/npm/htm@3.1.1/preact/standalone.module.js`, commit as-is with a header comment noting source+version), `src/web/ui/app.js`, `src/web/ui/api.js`, `src/web/ui/store.js`, `src/web/ui/app.css`, `src/web/ui/components/{button,toggle,sheet,toast,badge,card,empty,row,search,tabs,confirm}.js`
- Rename: `src/web/index.php` → `src/web/legacy.php`; new `src/web/index.php` = HTML shell.
- Modify: manifests (every new file + directories `ui`, `ui/vendor`, `ui/components`, `ui/screens`).

**Interfaces:**
- `api.js`: `api(action: string, data?: object, {method}?): Promise<object>` — GET when no data, POST form-encoded otherwise; 10 s timeout; on network failure/timeout → `{error:'Роутер не отвечает'}` and `store.set({offline:true})`; 401 → route `#/login`; any `{error}` → `toast(error,'error')` unless `{quiet:true}`.
- `store.js`: `store.get()`, `store.set(patch)`, `useStore(selector)` hook; holds `overview`, `servers`, `offline`, `theme`.
- `app.js`: hash routes `#/` Обзор, `#/servers`, `#/settings`, `#/login`; routes `#/routes`, `#/devices`, `#/diagnostics` render a card «Этот раздел пока в старом интерфейсе» with a link to `legacy.php#<tab>`. If `get_onboarding_status.onboarded === false` → `location = 'legacy.php'`. Layout: sidebar ≥900 px, bottom nav <900 px (5 items), header with gear and update dot. Polling: `overview` every 10 s and `check_ips` every 60 s only while `document.visibilityState === 'visible'`.
- Theme: `data-theme` = `light|dark`; `auto` resolves via `matchMedia('(prefers-color-scheme: dark)')` **with** a change listener; value persisted in `localStorage` and `set_features.theme`.

- [ ] **Step 1:** Implement shell + components.
- [ ] **Step 2: Verify in docker mock:** `docker compose -f tests/docker/docker-compose.yml up -d`; open the UI with Playwright at phone (390×844) and desktop (1440×900) sizes; expect no console errors, nav switches routes, back button works.
- [ ] **Step 3: Offline check (Review Focus 5):** stop lighttpd in the container → within 10 s a banner «Роутер не отвечает» appears and the last overview stays on screen; restart → banner disappears.
- [ ] **Step 4:** `sh tests/smoke.sh` in the container passes (index + api still respond).
- [ ] **Step 5:** Commit `feat(ui): Preact shell, navigation, theme, api client; old UI kept as legacy.php`.

### Task 11: Обзор screen

**Files:** Create `src/web/ui/screens/overview.js`, `src/web/ui/screens/server-picker.js` (sheet reused by Task 12).

**Interfaces:**
- Consumes: `overview` (Task 7), `check_ips`, `server_probe` (Task 9), `servers` (Task 8), `events?limit=5` (Task 3), `select_server` + `restart` → replaced by `select_server` returning `apply_changes` result (update `select_server` to call `apply_changes('full')` and return its result).
- `server-picker.js`: `ServerPicker({onClose})` — search, favorites first, grouped by source, row tap selects and shows spinner → success toast «Сервер: <name>» or the returned error.

- [ ] **Step 1:** Implement: state card (label, reason, Старт/Стоп chosen by `state`, Перезапуск), current server card (name, proto, ping, «для Google: FI», «проверить» → `server_probe`), IP card, warnings list (each with its action: «выбрать сервер», «обновить подписку»), last 5 events.
- [ ] **Step 2: Verify on docker mock and router:** stopping Xray → card shows «Остановлен» and VPN IP says the same reason (no contradiction); switching server from the picker updates the card without reload.
- [ ] **Step 3:** Commit `feat(ui): overview screen and server picker`.

### Task 12: Серверы screen

**Files:** Create `src/web/ui/screens/servers.js`, `src/web/ui/screens/add-link.js`.

**Interfaces:** Consumes `servers`, `add_link`, `set_server_flags`, `rename_key`, `delete_key`, `toggle_subscription`, `update_subscriptions`, `delete_subscription`, `ping_servers`, `server_probe`, `select_server`.

- [ ] **Step 1:** Implement: source groups (header: name, count, updated, error, update / on-off / delete with confirm naming the source), rows (flag+name, proto badge, host, ping, google country, star, switch), top bar (search, protocol + country filters, «только избранные», «Проверить все», «Добавить»), row menu (Проверить страну, Реальная задержка, Копировать ссылку; for keys Переименовать, Удалить).
- [ ] **Step 2: Escaping check (Review Focus 4):** add a key named `O'Reilly <b>x</b>` → shown literally; rename/delete of it work.
- [ ] **Step 3: Verify:** add a subscription URL and two key links in one paste → counts match `add_link` response; «Проверить все» fills ping column; hysteria rows show «—».
- [ ] **Step 4:** Commit `feat(ui): servers screen — subscriptions, keys and servers in one place`.

### Task 13: Настройки screen (basic)

**Files:** Create `src/web/ui/screens/settings.js`, `src/web/ui/screens/login.js`.

**Interfaces:** Consumes `get_features`, `set_features`, `check_update`, `changelog_full`, `apply_update`, `status_update`, `rollback_update`, `set_ui_password`, `test_kn_password`, `set_kn_password`, `reset_onboarding`, `login`, `logout`.

- [ ] **Step 1:** Implement sections: Общие (theme, version), Обновления (check, what's new, install with progress polling `status_update` every 1.5 s, rollback with confirm), Функции (WireGuard, AdGuard, автообновление), Диагностика (only «Сырые логи Xray» = `logs_enabled` in this phase), Безопасность (forms with current/new/confirm and inline errors), Опасная зона (confirm dialogs). Login screen handles 429 lockout message.
- [ ] **Step 2: Verify:** toggling «Сырые логи Xray» keeps Xray running (`overview.state` stays `running`) — regression for the old `kill -HUP`.
- [ ] **Step 3:** Commit `feat(ui): settings page and login`.

### Task 14: Ship phases 1–2 to the router

**Files:** Modify `changelog.md` (`[Unreleased]` entries for every user-visible change above).

- [ ] **Step 1:** `php tests/php/run.php` → all PASS; `php -l` on every PHP file; `sh -n` on shell scripts; manifests valid JSON.
- [ ] **Step 2:** Backup on router: `/opt/etc/xray/backups/pre-ui2-<ts>/` (api.php, index.php, lib, ui, xray-manager.sh).
- [ ] **Step 3:** Copy `src/web/{index.php,legacy.php,api.php,lib/,ui/}` → `/opt/share/www/xray/`, `xray-manager.sh` → `/opt/etc/xray/` in one SSH session (router rate-limits SSH logins).
- [ ] **Step 4: Router checks:** `overview.state` = `running` and `check_ips.vpn_ip` set; leaked-flow count 0 (conntrack check from memory note); switch server twice from the phone → both succeed, no «Xray не запущен»; `legacy.php` still loads and works.
- [ ] **Step 5:** Commit `docs(changelog): UI overhaul phases 1–2`.
