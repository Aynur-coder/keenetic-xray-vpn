# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
