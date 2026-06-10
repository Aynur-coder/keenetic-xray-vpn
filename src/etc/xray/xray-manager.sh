#!/bin/sh
# Xray VPN Manager for Keenetic
# Manages config generation, iptables, ipset, subscriptions

XRAY_BIN="/opt/sbin/xray"
XRAY_DIR="/opt/etc/xray"
XRAY_CONF="$XRAY_DIR/config.json"
XRAY_PID="/opt/var/run/xray.pid"
RULES_DIR="$XRAY_DIR/rules"
SUBS_FILE="$XRAY_DIR/subscriptions/list.json"
LOG_DIR="/opt/var/log/xray"
DOMAINS_FILE="$RULES_DIR/domains.txt"
IPS_FILE="$RULES_DIR/ips.txt"
FULLVPN_FILE="$RULES_DIR/fullvpn_devices.txt"
IPSET_NAME="vpn1"
IPSET6_NAME="vpn6"
REDIR_PORT=1080

log() { logger -t xray-mgr "$1"; echo "$1"; }

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

# Parse vless:// link -> JSON outbound
parse_vless_link() {
    local link="$1"
    local uuid=$(echo "$link" | sed 's|vless://||' | sed 's|@.*||')
    local rest=$(echo "$link" | sed 's|vless://[^@]*@||')
    local host=$(echo "$rest" | cut -d: -f1)
    local port=$(echo "$rest" | cut -d: -f2 | cut -d'?' -f1)
    local params=$(echo "$rest" | cut -d'?' -f2 | cut -d'#' -f1)
    local security=$(echo "$params" | tr '&' '\n' | grep '^security=' | cut -d= -f2)
    local type=$(echo "$params" | tr '&' '\n' | grep '^type=' | cut -d= -f2)
    local sni=$(echo "$params" | tr '&' '\n' | grep '^sni=' | cut -d= -f2)
    local fp=$(echo "$params" | tr '&' '\n' | grep '^fp=' | cut -d= -f2)
    local pbk=$(echo "$params" | tr '&' '\n' | grep '^pbk=' | cut -d= -f2)
    local sid=$(echo "$params" | tr '&' '\n' | grep '^sid=' | cut -d= -f2)
    local flow=$(echo "$params" | tr '&' '\n' | grep '^flow=' | cut -d= -f2)
    echo "VLESS|$uuid|$host|$port|$security|$type|$sni|$fp|$pbk|$sid|$flow"
}

# Generate xray config from subscriptions + rules
generate_config() {
    log "Generating xray config..."
    
    local outbounds=""
    local active_tag="proxy"
    local idx=0
    
    # Parse subscriptions
    if [ -f "$SUBS_FILE" ]; then
        local count=$(jsonfilter -i "$SUBS_FILE" -t '@.length' 2>/dev/null || echo 0)
        local i=0
        while [ "$i" -lt "$count" ] 2>/dev/null; do
            local enabled=$(jsonfilter -i "$SUBS_FILE" -e "@[$i].enabled" 2>/dev/null)
            if [ "$enabled" = "true" ]; then
                local link=$(jsonfilter -i "$SUBS_FILE" -e "@[$i].link" 2>/dev/null)
                local name=$(jsonfilter -i "$SUBS_FILE" -e "@[$i].name" 2>/dev/null)
                local type=$(echo "$link" | cut -d: -f1)
                
                if [ "$type" = "ss" ]; then
                    local server=$(parse_ss_link "$link")
                    local tag="proxy-$i"
                    if [ $idx -eq 0 ]; then active_tag="$tag"; fi
                    if [ -n "$outbounds" ]; then outbounds="$outbounds,"; fi
                    outbounds="$outbounds{\"tag\":\"$tag\",\"protocol\":\"shadowsocks\",\"settings\":{\"servers\":[$server]}}"
                    idx=$((idx+1))
                fi
            fi
            i=$((i+1))
        done
    fi
    
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
    
    # Build full-vpn source IPs (MAC->IP)
    local fullvpn_sources=""
    if [ -s "$FULLVPN_FILE" ]; then
        while IFS= read -r mac || [ -n "$mac" ]; do
            [ -z "$mac" ] && continue
            [ "${mac#\#}" != "$mac" ] && continue
            local ip=$(ip neigh | grep -i "$mac" | awk '{print $1}' | head -1)
            if [ -n "$ip" ]; then
                if [ -n "$fullvpn_sources" ]; then fullvpn_sources="$fullvpn_sources,"; fi
                fullvpn_sources="$fullvpn_sources\"$ip\""
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

# Setup iptables + ipset
setup_firewall() {
    log "Setting up firewall rules..."
    
    # Create ipsets
    ipset create $IPSET_NAME hash:net family inet 2>/dev/null
    ipset create $IPSET6_NAME hash:net family inet6 2>/dev/null
    
    # Flush ipset
    ipset flush $IPSET_NAME 2>/dev/null
    ipset flush $IPSET6_NAME 2>/dev/null
    
    # Add IPs from file
    if [ -s "$IPS_FILE" ]; then
        while IFS= read -r ip || [ -n "$ip" ]; do
            [ -z "$ip" ] && continue
            [ "${ip#\#}" != "$ip" ] && continue
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
    if [ -f "$XRAY_PID" ] && kill -0 $(cat "$XRAY_PID") 2>/dev/null; then
        log "Xray already running"
        return 0
    fi
    
    generate_config || return 1
    setup_firewall
    
    # Truncate old logs
    : > "$LOG_DIR/access.log"
    : > "$LOG_DIR/error.log"
    
    $XRAY_BIN run -config "$XRAY_CONF" &
    echo $! > "$XRAY_PID"
    sleep 2
    
    if kill -0 $(cat "$XRAY_PID") 2>/dev/null; then
        log "Xray started (PID: $(cat $XRAY_PID))"
    else
        log "ERROR: Xray failed to start"
        cat "$LOG_DIR/error.log" 2>/dev/null
        return 1
    fi
}

# Stop xray
stop() {
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
    *) echo "Usage: $0 {start|stop|restart|status|generate|firewall|update-subs}" ;;
esac
