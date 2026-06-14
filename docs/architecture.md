# Architecture

## Components

| Component | Role | Where it lives |
|---|---|---|
| **Xray-core** | VPN client process. Outbounds to VLESS/SS/Trojan. | `/opt/sbin/xray`, config at `/opt/etc/xray/config.json` |
| **xray-manager.sh** | Generates `config.json` from rules + subscriptions, sets iptables/ipset. | `/opt/etc/xray/xray-manager.sh` |
| **AdGuard Home** | DNS resolver that tags lookups with ipset membership so iptables routes them via the VPN. | `/opt/sbin/AdGuardHome`, config `/opt/etc/AdGuardHome/AdGuardHome.yaml` |
| **WireGuard** | Optional VPN server for clients (phones, laptops) to reach the home network. | `/opt/bin/wg`, config `/opt/etc/wireguard/wg0.conf` |
| **lighttpd + php-cgi** | Serves the web UI on port 91. | `/opt/sbin/lighttpd`, config `/opt/etc/lighttpd/conf.d/91-shadowsocks.conf` |
| **Web UI** | Single `index.php` (HTML + CSS + vanilla JS) + `api.php` backend. | `/opt/share/www/xray/` |
| **install.sh** | One-command installer + upgrader (POSIX sh, BusyBox-safe). | curled from GitHub, runs in place |
| **update.sh** | Wraps install.sh with --check/--apply/--cron/--rollback. | `/opt/etc/xray/update.sh` |
| **migrate.sh** | Applies versioned migrations between releases. | `/opt/etc/xray/migrate.sh` |
| **init.d scripts** | Autostart hooks Entware picks up at boot. | `/opt/etc/init.d/S22xray`, `S99wireguard` |
| **cron** | Daily auto-update check at 04:30. | `/opt/etc/cron.d/xray-vpn-update` |

## Data layout

```
/opt/etc/xray/
├── .version              # installed semver (read by update.sh)
├── .onboarded            # presence = setup wizard finished
├── .kn_pass              # Keenetic admin password (chmod 600)
├── .migrations           # list of applied migrations
├── ui-auth.json          # UI password hash (chmod 600)
├── features.json         # { wireguard, adguard, auto_update, theme }
├── state.json            # active outbound, mode
├── config.json           # generated Xray config
├── rules/
│   ├── domains.txt       # custom domains to route via VPN (tokens may carry an Xray
│   │                     #   match prefix: "domain:" = suffix, "full:" = exact)
│   ├── ips.txt           # custom IPs/CIDRs to route via VPN
│   ├── rule_targets.json # sparse per-rule overrides: "domain:x"/"ip:x"/"list:x" → "direct"|server-id
│   │                     #   (absent value = "proxy" = active server; missing file = legacy behavior)
│   ├── direct_ips.txt    # derived: IP tokens whose target is "direct" (read by the shell firewall)
│   ├── fullvpn_devices.txt   # MACs that route ALL traffic via VPN
│   └── github_lists.json # cached domain-list manifests (v2fly lists tracked with source="v2fly")
├── subscriptions/
│   ├── list.json         # subscription URLs
│   ├── keys.json         # manually-added VLESS/SS keys
│   └── cached_servers.json   # last fetched servers
├── migrations/           # NNNN_*.sh, idempotent
└── backups/              # timestamped, rotated (last 5)

/opt/etc/wireguard/       # chmod 700
├── wg0.conf              # server config + private key
└── *.conf                # one per peer (chmod 600)

/opt/share/www/xray/      # served by lighttpd on :91
├── index.php             # SPA
└── api.php               # backend
```

## Request flow

1. Browser → lighttpd:91. `91-shadowsocks.conf` rewrites paths to `/xray/$1`, so `/api.php` becomes `/xray/api.php` and is handed to `php-cgi`.
2. `api.php` enforces auth: if request is on a `trusted_subnets` IP (default LAN + WG subnet) and the action is not in `PUBLIC_READ_ACTIONS`, it passes; otherwise it requires `?password=...` from `set_ui_password` or rejects with HTTP 401.
3. Mutating actions (`start`, `stop`, `set_features`, `add_*`, etc.) call into `xray-manager.sh` or directly tweak iptables/ipset/init.d scripts.

## Routing model (per-rule outbounds)

Multiple servers can be enabled at once. Each enabled key/cached server becomes an Xray
outbound tagged `key-<id>` / `sub-<id>`; the active one (`state.json.active_outbound`) is the
default ("Proxy") target. `generate_xray_config()` builds an `id → tag` map, then **buckets
every domain/IP by its effective outbound** and emits **one routing rule per bucket**:

- `resolve_target(key, …)` maps a rule to a tag: `proxy`/absent → active tag, `direct` →
  the `direct` (freedom) outbound, a server id → that server's tag, and an unknown/disabled
  server id → falls back to the active tag.
- **Precedence:** v2fly lists are authoritative. A manual domain only overrides a v2fly list
  when it carries an explicit override (specific server or `direct`); a plain `proxy` manual
  duplicate is dropped (see `all_domains_with_target()`).
- The whole thing stays light on the router: server selection happens **inside Xray** (a few
  extra routing rules), not in the firewall — the same single `vpn1`/`vpn6` ipset and iptables
  rules are reused regardless of how many servers are targeted.

How a domain reaches Xray at all: AdGuard resolves it and adds the resolved IP to ipset
`vpn1`; iptables redirects any packet whose destination is in `vpn1` to tproxy :1080. So
**`direct` = exclude the domain from the ipset** (it never enters Xray); for static IPs the
shell firewall skips any token listed in the derived `direct_ips.txt`.

### Apply paths

| Trigger | Function | What it does |
|---|---|---|
| Add/remove domain or IP, toggle `direct`, list changes | `quick_apply()` | regenerate config → rebuild firewall (flushes + repopulates ipset) → restart Xray → restart AdGuard → warm ipset |
| **Switch a rule between Proxy/servers** (no `direct` involved), change domain match type | `gen_and_restart_xray()` | regenerate config → restart Xray only. **No ipset flush, no AdGuard restart, no warmup** — the IP is already in the set, so the route switches in ~1s and other domains aren't disrupted |

`warmup_ipset()` waits for AdGuard to actually answer (probe loop) instead of a fixed sleep,
so the route comes up sooner after the slow path.

## Update flow

```
                 ┌──────────────────────────────────────┐
                 │            GitHub repo               │
                 │  main/VERSION  ←  push tag vX.Y.Z    │
                 │  releases/vX.Y.Z/manifest.json       │
                 └─────────────────┬────────────────────┘
                                   │
                          curl (raw / release asset)
                                   │
            ┌──────────────────────▼───────────────────┐
            │       router (UI button or cron 04:30)    │
            │  update.sh --apply / --cron               │
            │    └── setsid: detach from the web server │
            │         (a lighttpd restart can't kill it)│
            │    └── writes status + PID to state file   │
            │    └── runs install.sh --upgrade           │
            │         ├── downloads manifest.json        │
            │         ├── verifies SHA256 per file       │
            │         ├── backs up current install       │
            │         ├── atomic mv -f staged → target   │
            │         ├── runs migrations new since old  │
            │         └── restart_services:              │
            │             • upgrade = fast path, NO       │
            │               service restart (code is read │
            │               fresh; AGH/Xray left running) │
            │             • fresh install = full restart  │
            └───────────────────────────────────────────┘
```

`update.sh --apply` first re-execs itself under `setsid` so it survives the install (which may
restart lighttpd). It records `status\ntimestamp\nmessage\npid` to
`/opt/tmp/xray-vpn-update.state`. The `apply_update` API guards re-entry by checking whether
that **PID is still alive** (`kill -0`), not by elapsed time — a dead/finished run never blocks
a retry. `check_update` / `status_update` expose the same state to the UI (current version,
available update, full changelog via `changelog_full`, live progress).

## Networking

- LAN: `192.168.1.0/24` (default Keenetic).
- WireGuard: `10.50.0.0/24`, port 500. Peer `.conf` files are generated server-side and downloadable as QR codes.
- ipset `vpn1` (IPv4) and `vpn6` (IPv6) are populated by AdGuard Home as DNS responses arrive. iptables marks packets to those sets with fwmark 0x1, which is then redirected to `tproxy` port 1080.
- Real IP and VPN IP are surfaced in the status bar via two `curl` probes (direct vs. via socks5 :1081).

## Onboarding

A first-time install leaves `/opt/etc/xray/.onboarded` absent. The SPA detects this through `status.onboarded === false` and shows a 5-step overlay:

1. **UI password** → POST `set_ui_password` → writes hash + salt into `ui-auth.json`.
2. **Keenetic admin password** → POST `test_kn_password` (does the same digest dance as `keenetic_get_devices` and returns the first 3 devices as proof) → POST `set_kn_password` writes `.kn_pass`.
3. **Subscription URL** → POST `add_subscription` + `update_subscriptions`.
4. **Server picker** → POST `select_server` + `start`.
5. **WireGuard toggle** → POST `set_features` + `complete_onboarding` (creates `.onboarded`).

Every step is skippable except the final one. Re-running the wizard later is possible via Settings → Danger zone → "Restart wizard" (deletes `.onboarded` and reloads).

## Security model

- Pass hash: `sha256(salt + ':' + password)`, salt = 16 random bytes hex-encoded. No password is stored in plain.
- Rate limit: 5 failed `login` attempts per IP per hour. Tracked in `/opt/tmp/xray-login-attempts.json`.
- LAN bypass: `_ip_in_subnet` against `trusted_subnets`. If `skip_local: false`, even LAN must log in.
- `.kn_pass` is chmod 600, root-owned.
- `escapeshellarg()` wraps every user-provided string that hits `shell_exec`.
- HTTPS is not provided; remote access should go through WireGuard.
