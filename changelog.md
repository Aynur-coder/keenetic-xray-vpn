# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
