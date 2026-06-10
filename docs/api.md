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
| `status_update` | GET | `{status, updated_at, message, log_tail}` for progress polling |
| `keys` / `subscriptions` / `subscription_servers` | GET | Lists |
| `domains` / `ips` / `devices` / `lan_devices` | GET | Lists |
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

- `add_domains` / `delete_domain`
- `add_ips` / `delete_ip`
- `add_device` / `delete_device`
- `add_github_list` / `delete_github_list` / `toggle_github_list` / `update_github_lists`
- `v2fly_add` / `v2fly_refresh`

### WireGuard

- `wg_add_peer` / `wg_delete_peer` / `wg_get_config` / `wg_qrcode` / `wg_restart`

### Updates

- `apply_update` — fires `update.sh --apply` in background
- `rollback_update` — fires `update.sh --rollback`

### Logs

- `clear_logs`

## Error shape

```json
{ "error": "auth_required" }
```

HTTP status is 401 for `auth_required`, 429 for `too_many_attempts`, 200 otherwise (errors are conveyed in body).
