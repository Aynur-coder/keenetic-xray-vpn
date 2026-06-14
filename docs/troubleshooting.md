# Troubleshooting

## Installation failed mid-way

`install.sh` is idempotent and uses atomic file moves, so re-running it generally finishes what was interrupted:

```sh
curl -fsSL https://raw.githubusercontent.com/Aynur-coder/keenetic-xray-vpn/main/install.sh | sh
```

If a lock file is left behind (PID not running):

```sh
rm /opt/var/run/xray-vpn-install.lock
```

If a backup is needed, they live in `/opt/etc/xray/backups/<timestamp>/` (5 newest kept).

## "auth_required" when the LAN should bypass

`api.php` checks `_ip_in_subnet` against `trusted_subnets` in `ui-auth.json`. If your LAN is not `192.168.1.0/24` or `192.168.0.0/24`, edit that file:

```json
{
  "skip_local": true,
  "trusted_subnets": ["192.168.0.0/24", "10.50.0.0/24", "192.168.88.0/24"]
}
```

After editing, refresh the browser.

## UI shows "Память —/— MB"

`free -m` on BusyBox ignores `-m` and emits KB. `api.php` already divides by 1024; if you still see kilobytes, the file is out of date — run install.sh again or wait for auto-update.

## Wizard keeps reappearing

The wizard triggers only when `/opt/etc/xray/.onboarded` is missing. Touch it manually to skip:

```sh
date -Iseconds > /opt/etc/xray/.onboarded
```

## Settings → "WireGuard" toggle is stuck

After a reboot, the `S99wireguard` init script checks `features.json` and refuses to start when `wireguard=false`. Toggle back on from the UI; the WG interface comes up immediately.

If wg0 fails to come up but Settings says it's enabled, run:

```sh
/opt/etc/init.d/S99wireguard status
/opt/bin/wg show wg0
```

Common cause: missing `/opt/etc/wireguard/wg0.conf`. Create one from the UI (Add Peer for yourself) or restore from backup.

## "Update available" badge never goes away

The SPA caches the answer for 6h via `localStorage`. Clear it from the browser console:

```js
localStorage.removeItem('xrayvpn:update:check'); location.reload();
```

The backend also caches for 6h in `/opt/tmp/xray-vpn-update-check.json`. Pass `force=1` to bypass:

```
curl 'http://192.168.1.1:91/api.php?action=check_update&force=1'
```

## RCI auth fails — device list is wrong

The wizard's "Test" button shows the first 3 devices when the Keenetic password is correct. If it fails:

- Confirm the password works on `http://192.168.1.1` in a private browser tab.
- Some Keenetic firmwares use realm `Keenetic Hopper` vs `Keenetic`. `api.php` reads it from the challenge response — no manual override needed, but if the regex falls back to the default the digest will mismatch. Update to the latest version.
- ARP/DHCP fallback kicks in automatically and gives you a list, just without hostnames.

## After USB unplug, nothing works

`/opt` lives on USB. When it disappears, Entware processes (xray, lighttpd, AdGuard) die. Reinsert the USB and Keenetic auto-mounts; `rc.unslung` re-runs the S* scripts. If something stays down:

```sh
/opt/etc/init.d/S80lighttpd start
/opt/etc/init.d/S22xray start
/opt/etc/init.d/S99adguardhome start
/opt/etc/init.d/S99wireguard start
```

## auto_update fired and broke things

```sh
/opt/etc/xray/update.sh --rollback
```

This re-installs the version recorded in `.version` at the start of the failed run via `install.sh --upgrade --version v<prev>`. Manual restore from a specific backup directory:

```sh
ls /opt/etc/xray/backups
# pick one
install.sh --reinstall --version v0.9.0
```

## Update stuck on "already_running" / "applying"

Fixed in 0.13.0+. The updater now detaches via `setsid` (a lighttpd restart during the update
no longer kills it) and the API guards re-entry by the live process PID, not a 5-minute timer —
a dead run no longer blocks a retry. The fast upgrade path also skips restarting AdGuard/Xray.

If you are still on an old version and hit a stuck state, clear it once and retry:

```sh
cat /opt/tmp/xray-vpn-update.state     # line 1 = status, line 4 = PID
rm -f /opt/tmp/xray-vpn-update.state
```

Or update from SSH (bypasses the web UI entirely; the fresh `install.sh` no longer self-kills):

```sh
curl -fsSL https://github.com/Aynur-coder/keenetic-xray-vpn/releases/latest/download/install.sh | sh -s -- --upgrade
```

## A domain goes direct (real IP) right after changing its server

Per-rule routing happens inside Xray, but the domain's traffic only reaches Xray if its IP is
in ipset `vpn1` (AdGuard adds it on resolution). In 0.13.1+ switching a rule between
Proxy/servers restarts only Xray and leaves the ipset intact, so it applies in ~1s. If a domain
still shows the real IP:

- It may be a brand-new domain whose IP isn't warmed into the ipset yet — wait a few seconds.
- Check the rule and ipset membership:

```sh
cat /opt/etc/xray/rules/rule_targets.json
for ip in $(nslookup example.com 127.0.0.1 2>/dev/null | awk '/^Address [0-9]/{print $3}'); do ipset test vpn1 "$ip"; done
```

- "Напрямую" (`direct`) intentionally excludes the domain from `vpn1` so it bypasses the VPN.
- CDN sites with many rotating IPs may resolve to an address that isn't in the set; re-test or
  add the IP range under IPs.

## Duplicate domains / "exception v2fly" badge

v2fly lists are authoritative. A manually-added domain already covered by an enabled v2fly list
is dropped unless it has its own connection override (then it's a deliberate exception, badged
"исключение v2fly"). Use the **"Убрать дубли"** button in the Rules tab (or the `dedup_rules`
action) to clean up redundant manual domains and duplicate IPs in one pass.

## Logs

- Install: `/opt/var/log/xray-vpn-install.log`
- Update: `/opt/var/log/xray/update.log`
- Xray access/error: `/opt/var/log/xray/{access,error}.log`
- Lighttpd error: `/opt/var/log/lighttpd/error.log`

## Reset everything (last resort)

```sh
curl -fsSL https://raw.githubusercontent.com/Aynur-coder/keenetic-xray-vpn/main/install.sh | sh -s -- --uninstall
rm -rf /opt/etc/xray   # also wipes subscriptions and keys
curl -fsSL https://raw.githubusercontent.com/Aynur-coder/keenetic-xray-vpn/main/install.sh | sh
```
