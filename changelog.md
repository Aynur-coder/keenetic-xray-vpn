# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
