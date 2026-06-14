# API reference

All endpoints live at `/api.php?action=<name>`. They return JSON.

- **Public** actions are reachable on the trusted LAN without a password. Outside the LAN they require a session cookie obtained from `login`.
- **Auth-gated** actions always require either a LAN bypass or a session.

The middleware list lives in `api.php` as `$PUBLIC_READ_ACTIONS`.

## Public

| Action | Method | Returns |
|---|---|---|
| `status` | GET | Service status, IPs, memory, WG state, `onboarded`, `authenticated`, `local`, `features`, `version`. Single round-trip the SPA needs to render. |
| `auth_status` | GET | `{authenticated, local, password_set}` |
| `login` | POST `password` | `{ok}` or `{error: 'invalid_credentials'|'too_many_attempts', retry_after, remaining}` |
| `logout` | POST | Destroys session |
| `get_onboarding_status` | GET | `{onboarded, ui_password_set, kn_pass_set, has_subscription, has_keys, has_servers, features}` |
| `get_features` | GET | `{wireguard, adguard, auto_update, theme}` |
| `get_version` | GET | `{installed}` |
| `check_update` | GET (optional `force=1`) | `{current, latest, available, changelog}` — 6h server-side cache |
| `changelog_full` | GET (optional `force=1`) | `{markdown, current}` — full version history for the "История изменений" view, 6h cache |
| `status_update` | GET | `{status, updated_at, message, log_tail}` for progress polling. `status` reflects the live updater; a dead/finished run no longer reports as running |
| `keys` / `subscriptions` / `subscription_servers` | GET | Lists |
| `domains` | GET | `{manual:[{domain, mode}], v2fly:{domain: listName}}` — `mode` is `suffix`/`full`/`plain` |
| `ips` / `devices` / `lan_devices` | GET | Lists |
| `rule_targets` | GET | Map of per-rule overrides: `{"domain:x"|"ip:x"|"list:x": "direct"|server-id}` |
| `github_lists` / `v2fly_search` | GET | Lists / search results |
| `wg_peers` | GET | Parsed `wg show` output |
| `logs` | GET (`type`, `lines`) | Tail of access.log or error.log |
| `raw_config` | GET | Raw `config.json` contents |

## Auth-gated (mutating)

### Service control

- `start`, `stop`, `restart` — call `xray-manager.sh`
- `warmup_ipset` — kick a background dig pass on tracked domains
- `test_connection` — runs two curl probes to verify the tunnel

### Onboarding

- `set_ui_password` (POST `password`) — sets UI password (also allowed if no password set yet, so the wizard can run)
- `test_kn_password` (POST `password`) — temporarily writes `.kn_pass`, hits Keenetic RCI, returns `{ok, count, sample}`
- `set_kn_password` (POST `password`)
- `complete_onboarding` — writes `.onboarded`
- `reset_onboarding` — deletes `.onboarded`

### Features

- `set_features` (POST one or more of `wireguard`, `adguard`, `auto_update` `=1|0`; `theme` `=auto|dark|light`)
  - Side effect: WG toggle starts/stops the interface immediately.

### Subscriptions / keys

- `add_key` / `delete_key` / `toggle_key`
- `add_subscription` / `delete_subscription` / `update_subscriptions`
- `toggle_server` / `select_server`

### Routing rules

- `add_domains` (POST `domains`; optional `mode=suffix|full`, optional `target=proxy|direct|<server-id>`) — new domains stored with the match prefix; domains already covered by an enabled v2fly list are skipped unless a `target` is given (response includes `added` and `skipped` counts)
- `delete_domain` (POST `domain`) — also clears its `rule_targets` entry
- `add_ips` (POST `ips`; optional `target`) / `delete_ip`
- `set_rule_target` (POST `key`, `target`) — `key` = `domain:x`/`ip:x`/`list:x`, `target` = `proxy`/`direct`/`<server-id>`. Proxy↔server changes apply via the fast Xray-only path; `direct` changes use the full path. May return `{warning: "server_disabled"}`
- `set_rule_targets_bulk` (POST `keys` = JSON array or CSV, `target`) — apply one target to many rules in a single pass
- `set_domain_match` (POST `domain`, `mode=suffix|full`) — change a domain's match type (fast Xray-only apply)
- `dedup_rules` — one-time cleanup: drop manual domains already covered by v2fly (keeping overrides) and collapse duplicate IPs; returns `{removed_domains, removed_ips}`
- `add_device` / `delete_device`
- `add_github_list` / `delete_github_list` / `toggle_github_list` / `update_github_lists`
- `v2fly_add` / `v2fly_refresh`

### WireGuard

- `wg_add_peer` / `wg_delete_peer` / `wg_get_config` / `wg_qrcode` / `wg_restart`

### Updates

- `apply_update` — fires `update.sh --apply` (the script self-detaches via `setsid`). Re-entry is guarded by the live updater PID, not a timer, so it returns `{error: "already_running"}` only while a process is genuinely alive; otherwise it (re)starts. Poll `status_update` for progress
- `rollback_update` — fires `update.sh --rollback`

### Logs

- `clear_logs`

## Error shape

```json
{ "error": "auth_required" }
```

HTTP status is 401 for `auth_required`, 429 for `too_many_attempts`, 200 otherwise (errors are conveyed in body).
