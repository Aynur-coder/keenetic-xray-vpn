#!/bin/sh
# Xray VPN Manager for Keenetic
# Manages config generation, iptables, ipset, subscriptions

XRAY_BIN="/opt/sbin/xray"
XRAY_DIR="/opt/etc/xray"
XRAY_CONF="$XRAY_DIR/config.json"
XRAY_PID="/opt/var/run/xray.pid"
RULES_DIR="$XRAY_DIR/rules"
SUBS_FILE="$XRAY_DIR/subscriptions/list.json"
KEYS_FILE="$XRAY_DIR/subscriptions/keys.json"
CACHED_FILE="$XRAY_DIR/subscriptions/cached_servers.json"
LOG_DIR="/opt/var/log/xray"
DOMAINS_FILE="$RULES_DIR/domains.txt"
IPS_FILE="$RULES_DIR/ips.txt"
DIRECT_IPS_FILE="$RULES_DIR/direct_ips.txt"
FULLVPN_FILE="$RULES_DIR/fullvpn_devices.txt"
IPSET_NAME="vpn1"
IPSET6_NAME="vpn6"
REDIR_PORT=1080
WATCHDOG_PID="/opt/var/run/xray-watchdog.pid"
WATCHDOG_STATE="/opt/var/run/xray-watchdog.state"
WATCHDOG_INTERVAL=30
WATCHDOG_MAX_FAILS=3

log() { _logs_enabled && { logger -t xray-mgr "$1"; echo "$1"; } || true; }

# URL-decode %XX sequences (handles base64 key chars: +, /, =)
_urldecode() { printf '%b' "$(printf '%s' "$1" | sed 's/%2[Bb]/+/g; s/%2[Ff]/\//g; s/%3[Dd]/=/g; s/%2[Ee]/./g')"; }

# Parse ss:// link -> JSON outbound
parse_ss_link() {
    local link="$1"
    # ss://base64@host:port#name
    local encoded=$(echo "$link" | sed 's|ss://||' | sed 's|#.*||' | sed 's|@.*||')
    local hostport=$(echo "$link" | sed 's|ss://[^@]*@||' | sed 's|#.*||' | sed 's|?.*||')
    local decoded=$(echo "$encoded" | base64 -d 2>/dev/null)
    local method=$(echo "$decoded" | cut -d: -f1)
    local password=$(echo "$decoded" | cut -d: -f2-)
    local host=$(echo "$hostport" | rev | cut -d: -f2- | rev)
    local port=$(echo "$hostport" | rev | cut -d: -f1 | rev)
    echo "{\"address\":\"$host\",\"port\":$port,\"method\":\"$method\",\"password\":\"$password\"}"
}

# Build vless:// -> JSON outbound
build_vless_outbound() {
    local link="$1"
    local tag="$2"
    link=$(echo "$link" | sed 's/#.*//')
    local uuid=$(echo "$link" | sed 's|vless://||' | sed 's|@.*||')
    local rest=$(echo "$link" | sed 's|vless://[^@]*@||')
    local host=$(echo "$rest" | cut -d: -f1)
    local port=$(echo "$rest" | cut -d: -f2 | cut -d'?' -f1)
    local params=$(echo "$rest" | sed 's/^[^?]*?//')
    local security=$(echo "$params" | tr '&' '\n' | grep '^security=' | cut -d= -f2)
    local type=$(echo "$params" | tr '&' '\n' | grep '^type=' | cut -d= -f2)
    local sni=$(echo "$params" | tr '&' '\n' | grep '^sni=' | cut -d= -f2)
    local fp=$(echo "$params" | tr '&' '\n' | grep '^fp=' | cut -d= -f2)
    local pbk=$(_urldecode "$(echo "$params" | tr '&' '\n' | grep '^pbk=' | cut -d= -f2)")
    local sid=$(echo "$params" | tr '&' '\n' | grep '^sid=' | cut -d= -f2)
    local flow=$(echo "$params" | tr '&' '\n' | grep '^flow=' | cut -d= -f2)
    [ -z "$type" ] && type="tcp"
    [ -z "$security" ] && security="none"
    [ -z "$fp" ] && fp="chrome"
    local stream_extra=""
    if [ "$security" = "reality" ]; then
        stream_extra=",\"realitySettings\":{\"serverName\":\"$sni\",\"fingerprint\":\"$fp\",\"publicKey\":\"$pbk\",\"shortId\":\"$sid\",\"spiderX\":\"\"}"
    elif [ "$security" = "tls" ]; then
        stream_extra=",\"tlsSettings\":{\"serverName\":\"$sni\",\"fingerprint\":\"$fp\"}"
    fi
    printf '{"tag":"%s","protocol":"vless","settings":{"vnext":[{"address":"%s","port":%s,"users":[{"id":"%s","encryption":"none","flow":"%s"}]}]},"streamSettings":{"network":"%s","security":"%s"%s}}' \
        "$tag" "$host" "$port" "$uuid" "$flow" "$type" "$security" "$stream_extra"
}

# Add one server link to outbounds (ss:// or vless://)
_add_link_outbound() {
    local link="$1"
    [ -z "$link" ] && return
    local type=$(echo "$link" | cut -d: -f1)
    local tag="proxy-$idx"
    local ob=""
    if [ "$type" = "ss" ]; then
        local server=$(parse_ss_link "$link")
        ob="{\"tag\":\"$tag\",\"protocol\":\"shadowsocks\",\"settings\":{\"servers\":[$server]}}"
    elif [ "$type" = "vless" ]; then
        ob=$(build_vless_outbound "$link" "$tag")
    fi
    [ -z "$ob" ] && return
    if [ $idx -eq 0 ]; then active_tag="$tag"; fi
    if [ -n "$outbounds" ]; then outbounds="$outbounds,"; fi
    outbounds="$outbounds$ob"
    idx=$((idx+1))
}

# Iterate a JSON array file, call _add_link_outbound for each enabled entry
_parse_link_file() {
    local file="$1"
    [ -f "$file" ] || return
    local count=$(jsonfilter -i "$file" -t '@.length' 2>/dev/null || echo 0)
    local i=0
    while [ "$i" -lt "$count" ] 2>/dev/null; do
        local enabled=$(jsonfilter -i "$file" -e "@[$i].enabled" 2>/dev/null)
        if [ "$enabled" = "true" ]; then
            local link=$(jsonfilter -i "$file" -e "@[$i].link" 2>/dev/null)
            _add_link_outbound "$link"
        fi
        i=$((i+1))
    done
}

# Generate xray config from subscriptions + rules
generate_config() {
    log "Generating xray config..."

    local outbounds=""
    local active_tag="proxy"
    local idx=0

    # Parse keys and cached subscription servers (current data model)
    _parse_link_file "$KEYS_FILE"
    _parse_link_file "$CACHED_FILE"
    # Legacy fallback: old list.json format with direct links
    _parse_link_file "$SUBS_FILE"

    # If no outbounds parsed, keep defaults
    if [ -z "$outbounds" ]; then
        log "No active subscriptions, using default config"
        return 1
    fi
    
    # Build domain rules
    local domain_rules=""
    if [ -s "$DOMAINS_FILE" ]; then
        domain_rules=$(grep -v '^#\|^$' "$DOMAINS_FILE" | sed 's/^/"/;s/$/"/' | tr '\n' ',' | sed 's/,$//')
    fi
    
    # Build IP rules  
    local ip_rules=""
    if [ -s "$IPS_FILE" ]; then
        ip_rules=$(grep -v '^#\|^$' "$IPS_FILE" | sed 's/^/"/;s/$/"/' | tr '\n' ',' | sed 's/,$//')
    fi
    
    # Build full-vpn source IPs (MAC->IP lookup with multiple fallbacks)
    local fullvpn_sources=""
    if [ -s "$FULLVPN_FILE" ]; then
        while IFS= read -r mac || [ -n "$mac" ]; do
            [ -z "$mac" ] && continue
            [ "${mac#\#}" != "$mac" ] && continue
            # Method 1: ARP table (skip FAILED/INCOMPLETE entries)
            local ip=$(ip neigh show dev br0 | grep -iv 'failed\|incomplete' | grep -i "$mac" | awk '{print $1}' | head -1)
            # Method 2: all interfaces ARP
            if [ -z "$ip" ]; then
                ip=$(ip neigh | grep -iv 'failed\|incomplete' | grep -i "$mac" | awk '{print $1}' | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | head -1)
            fi
            # Method 3: DHCP leases file
            if [ -z "$ip" ]; then
                local mac_lower=$(echo "$mac" | tr '[:upper:]' '[:lower:]')
                ip=$(awk -v m="$mac_lower" '$2==m{print $3}' /tmp/dhcp.leases 2>/dev/null | head -1)
            fi
            # Method 4: ndmc RCI (Keenetic API)
            if [ -z "$ip" ] && command -v ndmc >/dev/null 2>&1; then
                ip=$(ndmc -c "show ip hotspot" 2>/dev/null | grep -i "$mac" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | head -1)
            fi
            if [ -n "$ip" ]; then
                if [ -n "$fullvpn_sources" ]; then fullvpn_sources="$fullvpn_sources,"; fi
                fullvpn_sources="$fullvpn_sources\"$ip\""
                log "Full VPN device $mac → $ip"
            else
                log "Full VPN device $mac: IP not found (iptables MAC rule still active)"
            fi
        done < "$FULLVPN_FILE"
    fi

    # Generate config
    cat > "$XRAY_CONF" << CONF
{
  "log": {
    "loglevel": "warning",
    "access": "$LOG_DIR/access.log",
    "error": "$LOG_DIR/error.log"
  },
  "inbounds": [
    {
      "tag": "tproxy-in",
      "port": $REDIR_PORT,
      "protocol": "dokodemo-door",
      "settings": {"network": "tcp,udp", "followRedirect": true},
      "sniffing": {"enabled": true, "destOverride": ["http","tls","quic"], "routeOnly": true},
      "streamSettings": {"sockopt": {"tproxy": "redirect"}}
    },
    {
      "tag": "socks-in",
      "port": 1081,
      "listen": "0.0.0.0",
      "protocol": "socks",
      "settings": {"auth": "noauth", "udp": true},
      "sniffing": {"enabled": true, "destOverride": ["http","tls","quic"], "routeOnly": true}
    },
    {
      "tag": "http-in",
      "port": 1082,
      "listen": "0.0.0.0",
      "protocol": "http"
    }
  ],
  "outbounds": [
    $outbounds,
    {"tag": "direct", "protocol": "freedom"},
    {"tag": "block", "protocol": "blackhole"}
  ],
  "routing": {
    "domainStrategy": "IPIfNonMatch",
    "rules": [
      {"type": "field", "outboundTag": "direct", "ip": ["geoip:private"]},
CONF

    # Full VPN devices rule
    if [ -n "$fullvpn_sources" ]; then
        cat >> "$XRAY_CONF" << CONF
      {"type": "field", "outboundTag": "$active_tag", "source": [$fullvpn_sources]},
CONF
    fi

    # Domain rules
    if [ -n "$domain_rules" ]; then
        cat >> "$XRAY_CONF" << CONF
      {"type": "field", "outboundTag": "$active_tag", "domain": [$domain_rules]},
CONF
    fi
    
    # IP rules
    if [ -n "$ip_rules" ]; then
        cat >> "$XRAY_CONF" << CONF
      {"type": "field", "outboundTag": "$active_tag", "ip": [$ip_rules]},
CONF
    fi

    cat >> "$XRAY_CONF" << CONF
      {"type": "field", "outboundTag": "direct"}
    ]
  }
}
CONF

    # Validate
    if $XRAY_BIN run -test -config "$XRAY_CONF" > /dev/null 2>&1; then
        log "Config validated OK"
        return 0
    else
        log "ERROR: Config validation failed!"
        $XRAY_BIN run -test -config "$XRAY_CONF" 2>&1
        return 1
    fi
}

# Extract "addr port" of the first VPN outbound from config
_get_vpn_server() {
    [ ! -f "$XRAY_CONF" ] && return
    local addr port
    addr=$(jsonfilter -i "$XRAY_CONF" -e "@.outbounds[0].settings.vnext[0].address" 2>/dev/null)
    port=$(jsonfilter -i "$XRAY_CONF" -e "@.outbounds[0].settings.vnext[0].port" 2>/dev/null)
    if [ -z "$addr" ]; then
        addr=$(jsonfilter -i "$XRAY_CONF" -e "@.outbounds[0].settings.servers[0].address" 2>/dev/null)
        port=$(jsonfilter -i "$XRAY_CONF" -e "@.outbounds[0].settings.servers[0].port" 2>/dev/null)
    fi
    [ -n "$addr" ] && [ -n "$port" ] && printf '%s %s\n' "$addr" "$port"
}

# Test TCP reachability of the VPN server. Returns 1 on connection timeout (server unreachable).
_check_vpn_reachable() {
    local srv addr port rc
    srv=$(_get_vpn_server)
    [ -z "$srv" ] && return 0
    addr=$(echo "$srv" | cut -d' ' -f1)
    port=$(echo "$srv" | cut -d' ' -f2)
    /opt/bin/curl -s --max-time 5 --connect-timeout 5 \
        -o /dev/null "http://$addr:$port" 2>/dev/null
    rc=$?
    [ "$rc" = "28" ] && return 1   # timeout = server IP unreachable
    return 0                        # connected or refused = IP is reachable
}

# Returns 0 if logs_enabled is true (or unset), 1 if explicitly false.
# Reads features.json each call — only used on rare state-change events, not in the hot loop.
_logs_enabled() {
    local v
    v=$(jsonfilter -i "$XRAY_DIR/features.json" -e "@.logs_enabled" 2>/dev/null)
    [ "$v" != "false" ]
}

# Remove the PREROUTING hook so traffic bypasses xray (XRAY chain stays intact for fast resume)
_pause_firewall() {
    iptables -t nat -D PREROUTING -p tcp -i br0 -j XRAY 2>/dev/null
    ip6tables -t nat -D PREROUTING -p tcp -i br0 -j XRAY6 2>/dev/null
    echo "paused" > "$WATCHDOG_STATE"
    log "Watchdog: redirect paused — traffic goes direct"
}

# Re-attach the PREROUTING hook
_resume_firewall() {
    iptables -t nat -C PREROUTING -p tcp -i br0 -j XRAY 2>/dev/null || \
    iptables -t nat -I PREROUTING -p tcp -i br0 -j XRAY
    ip6tables -t nat -C PREROUTING -p tcp -i br0 -j XRAY6 2>/dev/null || \
    ip6tables -t nat -I PREROUTING -p tcp -i br0 -j XRAY6 2>/dev/null
    echo "ok" > "$WATCHDOG_STATE"
    log "Watchdog: redirect resumed — VPN reachable"
}

# Background watchdog loop: when VPN server is unreachable for WATCHDOG_MAX_FAILS consecutive
# checks, removes the iptables redirect to prevent conntrack table saturation (which otherwise
# kills all new TCP connections, including access to 192.168.1.1).
_watchdog_loop() {
    local fail_count=0 is_paused=0
    echo "ok" > "$WATCHDOG_STATE"
    while true; do
        sleep "$WATCHDOG_INTERVAL"
        if _check_vpn_reachable; then
            fail_count=0
            if [ "$is_paused" = "1" ]; then
                _resume_firewall
                is_paused=0
            fi
        else
            fail_count=$((fail_count + 1))
            # No log here — runs every 30 s and would spam syslog constantly.
            # State changes (pause/resume) are logged by _pause_firewall/_resume_firewall.
            if [ "$fail_count" -ge "$WATCHDOG_MAX_FAILS" ] && [ "$is_paused" = "0" ]; then
                _pause_firewall
                is_paused=1
            fi
        fi
    done
}

start_watchdog() {
    stop_watchdog 2>/dev/null
    _watchdog_loop &
    echo $! > "$WATCHDOG_PID"
    log "Watchdog started (PID: $(cat $WATCHDOG_PID), interval: ${WATCHDOG_INTERVAL}s)"
}

stop_watchdog() {
    if [ -f "$WATCHDOG_PID" ]; then
        kill "$(cat "$WATCHDOG_PID")" 2>/dev/null
        rm -f "$WATCHDOG_PID"
    fi
    rm -f "$WATCHDOG_STATE"
}

# Setup iptables + ipset
setup_firewall() {
    log "Setting up firewall rules..."
    
    # Reduce SYN_SENT conntrack timeout: default 120s means stalled redirect attempts
    # linger 2 minutes. 10s flushes them quickly when VPN is down and watchdog hasn't
    # yet paused the redirect, preventing conntrack table saturation.
    echo 10 > /proc/sys/net/netfilter/nf_conntrack_tcp_timeout_syn_sent 2>/dev/null

    # Create ipsets
    ipset create $IPSET_NAME hash:net family inet 2>/dev/null
    ipset create $IPSET6_NAME hash:net family inet6 2>/dev/null
    
    # Flush ipset
    ipset flush $IPSET_NAME 2>/dev/null
    ipset flush $IPSET6_NAME 2>/dev/null
    
    # Add IPs from file (skip those routed "direct" — they must bypass the ipset/Xray)
    if [ -s "$IPS_FILE" ]; then
        while IFS= read -r ip || [ -n "$ip" ]; do
            [ -z "$ip" ] && continue
            [ "${ip#\#}" != "$ip" ] && continue
            if [ -f "$DIRECT_IPS_FILE" ] && grep -qxF "$ip" "$DIRECT_IPS_FILE" 2>/dev/null; then
                continue
            fi
            if echo "$ip" | grep -q ':'; then
                ipset add $IPSET6_NAME "$ip" 2>/dev/null
            else
                ipset add $IPSET_NAME "$ip" 2>/dev/null
            fi
        done < "$IPS_FILE"
    fi
    
    # IPv4 rules
    iptables -t nat -N XRAY 2>/dev/null
    iptables -t nat -F XRAY
    
    # Skip VPN server IPs (extract from config)
    local server_ips=""
    if [ -f "$XRAY_CONF" ]; then
        server_ips=$(grep -oE '"address"[[:space:]]*:[[:space:]]*"[^"]*"' "$XRAY_CONF" | grep -oE '"[^"]*"$' | tr -d '"' | sort -u)
    fi
    for saddr in $server_ips; do
        # Resolve hostname to IP if needed
        local resolved=$(resolveip -4 "$saddr" 2>/dev/null | head -1)
        [ -z "$resolved" ] && resolved="$saddr"
        iptables -t nat -A XRAY -d "$resolved" -j RETURN 2>/dev/null
    done
    iptables -t nat -A XRAY -d 0.0.0.0/8 -j RETURN
    iptables -t nat -A XRAY -d 10.0.0.0/8 -j RETURN
    iptables -t nat -A XRAY -d 127.0.0.0/8 -j RETURN
    iptables -t nat -A XRAY -d 169.254.0.0/16 -j RETURN
    iptables -t nat -A XRAY -d 172.16.0.0/12 -j RETURN
    iptables -t nat -A XRAY -d 192.168.0.0/16 -j RETURN
    iptables -t nat -A XRAY -d 224.0.0.0/4 -j RETURN
    iptables -t nat -A XRAY -d 240.0.0.0/4 -j RETURN
    
    # Full VPN devices - redirect ALL traffic
    if [ -s "$FULLVPN_FILE" ]; then
        while IFS= read -r mac || [ -n "$mac" ]; do
            [ -z "$mac" ] && continue
            [ "${mac#\#}" != "$mac" ] && continue
            iptables -t nat -A XRAY -p tcp -m mac --mac-source "$mac" -j REDIRECT --to-port $REDIR_PORT
        done < "$FULLVPN_FILE"
    fi
    
    # Selective: only ipset
    iptables -t nat -A XRAY -p tcp -m set --match-set $IPSET_NAME dst -j REDIRECT --to-port $REDIR_PORT
    
    # Hook into PREROUTING (from LAN)
    iptables -t nat -C PREROUTING -p tcp -i br0 -j XRAY 2>/dev/null || \
    iptables -t nat -I PREROUTING -p tcp -i br0 -j XRAY
    
    # IPv6 rules
    ip6tables -t nat -N XRAY6 2>/dev/null
    ip6tables -t nat -F XRAY6
    ip6tables -t nat -A XRAY6 -d ::1/128 -j RETURN
    ip6tables -t nat -A XRAY6 -d fc00::/7 -j RETURN
    ip6tables -t nat -A XRAY6 -d fe80::/10 -j RETURN
    ip6tables -t nat -A XRAY6 -d ff00::/8 -j RETURN
    
    # Full VPN devices IPv6
    if [ -s "$FULLVPN_FILE" ]; then
        while IFS= read -r mac || [ -n "$mac" ]; do
            [ -z "$mac" ] && continue
            [ "${mac#\#}" != "$mac" ] && continue
            ip6tables -t nat -A XRAY6 -p tcp -m mac --mac-source "$mac" -j REDIRECT --to-port $REDIR_PORT
        done < "$FULLVPN_FILE"
    fi
    
    ip6tables -t nat -A XRAY6 -p tcp -m set --match-set $IPSET6_NAME dst -j REDIRECT --to-port $REDIR_PORT 2>/dev/null
    
    ip6tables -t nat -C PREROUTING -p tcp -i br0 -j XRAY6 2>/dev/null || \
    ip6tables -t nat -I PREROUTING -p tcp -i br0 -j XRAY6 2>/dev/null
    
    log "Firewall rules applied"
}

# Remove firewall rules
cleanup_firewall() {
    iptables -t nat -D PREROUTING -p tcp -i br0 -j XRAY 2>/dev/null
    iptables -t nat -F XRAY 2>/dev/null
    iptables -t nat -X XRAY 2>/dev/null
    ip6tables -t nat -D PREROUTING -p tcp -i br0 -j XRAY6 2>/dev/null
    ip6tables -t nat -F XRAY6 2>/dev/null
    ip6tables -t nat -X XRAY6 2>/dev/null
    log "Firewall rules removed"
}

# Update subscription (download & re-parse)
update_subscriptions() {
    log "Updating subscriptions..."
    if [ -f "$SUBS_FILE" ]; then
        local count=$(jsonfilter -i "$SUBS_FILE" -t '@.length' 2>/dev/null || echo 0)
        local i=0
        while [ "$i" -lt "$count" ] 2>/dev/null; do
            local url=$(jsonfilter -i "$SUBS_FILE" -e "@[$i].url" 2>/dev/null)
            local name=$(jsonfilter -i "$SUBS_FILE" -e "@[$i].name" 2>/dev/null)
            if [ -n "$url" ] && [ "$url" != "" ]; then
                log "Fetching subscription: $name"
                local content=$(/opt/bin/curl -s --max-time 15 "$url" 2>/dev/null)
                if [ -n "$content" ]; then
                    # Decode base64 subscription
                    local decoded=$(echo "$content" | base64 -d 2>/dev/null)
                    if [ -n "$decoded" ]; then
                        echo "$decoded" > "$XRAY_DIR/subscriptions/sub_${i}.txt"
                        log "Subscription $name updated"
                    fi
                fi
            fi
            i=$((i+1))
        done
    fi
    generate_config
}

# Start xray
start() {
    if [ -f "$XRAY_PID" ] && kill -0 "$(cat "$XRAY_PID" 2>/dev/null)" 2>/dev/null; then
        log "Xray already running"
        return 0
    fi

    # Prefer existing config.json (generated by web UI via PHP).
    # Only fall back to shell generate_config if config is missing or invalid.
    if [ -f "$XRAY_CONF" ] && $XRAY_BIN run -test -config "$XRAY_CONF" >/dev/null 2>&1; then
        log "Using existing config (validated OK)"
    else
        log "Config missing or invalid — regenerating"
        generate_config || {
            log "ERROR: config generation failed"
            return 1
        }
    fi

    setup_firewall
    
    # Truncate old logs
    : > "$LOG_DIR/access.log"
    : > "$LOG_DIR/error.log"
    
    $XRAY_BIN run -config "$XRAY_CONF" &
    echo $! > "$XRAY_PID"
    sleep 2
    
    if kill -0 $(cat "$XRAY_PID") 2>/dev/null; then
        log "Xray started (PID: $(cat $XRAY_PID))"
        start_watchdog
    else
        log "ERROR: Xray failed to start"
        cat "$LOG_DIR/error.log" 2>/dev/null
        return 1
    fi
}

# Stop xray
stop() {
    stop_watchdog
    if [ -f "$XRAY_PID" ]; then
        kill $(cat "$XRAY_PID") 2>/dev/null
        rm -f "$XRAY_PID"
    fi
    killall xray 2>/dev/null
    cleanup_firewall
    log "Xray stopped"
}

# Restart
restart() {
    stop
    sleep 1
    start
}

# Status
status() {
    if [ -f "$XRAY_PID" ] && kill -0 $(cat "$XRAY_PID") 2>/dev/null; then
        echo "running|$(cat $XRAY_PID)"
    else
        echo "stopped"
    fi
}

case "$1" in
    start) start ;;
    stop) stop ;;
    restart) restart ;;
    status) status ;;
    generate) generate_config ;;
    firewall) setup_firewall ;;
    update-subs) update_subscriptions ;;
    start_watchdog) start_watchdog ;;
    stop_watchdog) stop_watchdog ;;
    *) echo "Usage: $0 {start|stop|restart|status|generate|firewall|update-subs|start_watchdog|stop_watchdog}" ;;
esac
