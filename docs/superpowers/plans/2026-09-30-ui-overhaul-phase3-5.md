# UI Overhaul — Phases 3–5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Маршруты, Устройства, Диагностика and the setup wizard into the new UI, remove per-server on/off switches, and ship it to the router — after this the new UI covers every legacy feature.

**Architecture:** Same as phases 1–2 (branch `ui-overhaul`): pure PHP in `src/web/lib/*.php` with php-cli tests, actions in `src/web/api.php`, Preact+htm screens in `src/web/ui/screens/`, components in `src/web/ui/components/`, dev verification on `php -S 127.0.0.1:8099 tests/ui/dev-server.php` with fixtures + Playwright, real verification on the router in the last task.

**Tech Stack:** PHP 8 (no ctype), BusyBox sh, Preact+htm (vendored), conntrack, AdGuard Home querylog, WireGuard, qrencode.

**Spec:** `docs/superpowers/specs/2026-09-29-ui-overhaul-design.md` §4.2 (amended 2026-09-30: no per-server switches), §4.3–4.6, §5, §8 phases 3–5.

## Global Constraints

- Everything from the phase 1–2 plan's Global Constraints still binds (no CDN, no ctype, BusyBox-only shell, Russian copy, optional-field-only storage changes, all Xray mutations via `apply_changes()`, `php tests/php/run.php` green before every commit).
- Existing API actions keep their behaviour for `legacy.php`; new UI may call them or new actions.
- Diagnostics tools each have a feature flag in `features.json`: `diag_events`, `diag_connections`, `diag_site_check` (absent = enabled), plus existing `logs_enabled` for raw Xray logs. A disabled tool is hidden and its backend action returns `{error:'Инструмент выключен в настройках'}`.
- UI: htm interpolation only (no innerHTML), real buttons/roles, Esc/focus handling as in existing components, layouts at 390×844 and 1440×900 checked with Playwright, no console errors.
- Router access only in Task 10.

## Review Focus

1. AdGuard querylog can be large or rotated → connections/site-check read only the last 2 MB and never block the request for more than ~3 s (Task 4 tests with a truncated file).
2. conntrack table with thousands of flows on a 254 MB router → connections action caps output (500 newest) and runs `conntrack -L` once per request (Task 4).
3. Domain/IP rule input with junk (spaces, schemes `https://x.com/path`, uppercase, IDN, CIDR, IPv6) → normalised or rejected with a clear reason, never written raw (Task 3 tests).
4. WireGuard peer names with quotes/spaces → no shell injection in wg actions, QR renders (Task 7).
5. Wizard on a fresh router with no subscription/keys → every step skippable, ends in a working overview (Task 8).

---

### Task 1: Remove per-server switches

**Files:** `src/web/ui/screens/servers.js`, `src/web/ui/screens/server-picker.js`, `src/web/api.php` (`select_server`), `src/web/lib/servers.php`, `tests/php/servers_test.php`.

**Interfaces:** Produces `select_server` behaviour: if the chosen server (key or cached) has `enabled === false`, set it to `true` before `apply_changes`. Picker and Servers list show all servers of enabled subscriptions regardless of their own `enabled` flag; rows have no switch. Subscription on/off stays in the source menu.

- [ ] **Step 1:** Failing test `test_enable_on_select` for a pure helper `enable_server(array $list, string $id): array` (flips `enabled` to true for that id only).
- [ ] **Step 2:** Implement helper + use it in `select_server` for keys.json and cached_servers.json; remove switch UI; picker no longer filters by per-server `enabled`.
- [ ] **Step 3:** Tests + task11/task12 Playwright checks updated and green.
- [ ] **Step 4:** Commit `feat(ui): drop per-server on/off — only subscriptions can be disabled`.

### Task 2: `route_explain`

**Files:** Create `src/web/lib/routing.php`, `tests/php/routing_test.php`; modify `src/web/api.php`.

**Interfaces:** `route_explain(string $input, array $domainBuckets, array $ipBuckets, callable $inVpnSet): array` → `['input','kind'=>'domain'|'ip','rule'=>?string /* matching token e.g. domain:google.com, full:x, 1.2.3.0/24, list:openai */,'source'=>'manual'|'list'|null,'target'=>'proxy'|'direct'|<server id>|null,'target_name'=>?string,'in_vpn_set'=>?bool]`. Domain matching follows Xray semantics: `full:` exact, `domain:`/plain = suffix on label boundary; IP matching by CIDR (IPv4 and IPv6). Buckets come from the same `all_domains_with_target()` / IP bucketing the config generator uses — reuse, don't re-implement. API `route_explain?q=` (public read) resolves domain → IPs via `nslookup <d> 127.0.0.1` for `in_vpn_set` (ipset test vpn1).

- [ ] **Step 1: Failing tests:** suffix match `sub.google.com` vs `domain:google.com` true, `notgoogle.com` false; `full:` exact only; list membership reports `list:<name>`; IPv4 CIDR and IPv6 CIDR; unmatched → rule null, target `direct` (default route).
- [ ] **Step 2–4:** Implement, run, commit `feat(api): route_explain — which rule and server a domain or IP uses`.

### Task 3: Маршруты screen

**Files:** Create `src/web/ui/screens/routes.js` (+ `routes-services.js`, `routes-rules.js` if >400 lines); `src/web/lib/rules.php` gains `normalize_rule_input(string $line): array` (`['kind'=>'domain'|'ip'|null,'value'=>string,'reason'=>?string]`) with tests in `tests/php/rules_test.php`; `add_domains`/`add_ips` use it.

**Interfaces:** Consumes existing actions `github_lists`, `v2fly_search`, `v2fly_add`, `v2fly_refresh`, `toggle_github_list`, `delete_github_list`, `update_github_lists`, `domains`, `ips`, `rule_targets`, `set_rule_target`, `add_domains`, `add_ips`, `set_domain_match`, `dedup_rules`, `rules_batch` (Task 6 of phase 1–2), `route_explain` (Task 2), `servers` (target choices).

UI: three blocks on one page — **«Куда пойдёт…»** input on top (debounced, shows rule, target server name, «в VPN-наборе: да/нет»); **Сервисы** (connected v2fly/GitHub lists: name, domain count, updated, target select [VPN (активный сервер) / конкретный сервер / Напрямую], toggle, menu: обновить/удалить; «Добавить сервис» → catalog search sheet); **Свои правила** (add field accepting many lines with target + mode, list with search, filter chips Все/Домены/IP, per-row target select and suffix/exact select for domains, multi-select with bulk delete/target via one `rules_batch`, «Убрать дубли»). Show at most 200 rows with «Показать ещё».

- [ ] **Step 1: Failing tests** for `normalize_rule_input`: `https://Sub.Example.com/path?q` → domain `sub.example.com`; `*.example.com` → domain `example.com`; `1.2.3.4` ip; `10.0.0.0/8` ip; `2001:db8::/32` ip; `exa mple` → null with reason; `пример.рф` → punycode `xn--e1afmkfd.xn--p1ai` if `idn_to_ascii` exists else kept as is.
- [ ] **Step 2:** Implement lib + wire into add actions; tests green.
- [ ] **Step 3:** Build the screen; fixtures for all consumed actions; Playwright check `task-routes-check.js` (add rules, bulk delete sends one rules_batch, target change, explain box, catalog add).
- [ ] **Step 4:** Commit `feat(ui): routes screen — services, own rules, route explain`.

### Task 4: Connections backend

**Files:** Create `src/web/lib/connections.php`, `tests/php/connections_test.php`; modify `src/web/api.php`.

**Interfaces:**
- `dns_wire_answers(string $wireB64): array` → list of IP strings from A/AAAA answers of a DNS message (handles name compression pointers; ignores other types).
- `querylog_ip_map(string $jsonl): array` → `[ip => domain]` newest wins (querylog.json lines: fields `QH` host, `Answer` base64 wire, `T` time).
- `parse_conntrack(string $text): array` → rows `['proto','src','dst','sport','dport','reply_src','reply_sport','state','packets','bytes']` for LAN sources (`192.168.*`, `10.*`, `172.16-31.*`).
- `xray_access_outbounds(string $log): array` → `["src_ip:src_port" => outbound_tag]` from access.log lines `from IP:PORT accepted … [inbound -> outbound]`.
- `build_connections(array $flows, array $ipDomain, array $accessMap, callable $inVpn, array $serverNames): array` → rows `['device_ip','domain','dst','dport','proto','route'=>'vpn'|'direct'|'blocked_quic'|'leak','server'=>?string,'bytes']`; route `vpn` when reply comes from router `:1080/:1083`, `leak` when dst in vpn set but direct, `blocked_quic` for udp/443 to vpn set.
- API `connections?limit=` (default 300, max 500; gated by `diag_connections`): reads last 2 MB of `/opt/etc/AdGuardHome/data/querylog.json` and last 512 KB of access.log, one `conntrack -L`, device names from `lan_devices` cache if present.

- [ ] **Step 1: Failing tests** with fixture strings: a real-format DNS answer (A + AAAA + CNAME with compression), querylog lines (newest wins), conntrack lines (redirected tcp, direct udp FASTNAT, leak), access.log lines, build_connections route classification, truncated first JSONL line ignored.
- [ ] **Step 2–4:** Implement, run, commit `feat(api): live connections with domains and routes`.

### Task 5: Site check + logs sources + events filters

**Files:** Create `src/web/lib/sitecheck.php`, `tests/php/sitecheck_test.php`; modify `src/web/api.php` (`site_check`, `logs`, `events`, `set_features`).

**Interfaces:**
- `site_check?domain=` (gated by `diag_site_check`) → `['domain','ips'=>[],'in_vpn_set'=>bool,'explain'=>route_explain(),'via'=>'vpn'|'direct','google_country'=>?string,'exit_country'=>?string,'direct_flows'=>int,'quic_leaks'=>int]`; fetches through `socks5h://127.0.0.1:1081` (routing applies) with 8 s timeouts; reuses `parse_youtube_country`/`parse_cf_trace` from `lib/probe.php`; counts flows via Task 4 helpers.
- `logs?source=xray_error|xray_access|adguard|manager|update&lines=` (max 500); manager = `logread -e xray-mgr | tail`; keeps old `type=error|access` params working.
- `events` accepts `level` and `type` filters (already in lib) and `since` (ISO time).
- `set_features` accepts `diag_events`, `diag_connections`, `diag_site_check` booleans.

- [ ] **Step 1: Failing tests** for pure parts: site_check result assembly from injected fetchers (vpn vs direct, RU google country), logs source → command mapping rejects unknown sources.
- [ ] **Step 2–4:** Implement, run, commit `feat(api): site check, log sources, diagnostics flags`.

### Task 6: Диагностика screens

**Files:** Create `src/web/ui/screens/diagnostics.js` (tabs router), `diag-events.js`, `diag-connections.js`, `diag-site.js`, `diag-logs.js`; modify `settings.js` (Диагностика card: 4 toggles incl. `logs_enabled`, hint text removed), `app.js` routes `#/diagnostics/<tab>`.

UI: tabs hidden when their flag is off (link «включить в настройках» if all off). События: list with level icon, time (local HH:MM:SS, date separators), type chip, filters level/type, auto-refresh 10 s while visible. Соединения: table (phone: cards) device / domain / dst:port / route badge (VPN · сервер, Напрямую, QUIC отклонён, Утечка in red) / bytes; search; auto-refresh 5 s with pause button. Проверка сайта: input + «Проверить», result card with all fields and plain-language verdict (e.g. «Идёт через VPN (Хельсинки). Google видит: FI.»). Сырые логи: source select, lines select, auto-refresh toggle, level highlighting (error red, warning orange), «Очистить» with confirm (existing `clear_logs`).

- [ ] **Step 1:** Fixtures + screens.
- [ ] **Step 2:** Playwright `task-diag-check.js` at both sizes: tabs respect flags, filters, leak row highlighted, site check verdict text, logs highlighting, no console errors.
- [ ] **Step 3:** Commit `feat(ui): diagnostics — events, connections, site check, raw logs`.

### Task 7: Устройства screen + WireGuard

**Files:** Create `src/web/ui/screens/devices.js`; modify `src/web/api.php` (`wg_qrcode` gains `format=svg` via `qrencode -t SVG`, falls back to existing UTF8; all wg/device actions use `escapeshellarg` — fix any that don't), tests for any extracted helper (`wg_peer_name_valid(string): bool` — `[A-Za-z0-9_-]{1,32}` plus Cyrillic letters allowed).

UI: **Устройства сети** (from `lan_devices` + `devices`): name, IP, MAC, online dot, segmented control «Выборочно / Всё через VPN» (add_device/delete_device; shows apply error); search. **WireGuard** (hidden if feature off): peers with status dot, handshake («2 мин назад»), rx/tx, menu Конфиг (sheet with text + copy + SVG QR + download .conf), Удалить (confirm); «Добавить клиента» with name validation; «Перезапустить WireGuard».

- [ ] **Step 1:** Failing tests for `wg_peer_name_valid` (quotes/spaces/`;` rejected).
- [ ] **Step 2:** Backend + screen + fixtures; Playwright `task-devices-check.js` (mode switch, add peer validation, QR sheet renders SVG as image, peer named `a'b` rejected).
- [ ] **Step 3:** Commit `feat(ui): devices — full-VPN per device and WireGuard clients`.

### Task 8: Мастер настройки in the new UI

**Files:** Create `src/web/ui/screens/wizard.js`; modify `app.js` (route `#/setup`; when `get_onboarding_status.onboarded === false` go to `#/setup` instead of `legacy.php`), `settings.js` (Опасная зона → `#/setup`).

Steps (port legacy behaviour 1:1, legacy.php ~386 and ~1524-1700): 1 пароль панели (optional, skippable), 2 пароль Keenetic (test then save; skippable), 3 подписка или ключ (one field → `add_link`; skippable), 4 выбор сервера (ServerPicker list inline; skippable if none), 5 WireGuard вкл/выкл → `set_features`, `complete_onboarding`, `start`. Progress indicator, «Назад», each step shows errors inline.

- [ ] **Step 1:** Screen + fixtures (fresh router scenario with no servers).
- [ ] **Step 2:** Playwright `task-wizard-check.js`: full path; all-skipped path ends on Обзор without errors (Review Focus 5).
- [ ] **Step 3:** Commit `feat(ui): setup wizard in the new UI`.

### Task 9: Parity + polish

**Files:** `docs/superpowers/specs/…` untouched; create `tests/ui/parity.md` (checklist: every legacy.php feature → new screen/action, each ticked); small fixes in touched screens only.

- [ ] **Step 1:** Walk legacy.php features (tabs, settings modal, update modal, wizard) and tick parity; any gap → implement in the owning screen.
- [ ] **Step 2:** Replace «пока в старом интерфейсе» placeholders (should be none); keep a small «Старый интерфейс» link in Настройки → Общие.
- [ ] **Step 3:** All Playwright checks green at both sizes; `php tests/php/run.php` green.
- [ ] **Step 4:** Commit `chore(ui): legacy parity checklist and polish`.

### Task 10: Ship to the router

- [ ] **Step 1:** Local suite green; build file list from git (`git archive <sha>`), never the working tree.
- [ ] **Step 2:** One SSH session: backup `/opt/etc/xray/backups/pre-ui3-<ts>/`, copy files per manifest, `lighttpd -tt`, restart lighttpd, API `restart`.
- [ ] **Step 3: Checks:** overview running + VPN IP; leaked flows 0; `route_explain?q=gemini.google.com` shows VPN target; `connections` returns rows with domains; `site_check?domain=gemini.google.com` via vpn with non-RU google country; devices list loads; WireGuard config QR renders; wizard route reachable; screenshots of every new screen at 390×844 and 1440×900 from the real router, console clean.
- [ ] **Step 4:** On failure restore backup and report; on success changelog entries + commit `docs(changelog): UI overhaul phases 3–5`, push branch (PR #1 updates).
