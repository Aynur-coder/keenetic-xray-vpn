#!/bin/sh
# Harness for kick_leaks / _watchdog_kick_tick with fake conntrack, ipset and pidof.
# Run with: sh tests/leak-kick.sh   (POSIX sh, no bash-isms)
SRC="$(cd "$(dirname "$0")/.." && pwd)/src/etc/xray/xray-manager.sh"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/opt/var/run" "$T/opt/var/log"
sed "s#/opt/#$T/opt/#g" "$SRC" > "$T/mgr.sh"
cat > "$T/bin/conntrack" <<'F'
#!/bin/sh
if [ "$1" = "-L" ]; then
  echo "tcp      6 300 ESTABLISHED src=192.168.1.50 dst=1.2.3.4 sport=5000 dport=443 src=1.2.3.4 dst=203.0.113.9 sport=443 dport=5000 [ASSURED]"
  echo "tcp      6 300 ESTABLISHED src=192.168.1.50 dst=9.9.9.9 sport=5001 dport=443 src=9.9.9.9 dst=203.0.113.9 sport=443 dport=5001 [ASSURED]"
  exit 0
fi
echo "$*" >> "$FAKE_LOG"
F
cat > "$T/bin/ipset" <<'F'
#!/bin/sh
[ "$1" = "test" ] && [ "$3" = "1.2.3.4" ]
F
cat > "$T/bin/pidof" <<'F'
#!/bin/sh
[ "$FAKE_XRAY" = "1" ]
F
chmod +x "$T/bin/"*
export PATH="$T/bin:$PATH" FAKE_LOG="$T/log" FAKE_XRAY=1
fail=0
check() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1 (got $2, want $3)"; fail=1; fi; }
count() { [ -f "$FAKE_LOG" ] && wc -l < "$FAKE_LOG" | tr -d ' ' || echo 0; }

: > "$FAKE_LOG"
sh "$T/mgr.sh" kick_leaks >/dev/null 2>&1
check "kick_leaks deletes only the leaked flow" "$(count)" 1
grep -q -- "-d 1.2.3.4 --sport 5000" "$FAKE_LOG" || { echo "FAIL wrong flow"; fail=1; }

# Tick logic: source the manager (dispatcher prints usage on empty $1).
. "$T/mgr.sh" >/dev/null 2>&1
n=0; _kick_leaked_flows() { n=$((n + 1)); }
for i in 1 2 3 4 5 6 7 8; do _watchdog_kick_tick "$i" 0; done
check "kick on ticks 4 and 8 only" "$n" 2
n=0; for i in 1 2 3; do _watchdog_kick_tick "$i" 0; done
check "no kick on ticks 1-3" "$n" 0
n=0; for i in 4 8 12; do _watchdog_kick_tick "$i" 1; done
check "no kick while paused" "$n" 0
n=0; FAKE_XRAY=0; _watchdog_kick_tick 4 0
check "no kick when xray not running" "$n" 0
exit $fail
