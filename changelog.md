# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.11.23] - 2026-06-13
### Added
- Поиск v2fly на русском: «ватсап» → whatsapp, «телеграм» → telegram, «гугл» → google и т.д.

## [0.11.22] - 2026-06-13
### Fixed
- v2fly: используется `ss-downloader` если есть (обрабатывает `include:` рекурсивно), иначе curl с GitHub — поиск и загрузка работают одинаково на любом роутере
- install.sh: автоматически устанавливает `ss-downloader` под нужную архитектуру (mipsle/arm64/arm/amd64)

## [0.11.21] - 2026-06-13
### Fixed
- v2fly: загрузка из правильного источника (`master/data/{name}`) с парсингом формата `domain:example.com`
- v2fly: каталог теперь получается через GitHub API — отображаются только реально существующие списки
- «openai» и другие имена которых нет в v2fly теперь показывают понятную ошибку вместо падения

## [0.11.20] - 2026-06-13
### Fixed
- «Ошибка: already_running» при повторном нажатии «Обновить» после зависшего обновления — стейт-файл старше 5 минут теперь считается устаревшим и не блокирует новый запуск

## [0.11.19] - 2026-06-13
### Fixed
- v2fly списки больше не требуют `ss-downloader` — загружаются напрямую с GitHub (`v2fly/domain-list-community`) через curl

## [0.11.18] - 2026-06-12
### Fixed
- UI обновления больше не зависает: завершение определяется по тексту лога («Already at latest», «Nothing to do», «OK») даже если старый update.sh не записал state корректно

## [0.11.17] - 2026-06-12
### Fixed
- Кнопка «Обновить» скрыта когда установлена последняя версия

## [0.11.16] - 2026-06-12
### Fixed
- CI: объединены auto-tag и release в один workflow — PAT не нужен, релиз создаётся автоматически при изменении VERSION

## [0.11.15] - 2026-06-12
### Fixed
- Переключение обратно на предыдущий сервер больше не останавливает VPN
- Анимация спиннера вынесена в отдельный элемент — больше не накладывается на зелёную точку активного сервера

## [0.11.14] - 2026-06-12
### Changed
- Кнопка «Применить» убрана — сервер переключается сразу при нажатии с анимацией спиннера

## [0.11.13] - 2026-06-12
### Changed
- Кнопка переименована: «Применить» → «Обновить»
- Заполнен changelog для версий 0.11.4–0.11.12 — описание изменений теперь отображается в окне обновления

## [0.11.12] - 2026-06-12
### Fixed
- `install.sh`: retry `curl_fetch` up to 3 times with 3s delay on transient DNS/network errors instead of failing immediately.

## [0.11.11] - 2026-06-12
### Fixed
- `update_adguard_ipset()`: regex now handles `ipset: []` inline YAML format written by AdGuard Home on fresh setup. Previously the regex only matched multiline format so the ipset section was never updated — domain routing silently didn't work even with AdGuard configured.

## [0.11.10] - 2026-06-12
### Fixed
- `install.sh` `mf_get`: added `-d doc_root='' -d open_basedir=''` to php-cgi call. `php.ini` sets `doc_root=/opt/share/www` which blocked execution of helper scripts in `/tmp`, causing php-cgi to fall back to `api.php` and return `{"error":"Unknown action"}` as the manifest version.

## [0.11.9] - 2026-06-12
### Fixed
- `install.sh` `mf_get`: keep `REDIRECT_STATUS` (required by force-cgi-redirect) but override `SCRIPT_FILENAME` to the helper script and clear `QUERY_STRING`/`REQUEST_METHOD` to prevent api.php from being executed.

## [0.11.8] - 2026-06-12
### Fixed
- `install.sh` `mf_get`: removed `exec` from subshell — BusyBox ash does not pass `VAR=val` assignments through exec builtins, so `_MF`/`_EXPR` were not reaching php-cgi.

## [0.11.7] - 2026-06-12
### Fixed
- `install.sh` `mf_get`: unset CGI env vars (`REQUEST_METHOD`, `SCRIPT_FILENAME`, `REDIRECT_STATUS`, `QUERY_STRING`) in a subshell before calling php-cgi to prevent lighttpd-inherited vars from redirecting execution to api.php.

## [0.11.6] - 2026-06-12
### Fixed
- `install.sh` `mf_get`: use `env -i` to prevent inheriting CGI env vars from lighttpd. When `SCRIPT_FILENAME=api.php` and `QUERY_STRING=action=apply_update` were inherited, php-cgi executed api.php instead of the helper, triggering a second `update.sh` process.

## [0.11.5] - 2026-06-11
### Fixed
- `install.sh`: stale lock detection now also checks file age (>30 min = stale). Prevents false "Another install running" errors when the lock PID was recycled by an unrelated process after a crash.

## [0.11.4] - 2026-06-11
### Added
- VLESS support in `xray-manager.sh` shell config generator: `build_vless_outbound()` with Reality/TLS support and URL-decode for base64 public keys. Config generator now reads from `keys.json` and `cached_servers.json` (current data model) instead of legacy `list.json`.
### Fixed
- `api.php`: always merge hardcoded default subnets (including `10.50.0.0/24`) into `trusted_subnets` so WireGuard VPN admin access works even on old saved configs.
- `api.php`: prevent concurrent update runs — if state file shows `starting`/`downloading`/`applying`, return `already_running` instead of spawning a second `update.sh`.
- `ci`: shellcheck `--severity=warning` on `install.sh` to suppress SC2016 false positives for PHP strings in single quotes.

## [0.11.3] - 2026-06-11
### Fixed
- `install.sh` on older Entware (2024): `php8-cgi` was outputting HTTP response headers (`X-Powered-By`, `Content-Type`) even with `-q`, causing `mf_get` to return garbage instead of the manifest field. Added `_strip_cgi_headers()` awk filter and switched from `$argv` to `getenv()` for passing values to the PHP helper (more reliable across CGI versions).
- `geoip:private` in Xray routing config caused `failed to open file: geoip.dat` — MIPS Entware xray-core does not include geo data files. Replaced with explicit private IP ranges.
- `status` action always showed "Остановлен" after page refresh: `shell_exec` returns `"0\n"` (with newline), but comparison was `=== '0'`. Added `trim()` and `pgrep -x xray` fallback.

## [0.11.2] - 2026-06-11
### Fixed
- `install.sh` crashed with `sh: php: not found`. Root cause: `mf_get` used `php -r` to parse the manifest, but PHP wasn't installed yet (chicken-and-egg). Added `bootstrap_php()` before `download_manifest()` to ensure PHP is ready first. Also fixed for Entware setups where only `php8-cgi`/`php-cgi` is present (no CLI `php` binary — CGI binaries don't support `-r`): `_find_php()` auto-detects the available binary and mode; in CGI mode `mf_get` writes a one-line helper script to `/tmp` and calls `php8-cgi -f`. Verified on Keenetic Hopper where `php` doesn't exist but `php8-cgi` is installed.

## [0.11.1] - 2026-06-11
### Fixed
- `install.sh` crashed immediately on fresh install with `sh: REL_BASE: parameter not set`. The variable was only assigned inside the `--repo` CLI flag handler but not at the global level — so every install without `--repo` died before downloading the manifest.
- Keenetic model detection no longer prints a scary `WARN` if the model string isn't found; changed to `INFO` and widened the awk pattern to match more firmware versions.

## [0.11.0] - 2026-06-11
### Added
- AdGuard Home fully automated in `install.sh`: deploys `AdGuardHome.yaml` (DNS on port 53, Cloudflare+Google DOH upstreams, web UI on :3000), `adguardhome.conf` (Entware startup options), and the critical `10-dns-redirect.sh` Keenetic netfilter hook that redirects all LAN DNS queries through AGH. Applies iptables rules immediately, no reboot needed.
- `docs/adguard-setup.md`: full explanation of the AGH ↔ Xray DNS→ipset→iptables chain, step-by-step setup guide, troubleshooting.
- Wizard step 4 (server picker): search input for lists with >5 servers.

### Fixed
- Status bar skeleton animation no longer persists after data loads (stRealIp and stMem were not removing `.loading` class).
- Header bell/gear icons now visible — white text/border on gradient background.
- Emojis in wizard and Settings replaced with matching inline SVG icons.
- VPN IP and Real IP no longer block the main status response (moved to separate `check_ips` action called asynchronously). Status loads in <1s instead of up to 10s.
- Wizard step 4: server items showed `name [empty-badge] :` — fixed by parsing `vless://host:port` link client-side; now shows `Name · VLESS · host:port · source`. Port strips trailing slashes.
- Wizard step 4: `select_server` was called with wrong parameter (`tag` instead of `id`) — server was never actually selected.
- Full VPN: `xray-manager.sh start()` was calling broken shell `generate_config()` on every boot, causing xray not to start after reboot. Fixed to use existing `config.json` if valid (preserves UI-generated config). Auto-connect on reboot now works.
- Full VPN: source IP lookup now filters FAILED/INCOMPLETE ARP entries and adds DHCP leases + ndmc API as fallbacks.
- Full VPN devices panel: shows colored dot (green = IP resolved, orange = IP missing) and a warning banner with one-click Xray restart when device has no IP.
- "Restart wizard" confirm message clarifies that keys, subscriptions and domains are NOT deleted.
- `S99wireguard` now has start/stop/restart/status and respects `features.json.wireguard` on boot.

## [0.10.0] - 2026-06-10
### Added
- Onboarding wizard (5 steps): UI password, Keenetic admin password (with live RCI test), first subscription URL, server picker, WireGuard toggle.
- Login overlay for remote access (LAN bypass via trusted subnets).
- Session-based auth + rate-limit on failed logins.
- Settings modal (gear icon): version & auto-update toggle, WireGuard/AdGuard/theme switches, password management, rollback & restart-wizard buttons.
- Auto-update: `update.sh` with `--check / --apply / --cron / --rollback`, API endpoints, bell icon with badge, modal with changelog and progress polling.
- Light theme + auto (follows system).
- Stacking toast system with success/error/warn/info, swipe-to-dismiss on mobile.
- Skeleton shimmer placeholders for status bar.
- Mobile polish: scroll-snap tabs, 16px inputs (no iOS zoom), full-screen modals on small screens.
- Live status dot in header (pulse animation).
- `prefers-reduced-motion` respect.

### Changed
- `S99wireguard` now supports start/stop/restart/status and respects `features.json.wireguard` on boot.

## [0.9.0] - 2026-06-10
### Added
- Initial public release skeleton.
- POSIX `install.sh` for one-command installation on Keenetic with Entware.
- Web UI on port 91 (lighttpd + php-cgi): server selection, subscriptions, manual keys, domains, IPs, v2fly catalog, devices (Full VPN by MAC), WireGuard peers, logs.
- `xray-manager.sh` for config generation, firewall rules, ipset.
- Integration with Keenetic RCI API for device list.
- Optional WireGuard (toggle in UI).

### Fixed
- Memory display in status bar — `free -m` on BusyBox actually returns KB; divide by 1024.
