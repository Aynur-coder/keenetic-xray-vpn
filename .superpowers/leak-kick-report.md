# Leak kick report
- manager: `kick_leaks` CLI command; `_watchdog_kick_tick <n> <is_paused>` called each watchdog iteration (every 4th tick, not paused, pidof xray).
- api.php warmup_ipset(): `nice -n 19 $MANAGER kick_leaks` after the dig loop, before pid-file removal.
- tests/leak-kick.sh (sh and dash, fake conntrack/ipset/pidof): 5 checks pass. php tests: 226 passed.
- Concerns: the tick runs in the watchdog process itself (no nice, function cannot be niced); router untouched and not tested live.
