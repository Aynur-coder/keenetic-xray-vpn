<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$XRAY_DIR = '/opt/etc/xray';
$RULES_DIR = "$XRAY_DIR/rules";
$SUBS_FILE = "$XRAY_DIR/subscriptions/list.json";
$KEYS_FILE = "$XRAY_DIR/subscriptions/keys.json";
$CACHED_FILE = "$XRAY_DIR/subscriptions/cached_servers.json";
$DOMAINS_FILE = "$RULES_DIR/domains.txt";
$IPS_FILE = "$RULES_DIR/ips.txt";
$FULLVPN_FILE = "$RULES_DIR/fullvpn_devices.txt";
$GITHUB_LISTS_FILE = "$RULES_DIR/github_lists.json";
$XRAY_CONF = "$XRAY_DIR/config.json";
$STATE_FILE = "$XRAY_DIR/state.json";
$LOG_ACCESS = '/opt/var/log/xray/access.log';
$LOG_ERROR = '/opt/var/log/xray/error.log';
$MANAGER = "$XRAY_DIR/xray-manager.sh";
$AGH_CONF = '/opt/etc/AdGuardHome/AdGuardHome.yaml';
$WG_DIR = '/opt/etc/wireguard';
$WG_CONF = "$WG_DIR/wg0.conf";
$SS_DOWNLOADER = '/opt/usr/bin/ss-downloader';
$CATALOG_FILE = '/opt/etc/shadowsocks.d/catalog.json';
$V2FLY_LISTS_DIR = '/opt/etc/shadowsocks.d/lists';
$UI_AUTH_FILE = "$XRAY_DIR/ui-auth.json";
$FEATURES_FILE = "$XRAY_DIR/features.json";
$ONBOARDED_FILE = "$XRAY_DIR/.onboarded";
$KN_PASS_FILE = "$XRAY_DIR/.kn_pass";
$LOGIN_ATTEMPTS_FILE = '/opt/tmp/xray-login-attempts.json';
$VERSION_FILE = "$XRAY_DIR/.version";

function json_read($f) {
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function json_write($f, $d) {
    file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function lines_read($f) {
    if (!file_exists($f)) return [];
    return array_values(array_filter(array_map('trim', file($f)), function($l) {
        return $l !== '' && (!isset($l[0]) || $l[0] !== '#');
    }));
}

function lines_write($f, $lines) {
    file_put_contents($f, implode("\n", $lines) . "\n");
}

function shell_run($cmd) {
    return trim(shell_exec($cmd . ' 2>&1') ?? '');
}

// ============================================================================
// AUTH / SESSIONS / ONBOARDING / FEATURES
// ============================================================================

function _auth_cfg() {
    global $UI_AUTH_FILE;
    $c = json_read($UI_AUTH_FILE);
    return [
        'algo' => $c['algo'] ?? 'sha256',
        'salt' => $c['salt'] ?? '',
        'hash' => $c['hash'] ?? '',
        'skip_local' => $c['skip_local'] ?? true,
        'trusted_subnets' => $c['trusted_subnets'] ?? ['192.168.1.0/24', '192.168.0.0/24', '10.50.0.0/24'],
    ];
}

function _is_ipv4($s) {
    return is_string($s) && preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', $s)
        && !preg_match('/(?:^|\.)(?:25[6-9]|2[6-9]\d|[3-9]\d{2})(?:\.|$)/', $s);
}

function _ip_in_subnet($ip, $cidr) {
    if (strpos($cidr, '/') === false) $cidr .= '/32';
    list($net, $bits) = explode('/', $cidr, 2);
    $bits = (int)$bits;
    if (!_is_ipv4($ip) || !_is_ipv4($net)) return false;
    $ip_l = ip2long($ip);
    $net_l = ip2long($net);
    if ($ip_l === false || $net_l === false) return false;
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
    return ($ip_l & $mask) === ($net_l & $mask);
}

function is_local_request() {
    $cfg = _auth_cfg();
    if (!$cfg['skip_local']) return false;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach ($cfg['trusted_subnets'] as $s) {
        if (_ip_in_subnet($ip, $s)) return true;
    }
    return false;
}

function _start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('xrayvpn');
        session_set_cookie_params([
            'lifetime' => 7 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function is_authenticated() {
    if (is_local_request()) return true;
    _start_session();
    return !empty($_SESSION['authed']);
}

function require_auth() {
    if (is_authenticated()) return true;
    http_response_code(401);
    echo json_encode(['error' => 'auth_required']);
    exit;
}

function _hash_pass($pass, $salt) {
    return hash('sha256', $salt . ':' . $pass);
}

function set_ui_password($pass) {
    global $UI_AUTH_FILE;
    if (strlen($pass) < 4) return ['error' => 'password_too_short'];
    $cfg = _auth_cfg();
    if (!$cfg['salt']) $cfg['salt'] = bin2hex(random_bytes(16));
    $cfg['algo'] = 'sha256';
    $cfg['hash'] = _hash_pass($pass, $cfg['salt']);
    json_write($UI_AUTH_FILE, $cfg);
    @chmod($UI_AUTH_FILE, 0600);
    return ['ok' => true];
}

function check_login($pass) {
    $cfg = _auth_cfg();
    if (!$cfg['hash']) return false;
    return hash_equals($cfg['hash'], _hash_pass($pass, $cfg['salt']));
}

function _login_attempts_check() {
    global $LOGIN_ATTEMPTS_FILE;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = preg_replace('/[^0-9a-fA-F.:]/', '', $ip);
    $now = time();
    $data = json_read($LOGIN_ATTEMPTS_FILE);
    $entry = $data[$key] ?? ['attempts' => [], 'blocked_until' => 0];
    if (($entry['blocked_until'] ?? 0) > $now) {
        return ['blocked' => true, 'retry_after' => $entry['blocked_until'] - $now];
    }
    $entry['attempts'] = array_values(array_filter($entry['attempts'] ?? [], fn($t) => $t > $now - 3600));
    return ['blocked' => false, 'remaining' => max(0, 5 - count($entry['attempts']))];
}

function _login_attempts_record_fail() {
    global $LOGIN_ATTEMPTS_FILE;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = preg_replace('/[^0-9a-fA-F.:]/', '', $ip);
    $now = time();
    $data = json_read($LOGIN_ATTEMPTS_FILE);
    $entry = $data[$key] ?? ['attempts' => [], 'blocked_until' => 0];
    $entry['attempts'][] = $now;
    if (count($entry['attempts']) >= 5) {
        $entry['blocked_until'] = $now + 3600;
    }
    $data[$key] = $entry;
    json_write($LOGIN_ATTEMPTS_FILE, $data);
}

function _login_attempts_reset() {
    global $LOGIN_ATTEMPTS_FILE;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = preg_replace('/[^0-9a-fA-F.:]/', '', $ip);
    $data = json_read($LOGIN_ATTEMPTS_FILE);
    unset($data[$key]);
    json_write($LOGIN_ATTEMPTS_FILE, $data);
}

function get_features() {
    global $FEATURES_FILE;
    $d = json_read($FEATURES_FILE);
    return [
        'wireguard'   => $d['wireguard']   ?? true,
        'adguard'     => $d['adguard']     ?? true,
        'auto_update' => $d['auto_update'] ?? false,
        'theme'       => $d['theme']       ?? 'auto',
    ];
}

function set_features_patch($patch) {
    global $FEATURES_FILE;
    $cur = get_features();
    foreach (['wireguard', 'adguard', 'auto_update'] as $k) {
        if (isset($patch[$k])) $cur[$k] = (bool)$patch[$k];
    }
    if (isset($patch['theme']) && in_array($patch['theme'], ['auto', 'dark', 'light'], true)) {
        $cur['theme'] = $patch['theme'];
    }
    json_write($FEATURES_FILE, $cur);

    // Side effects: turn WG on/off
    if (isset($patch['wireguard'])) {
        if ($patch['wireguard']) {
            shell_run('/opt/etc/init.d/S99wireguard start 2>/dev/null');
        } else {
            shell_run('ip link set wg0 down 2>/dev/null; ip link delete wg0 2>/dev/null');
        }
    }
    return $cur;
}

function get_onboarding_status() {
    global $ONBOARDED_FILE, $KN_PASS_FILE, $SUBS_FILE, $KEYS_FILE, $CACHED_FILE;
    $auth = _auth_cfg();
    return [
        'onboarded'        => file_exists($ONBOARDED_FILE),
        'ui_password_set'  => !empty($auth['hash']),
        'kn_pass_set'      => file_exists($KN_PASS_FILE) && filesize($KN_PASS_FILE) > 0,
        'has_subscription' => count(json_read($SUBS_FILE)) > 0,
        'has_keys'         => count(json_read($KEYS_FILE)) > 0,
        'has_servers'      => count(json_read($CACHED_FILE)) > 0,
        'features'         => get_features(),
    ];
}

function complete_onboarding() {
    global $ONBOARDED_FILE;
    @file_put_contents($ONBOARDED_FILE, date('c'));
    return ['ok' => true];
}

function set_kn_password($pass) {
    global $KN_PASS_FILE;
    @file_put_contents($KN_PASS_FILE, $pass);
    @chmod($KN_PASS_FILE, 0600);
    return ['ok' => true];
}

function test_kn_password($pass) {
    global $KN_PASS_FILE;
    $had_old = file_exists($KN_PASS_FILE);
    $old = $had_old ? @file_get_contents($KN_PASS_FILE) : null;
    @file_put_contents($KN_PASS_FILE, $pass);
    @chmod($KN_PASS_FILE, 0600);
    $devs = keenetic_get_devices();
    $ok = is_array($devs) && count($devs) > 0;
    if (!$ok) {
        if ($had_old !== false && $old !== false) @file_put_contents($KN_PASS_FILE, $old);
        elseif (!$had_old) @unlink($KN_PASS_FILE);
    }
    return [
        'ok' => $ok,
        'count' => is_array($devs) ? count($devs) : 0,
        'sample' => is_array($devs) ? array_slice($devs, 0, 3) : [],
    ];
}

function get_installed_version() {
    global $VERSION_FILE;
    return file_exists($VERSION_FILE) ? trim(@file_get_contents($VERSION_FILE)) : 'dev';
}

function parse_vless_link($link) {
    $name = 'VLESS';
    if (preg_match('/#(.+)$/', $link, $nm)) {
        $name = urldecode($nm[1]);
        $link = preg_replace('/#.*$/', '', $link);
    }
    $link = urldecode($link);
    if (!preg_match('/^vless:\/\/([^@]+)@([^:]+):(\d+)\??(.*)$/', $link, $m)) return null;
    $params = [];
    parse_str($m[4] ?? '', $params);
    return [
        'uuid' => $m[1], 'address' => $m[2], 'port' => (int)$m[3],
        'security' => $params['security'] ?? 'none',
        'type' => $params['type'] ?? 'tcp',
        'sni' => $params['sni'] ?? '',
        'fp' => $params['fp'] ?? 'chrome',
        'pbk' => $params['pbk'] ?? '',
        'sid' => $params['sid'] ?? '',
        'flow' => $params['flow'] ?? '',
        'host' => $params['host'] ?? '',
        'path' => $params['path'] ?? '',
        'mode' => $params['mode'] ?? '',
        'name' => $name
    ];
}

function parse_ss_link($link) {
    $link = preg_replace('/#.*$/', '', $link);
    $link = preg_replace('/\?.*@/', '@', $link);
    if (preg_match('/^ss:\/\/([^@]+)@(.+):(\d+)/', $link, $m)) {
        $decoded = base64_decode($m[1]);
        if (!$decoded && strpos($m[1], '%') !== false) $decoded = base64_decode(urldecode($m[1]));
        if ($decoded && preg_match('/^([^:]+):(.+)$/', $decoded, $dm)) {
            return ['address' => $m[2], 'port' => (int)$m[3], 'method' => $dm[1], 'password' => $dm[2]];
        }
    }
    return null;
}

function build_outbound_from_link($link, $tag) {
    if (strpos($link, 'vless://') === 0) {
        $v = parse_vless_link($link);
        if (!$v) return null;
        $out = [
            'tag' => $tag, 'protocol' => 'vless',
            'settings' => ['vnext' => [['address' => $v['address'], 'port' => $v['port'],
                'users' => [['id' => $v['uuid'], 'encryption' => 'none', 'flow' => $v['flow'] ?: '']]
            ]]],
            'streamSettings' => ['network' => $v['type'] ?: 'tcp', 'security' => $v['security'] ?: 'none']
        ];
        if ($v['security'] === 'reality') {
            $out['streamSettings']['realitySettings'] = [
                'serverName' => $v['sni'], 'fingerprint' => $v['fp'] ?: 'chrome',
                'publicKey' => $v['pbk'], 'shortId' => $v['sid'] ?: '', 'spiderX' => ''
            ];
        } elseif ($v['security'] === 'tls') {
            $out['streamSettings']['tlsSettings'] = [
                'serverName' => $v['sni'], 'fingerprint' => $v['fp'] ?: 'chrome'
            ];
        }
        if ($v['type'] === 'xhttp') {
            $out['streamSettings']['xhttpSettings'] = [];
            if (!empty($v['host'])) $out['streamSettings']['xhttpSettings']['host'] = [$v['host']];
            if (!empty($v['path'])) $out['streamSettings']['xhttpSettings']['path'] = $v['path'];
            if (!empty($v['mode'])) $out['streamSettings']['xhttpSettings']['mode'] = $v['mode'];
        }
        return $out;
    }
    if (strpos($link, 'ss://') === 0) {
        $s = parse_ss_link($link);
        if (!$s) return null;
        return ['tag' => $tag, 'protocol' => 'shadowsocks', 'settings' => ['servers' => [$s]]];
    }
    return null;
}

function fetch_subscription($url) {
    $content = shell_run("/opt/bin/curl -s --max-time 20 " . escapeshellarg($url));
    if (!$content) return [];
    $decoded = base64_decode($content);
    if ($decoded) $content = $decoded;
    return array_values(array_filter(explode("\n", $content), function($l) {
        $l = trim($l);
        return strpos($l, 'vless://') === 0 || strpos($l, 'ss://') === 0 ||
               strpos($l, 'trojan://') === 0 || strpos($l, 'vmess://') === 0;
    }));
}

function generate_xray_config() {
    global $XRAY_DIR, $XRAY_CONF, $KEYS_FILE, $CACHED_FILE, $DOMAINS_FILE, $IPS_FILE, $FULLVPN_FILE, $STATE_FILE;

    $outbounds = [];
    $server_ips = [];
    $active_tag = '';
    $state = json_read($STATE_FILE);
    $active_id = $state['active_outbound'] ?? '';

    $keys = json_read($KEYS_FILE);
    foreach ($keys as $k) {
        if (empty($k['enabled'])) continue;
        $tag = 'key-' . ($k['id'] ?? uniqid());
        $ob = build_outbound_from_link($k['link'], $tag);
        if ($ob) {
            $outbounds[] = $ob;
            $addr = $ob['settings']['servers'][0]['address'] ?? $ob['settings']['vnext'][0]['address'] ?? '';
            if ($addr) $server_ips[] = $addr;
            if ($active_id === $k['id'] || (!$active_tag && $active_id === '')) $active_tag = $tag;
        }
    }

    $cached = json_read($CACHED_FILE);
    foreach ($cached as $srv) {
        if (empty($srv['enabled'])) continue;
        $tag = 'sub-' . ($srv['id'] ?? uniqid());
        $ob = build_outbound_from_link($srv['link'], $tag);
        if ($ob) {
            $outbounds[] = $ob;
            $addr = $ob['settings']['servers'][0]['address'] ?? $ob['settings']['vnext'][0]['address'] ?? '';
            if ($addr) $server_ips[] = $addr;
            if ($active_id === $srv['id']) $active_tag = $tag;
        }
    }

    if (empty($outbounds)) return ['error' => 'No active outbounds'];
    if (!$active_tag) {
        foreach ($outbounds as $ob) {
            if ($ob['tag'] !== 'direct' && $ob['tag'] !== 'block') { $active_tag = $ob['tag']; break; }
        }
    }

    usort($outbounds, function($a, $b) use ($active_tag) {
        if ($a['tag'] === $active_tag) return -1;
        if ($b['tag'] === $active_tag) return 1;
        return 0;
    });

    $domains = all_domains();
    $ips_list = lines_read($IPS_FILE);
    $fullvpn_macs = lines_read($FULLVPN_FILE);

    $rules = [];
    $rules[] = ['type' => 'field', 'outboundTag' => 'direct', 'ip' => ['geoip:private']];
    foreach ($server_ips as $sip) {
        $resolved = gethostbyname($sip);
        $rules[] = ['type' => 'field', 'outboundTag' => 'direct', 'ip' => [$resolved !== $sip ? $resolved : $sip]];
    }

    if (!empty($fullvpn_macs)) {
        // Exclude FAILED/INCOMPLETE ARP entries (no IP yet)
        $arp_lines = array_filter(
            explode("\n", shell_run('ip neigh show dev br0')),
            fn($l) => !preg_match('/\b(FAILED|INCOMPLETE)\b/i', $l)
        );
        $arp = implode("\n", $arp_lines);

        $dhcp_leases = @file_get_contents('/tmp/dhcp.leases') ?: '';

        $fullvpn_ips = [];
        foreach ($fullvpn_macs as $mac) {
            $ip = '';
            // Method 1: ARP table (REACHABLE / STALE)
            if (preg_match('/^(\d+\.\d+\.\d+\.\d+).*' . preg_quote($mac, '/') . '/im', $arp, $am)) {
                $ip = $am[1];
            }
            // Method 2: DHCP leases file (mac is field 2, ip is field 3)
            if (!$ip && preg_match('/^\S+\s+' . preg_quote(strtolower($mac), '/') . '\s+(\d+\.\d+\.\d+\.\d+)/im', $dhcp_leases, $dm)) {
                $ip = $dm[1];
            }
            // Method 3: ndmc show ip hotspot
            if (!$ip) {
                $hotspot = shell_run('ndmc -c "show ip hotspot" 2>/dev/null');
                if (preg_match('/(\d+\.\d+\.\d+\.\d+).*' . preg_quote($mac, '/') . '/i', $hotspot, $hm)) {
                    $ip = $hm[1];
                }
            }
            if ($ip) $fullvpn_ips[] = $ip;
        }
        if (!empty($fullvpn_ips)) {
            $rules[] = ['type' => 'field', 'outboundTag' => $active_tag, 'source' => $fullvpn_ips];
        }
    }

    if (!empty($domains)) $rules[] = ['type' => 'field', 'outboundTag' => $active_tag, 'domain' => $domains];
    if (!empty($ips_list)) $rules[] = ['type' => 'field', 'outboundTag' => $active_tag, 'ip' => $ips_list];
    $rules[] = ['type' => 'field', 'outboundTag' => 'direct', 'network' => 'tcp,udp'];

    $outbounds[] = ['tag' => 'direct', 'protocol' => 'freedom'];
    $outbounds[] = ['tag' => 'block', 'protocol' => 'blackhole'];

    $config = [
        'log' => ['loglevel' => 'warning', 'access' => '/opt/var/log/xray/access.log', 'error' => '/opt/var/log/xray/error.log'],
        'inbounds' => [
            ['tag' => 'tproxy-in', 'port' => 1080, 'protocol' => 'dokodemo-door',
             'settings' => ['network' => 'tcp,udp', 'followRedirect' => true],
             'sniffing' => ['enabled' => true, 'destOverride' => ['http','tls','quic'], 'routeOnly' => true],
             'streamSettings' => ['sockopt' => ['tproxy' => 'redirect']]],
            ['tag' => 'socks-in', 'port' => 1081, 'listen' => '0.0.0.0', 'protocol' => 'socks',
             'settings' => ['auth' => 'noauth', 'udp' => true],
             'sniffing' => ['enabled' => true, 'destOverride' => ['http','tls','quic'], 'routeOnly' => true]],
            ['tag' => 'http-in', 'port' => 1082, 'listen' => '0.0.0.0', 'protocol' => 'http']
        ],
        'outbounds' => $outbounds,
        'routing' => ['domainStrategy' => 'IPIfNonMatch', 'rules' => $rules]
    ];

    file_put_contents($XRAY_CONF, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return ['ok' => true, 'active' => $active_tag, 'outbounds' => count($outbounds) - 2];
}

function all_domains() {
    global $DOMAINS_FILE, $GITHUB_LISTS_FILE, $V2FLY_LISTS_DIR;
    $manual = lines_read($DOMAINS_FILE);
    $lists = json_read($GITHUB_LISTS_FILE);
    $extra = [];
    foreach ($lists as $l) {
        if (empty($l['enabled'])) continue;
        if (($l['source'] ?? '') === 'v2fly' && !empty($l['name'])) {
            $f = "$V2FLY_LISTS_DIR/{$l['name']}.txt";
            if (file_exists($f)) {
                $extra = array_merge($extra, array_filter(array_map('trim', file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)), fn($x) => $x !== '' && $x[0] !== '#'));
            }
        }
    }
    return array_values(array_unique(array_merge($manual, $extra)));
}

function quick_apply() {
    global $MANAGER;
    $r = generate_xray_config();
    if (isset($r['error'])) return;
    shell_run('killall xray 2>/dev/null; sleep 1');
    shell_run("$MANAGER firewall 2>/dev/null");
    shell_run('xray run -config /opt/etc/xray/config.json > /dev/null 2>&1 & echo $! > /opt/var/run/xray.pid');
    reload_adguard();
    warmup_ipset();
}

function reload_adguard() {
    shell_run('/opt/etc/init.d/S99adguardhome restart 2>/dev/null');
}

function warmup_ipset() {
    shell_exec('pkill -f vpn_warmup 2>/dev/null');
    $domains = all_domains();
    $tmpfile = '/tmp/vpn_warmup.txt';
    file_put_contents($tmpfile, implode("\n", $domains) . "\n");
    shell_exec("nohup sh -c 'sleep 12; while read d; do dig @127.0.0.1 \"\$d\" +short A +timeout=2 +tries=1 >/dev/null 2>&1; done < $tmpfile; rm -f $tmpfile' >/dev/null 2>&1 &");
}

function update_adguard_ipset() {
    global $AGH_CONF;
    if (!file_exists($AGH_CONF)) return;
    $domains = all_domains();
    $yaml = file_get_contents($AGH_CONF);
    $entries = [];
    foreach ($domains as $d) $entries[] = "    - $d/vpn1";
    $ipset_block = "  ipset:\n" . implode("\n", $entries) . "\n  ipset_file:";
    $yaml = preg_replace('/  ipset:\n(    - .+\n)*  ipset_file:/', $ipset_block, $yaml);
    file_put_contents($AGH_CONF, $yaml);
}

function keenetic_get_devices() {
    // Keenetic NDW2 auth: 2-step challenge + SHA256
    $pass = trim(@file_get_contents('/opt/etc/xray/.kn_pass') ?: '');
    if (!$pass) return keenetic_fallback_devices();

    // Step 1: get challenge
    $ch = curl_init('http://192.168.1.1/auth');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HEADER => true]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $challenge = $session_id = $cookie_name = $cookie_val = '';
    if (preg_match('/X-NDM-Challenge:\s*(\S+)/i', $resp, $m)) $challenge = $m[1];
    if (preg_match('/Set-Cookie:\s*(\w+)=(\w+)/i', $resp, $m)) { $cookie_name = $m[1]; $cookie_val = $m[2]; }
    if (preg_match('/session_id="([^"]+)"/', $resp, $m)) $session_id = $m[1];

    if (!$challenge || !$cookie_val) return keenetic_fallback_devices();

    // Step 2: SHA256(challenge + MD5(admin:realm:password))
    $realm = 'Keenetic Hopper';
    if (preg_match('/realm="([^"]+)"/', $resp, $m)) $realm = $m[1];
    $inner = md5('admin:' . $realm . ':' . $pass);
    $token = hash('sha256', $challenge . $inner);

    $ch = curl_init('http://192.168.1.1/auth');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['login' => 'admin', 'password' => $token]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Cookie: $cookie_name=$cookie_val"],
        CURLOPT_HEADER => true,
    ]);
    $resp2 = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) return keenetic_fallback_devices();

    // Step 3: get devices
    $ch = curl_init('http://192.168.1.1/rci/show/ip/hotspot');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ["Cookie: $cookie_name=$cookie_val"],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    if (!is_array($data)) return keenetic_fallback_devices();

    $devices = [];
    if (isset($data['host'])) {
        foreach ($data['host'] as $h) {
            $devices[] = [
                'ip' => $h['ip'] ?? '', 'mac' => strtoupper($h['mac'] ?? ''),
                'hostname' => $h['name'] ?? $h['hostname'] ?? '',
                'active' => !empty($h['active']),
            ];
        }
    }
    return $devices;
}

function keenetic_fallback_devices() {
    $arp = shell_run('ip neigh show dev br0');
    $dhcp = shell_run('cat /tmp/dhcp.leases 2>/dev/null');
    $devices = [];
    if (preg_match_all('/(\d+\.\d+\.\d+\.\d+).*?(([0-9a-f]{2}:){5}[0-9a-f]{2})/i', $arp, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $hostname = '';
            if (preg_match('/' . preg_quote($m[2], '/') . '\s+\S+\s+(\S+)/i', $dhcp, $hm)) $hostname = $hm[1];
            $devices[] = ['ip' => $m[1], 'mac' => strtoupper($m[2]), 'hostname' => $hostname, 'active' => true];
        }
    }
    return $devices;
}

function fetch_github_domains($url) {
    $content = shell_run("/opt/bin/curl -sL --max-time 15 " . escapeshellarg($url));
    if (!$content) return [];
    $domains = [];
    foreach (explode("\n", $content) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === '!') continue;
        if (preg_match('/^\|\|([a-z0-9][\w\-.]+\.[a-z]{2,})\^?$/i', $line, $m)) {
            $domains[] = strtolower($m[1]);
        } elseif (preg_match('/^(?:server|address)=\/([^\/]+)\//i', $line, $m)) {
            $domains[] = strtolower($m[1]);
        } elseif (preg_match('/^(?:0\.0\.0\.0|127\.0\.0\.1)\s+([a-z0-9][\w\-.]+\.[a-z]{2,})$/i', $line, $m)) {
            $domains[] = strtolower($m[1]);
        } elseif (preg_match('/^\*?\.?([a-z0-9][\w\-.]+\.[a-z]{2,})$/i', $line, $m)) {
            $domains[] = strtolower($m[1]);
        }
    }
    return array_unique($domains);
}

function wg_list_peers() {
    global $WG_DIR, $WG_CONF;
    if (!file_exists($WG_CONF)) return [];
    $content = file_get_contents($WG_CONF);
    $configs = glob("$WG_DIR/*.conf");
    $client_map = [];
    foreach ($configs as $cf) {
        $bn = basename($cf, '.conf');
        if ($bn === 'wg0') continue;
        $cc = file_get_contents($cf);
        if (preg_match('/PrivateKey\s*=\s*(.+)/', $cc, $m)) {
            $pk = trim(shell_run("echo " . escapeshellarg(trim($m[1])) . " | /opt/bin/wg pubkey 2>/dev/null"));
            if ($pk) $client_map[$pk] = $bn;
        }
    }

    $status = shell_run("/opt/bin/wg show wg0 2>/dev/null");
    $peers = [];
    if (preg_match_all('/\[Peer\]\s*\n((?:[^\[]+?)(?=\[|$))/s', $content, $pm)) {
        foreach ($pm[1] as $block) {
            $pubkey = ''; $allowed_ips = '';
            if (preg_match('/PublicKey\s*=\s*(.+)/', $block, $m)) $pubkey = trim($m[1]);
            if (preg_match('/AllowedIPs\s*=\s*(.+)/', $block, $m)) $allowed_ips = trim($m[1]);
            $name = $client_map[$pubkey] ?? '';
            $ip = preg_replace('/\/\d+$/', '', $allowed_ips);
            $last_handshake = ''; $rx = ''; $tx = '';
            if ($pubkey && preg_match('/peer:\s*' . preg_quote($pubkey, '/') . '\s+(.*?)(?=peer:|$)/s', $status, $sm)) {
                if (preg_match('/latest handshake:\s*(.+)/', $sm[1], $hm)) $last_handshake = trim($hm[1]);
                if (preg_match('/transfer:\s*([\d.]+\s+\w+)\s+received,\s*([\d.]+\s+\w+)\s+sent/', $sm[1], $tm)) {
                    $rx = $tm[1]; $tx = $tm[2];
                }
            }
            $peers[] = [
                'name' => $name, 'pubkey' => $pubkey, 'allowed_ips' => $allowed_ips,
                'ip' => $ip, 'last_handshake' => $last_handshake,
                'rx' => $rx, 'tx' => $tx, 'has_config' => !empty($name),
            ];
        }
    }
    return $peers;
}

function wg_get_next_ip() {
    global $WG_CONF;
    $content = file_get_contents($WG_CONF);
    $max_ip = 1;
    if (preg_match_all('/AllowedIPs\s*=\s*10\.50\.0\.(\d+)/', $content, $m))
        $max_ip = max(array_map('intval', $m[1]));
    return '10.50.0.' . ($max_ip + 1);
}

function wg_get_server_pubkey() {
    global $WG_CONF;
    $content = file_get_contents($WG_CONF);
    if (preg_match('/PrivateKey\s*=\s*(.+)/', $content, $m))
        return trim(shell_run("echo " . escapeshellarg(trim($m[1])) . " | /opt/bin/wg pubkey 2>/dev/null"));
    return '';
}

function wg_get_endpoint() {
    global $WG_CONF;
    $ip = shell_run('/opt/bin/curl -s --max-time 5 http://api.ipify.org 2>/dev/null');
    $content = file_get_contents($WG_CONF);
    $port = '500';
    if (preg_match('/ListenPort\s*=\s*(\d+)/', $content, $m)) $port = $m[1];
    return ($ip ?: '95.105.78.232') . ':' . $port;
}

function wg_add_peer($name) {
    global $WG_DIR, $WG_CONF;
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    if (!$name) return ['error' => 'Invalid name'];
    $conf_file = "$WG_DIR/$name.conf";
    if (file_exists($conf_file)) return ['error' => 'Client already exists'];

    $privkey = trim(shell_run("/opt/bin/wg genkey"));
    $pubkey = trim(shell_run("echo " . escapeshellarg($privkey) . " | /opt/bin/wg pubkey"));
    if (!$privkey || !$pubkey) return ['error' => 'Failed to generate keys'];

    $client_ip = wg_get_next_ip();
    $server_pubkey = wg_get_server_pubkey();
    $endpoint = wg_get_endpoint();

    file_put_contents($WG_CONF, file_get_contents($WG_CONF) . "\n[Peer]\nPublicKey = $pubkey\nAllowedIPs = $client_ip/32\n");
    $client_conf = "[Interface]\nPrivateKey = $privkey\nAddress = $client_ip/24\nDNS = 192.168.1.1\nMTU = 1400\n\n[Peer]\nPublicKey = $server_pubkey\nEndpoint = $endpoint\nAllowedIPs = 0.0.0.0/0, ::/0\nPersistentKeepalive = 25\n";
    file_put_contents($conf_file, $client_conf);
    shell_run("/opt/bin/wg set wg0 peer $pubkey allowed-ips $client_ip/32 2>/dev/null");

    return ['ok' => true, 'name' => $name, 'ip' => $client_ip];
}

function wg_delete_peer($name) {
    global $WG_DIR, $WG_CONF;
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    $conf_file = "$WG_DIR/$name.conf";
    if (file_exists($conf_file)) {
        $cc = file_get_contents($conf_file);
        if (preg_match('/PrivateKey\s*=\s*(.+)/', $cc, $m)) {
            $pubkey = trim(shell_run("echo " . escapeshellarg(trim($m[1])) . " | /opt/bin/wg pubkey 2>/dev/null"));
            if ($pubkey) {
                $wg = file_get_contents($WG_CONF);
                $wg = preg_replace('/\n\[Peer\]\s*\nPublicKey\s*=\s*' . preg_quote($pubkey, '/') . '\s*\n[^\[]*/', '', $wg);
                file_put_contents($WG_CONF, $wg);
                shell_run("/opt/bin/wg set wg0 peer $pubkey remove 2>/dev/null");
            }
        }
        unlink($conf_file);
    }
    foreach (["$WG_DIR/{$name}_private.key", "$WG_DIR/{$name}_public.key"] as $kf) {
        if (file_exists($kf)) unlink($kf);
    }
    return ['ok' => true];
}

function wg_get_client_config($name) {
    global $WG_DIR;
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    $conf_file = "$WG_DIR/$name.conf";
    if (!file_exists($conf_file)) return ['error' => 'Config not found'];
    return ['ok' => true, 'config' => file_get_contents($conf_file), 'name' => $name];
}

// ===== API Router =====
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Read-only actions are public on local network (always require auth from outside).
// Mutating actions go through require_auth() which short-circuits with 401 if no session.
$PUBLIC_READ_ACTIONS = [
    'status', 'login', 'logout', 'auth_status',
    'get_onboarding_status', 'get_features', 'get_version',
    'check_update', 'status_update',
    'keys', 'subscriptions', 'subscription_servers',
    'domains', 'ips', 'devices', 'lan_devices',
    'github_lists', 'v2fly_search',
    'wg_peers', 'logs', 'raw_config',
];
if (!in_array($action, $PUBLIC_READ_ACTIONS, true)) {
    require_auth();
}

switch ($action) {

case 'status':
    $pid = shell_run('cat /opt/var/run/xray.pid 2>/dev/null');
    $running = $pid && shell_run("kill -0 $pid 2>/dev/null; echo \$?") === '0';
    $state = json_read($STATE_FILE);
    $mem = shell_run("free -m | awk '/Mem:/{print \$2,\$3,\$4}'");
    $mp = explode(' ', $mem);
    $wg_up = shell_run("/opt/bin/wg show wg0 2>/dev/null | head -1") !== '';
    $features = get_features();
    echo json_encode([
        'running' => $running, 'pid' => $pid,
        'active_outbound' => $state['active_outbound'] ?? '',
        'mode' => $state['mode'] ?? 'selective',
        'mem_total' => (int)(($mp[0] ?? 0) / 1024), 'mem_used' => (int)(($mp[1] ?? 0) / 1024),
        'uptime' => shell_run('uptime'),
        'external_ip' => shell_run('/opt/bin/curl -s --max-time 5 --socks5-hostname 127.0.0.1:1081 http://api.ipify.org 2>/dev/null'),
        'real_ip' => shell_run('/opt/bin/curl -s --max-time 5 http://api.ipify.org 2>/dev/null'),
        'wg_up' => $wg_up,
        'onboarded' => file_exists($ONBOARDED_FILE),
        'authenticated' => is_authenticated(),
        'local' => is_local_request(),
        'features' => $features,
        'version' => get_installed_version(),
    ]);
    break;

case 'start':
    update_adguard_ipset();
    $r = generate_xray_config();
    if (isset($r['error'])) { echo json_encode($r); break; }
    shell_run('killall xray 2>/dev/null; sleep 1');
    shell_run("$MANAGER firewall 2>/dev/null");
    shell_run(': > /opt/var/log/xray/access.log; : > /opt/var/log/xray/error.log');
    shell_run('xray run -config /opt/etc/xray/config.json > /dev/null 2>&1 & echo $! > /opt/var/run/xray.pid');
    reload_adguard();
    warmup_ipset();
    sleep(2);
    echo json_encode(['ok' => true]);
    break;

case 'stop':
    shell_run('killall xray 2>/dev/null; rm -f /opt/var/run/xray.pid');
    shell_run("$MANAGER cleanup_firewall 2>/dev/null");
    echo json_encode(['ok' => true]);
    break;

case 'restart':
    shell_run('killall xray 2>/dev/null; sleep 1');
    update_adguard_ipset();
    $r = generate_xray_config();
    if (isset($r['error'])) { echo json_encode($r); break; }
    shell_run("$MANAGER firewall 2>/dev/null");
    shell_run(': > /opt/var/log/xray/access.log');
    shell_run('xray run -config /opt/etc/xray/config.json > /dev/null 2>&1 & echo $! > /opt/var/run/xray.pid');
    reload_adguard();
    warmup_ipset();
    sleep(2);
    echo json_encode(['ok' => true]);
    break;

case 'warmup_ipset':
    warmup_ipset();
    echo json_encode(['ok' => true, 'domains' => count(all_domains())]);
    break;

case 'keys': echo json_encode(json_read($KEYS_FILE)); break;

case 'add_key':
    $keys = json_read($KEYS_FILE);
    $link = trim($_POST['link'] ?? '');
    $name = trim($_POST['name'] ?? 'Key ' . (count($keys) + 1));
    if (!$link) { echo json_encode(['error' => 'No link']); break; }
    $type = 'unknown';
    if (strpos($link, 'vless://') === 0) $type = 'vless';
    elseif (strpos($link, 'ss://') === 0) $type = 'shadowsocks';
    elseif (strpos($link, 'trojan://') === 0) $type = 'trojan';
    $keys[] = ['id' => uniqid(), 'name' => $name, 'link' => $link, 'enabled' => true, 'type' => $type];
    json_write($KEYS_FILE, $keys);
    echo json_encode(['ok' => true]);
    break;

case 'delete_key':
    $keys = json_read($KEYS_FILE);
    $id = $_POST['id'] ?? '';
    $keys = array_values(array_filter($keys, fn($k) => ($k['id'] ?? '') !== $id));
    json_write($KEYS_FILE, $keys);
    echo json_encode(['ok' => true]);
    break;

case 'toggle_key':
    $keys = json_read($KEYS_FILE);
    $id = $_POST['id'] ?? '';
    foreach ($keys as &$k) { if (($k['id'] ?? '') === $id) $k['enabled'] = !$k['enabled']; }
    json_write($KEYS_FILE, $keys);
    echo json_encode(['ok' => true]);
    break;

case 'subscriptions': echo json_encode(json_read($SUBS_FILE)); break;

case 'add_subscription':
    $subs = json_read($SUBS_FILE);
    $url = trim($_POST['url'] ?? '');
    $name = trim($_POST['name'] ?? 'Sub ' . (count($subs) + 1));
    if (!$url) { echo json_encode(['error' => 'No URL']); break; }
    $subs[] = ['id' => uniqid(), 'name' => $name, 'url' => $url, 'enabled' => true, 'updated' => ''];
    json_write($SUBS_FILE, $subs);
    echo json_encode(['ok' => true]);
    break;

case 'delete_subscription':
    $subs = json_read($SUBS_FILE);
    $id = $_POST['id'] ?? '';
    $subs = array_values(array_filter($subs, fn($s) => ($s['id'] ?? '') !== $id));
    json_write($SUBS_FILE, $subs);
    echo json_encode(['ok' => true]);
    break;

case 'update_subscriptions':
    $subs = json_read($SUBS_FILE);
    $all_servers = [];
    foreach ($subs as &$sub) {
        if (empty($sub['enabled']) || empty($sub['url'])) continue;
        $links = fetch_subscription($sub['url']);
        foreach ($links as $l) {
            $l = trim($l);
            $name = '';
            if (preg_match('/#(.+)$/', $l, $nm)) $name = urldecode($nm[1]);
            $all_servers[] = ['id' => md5($l), 'name' => $name ?: 'Server', 'link' => preg_replace('/#.*$/', '', $l), 'enabled' => true, 'sub' => $sub['id'] ?? ''];
        }
        $sub['updated'] = date('Y-m-d H:i:s');
    }
    json_write($SUBS_FILE, $subs);
    json_write($CACHED_FILE, $all_servers);
    echo json_encode(['ok' => true, 'count' => count($all_servers)]);
    break;

case 'subscription_servers': echo json_encode(json_read($CACHED_FILE)); break;

case 'toggle_server':
    $servers = json_read($CACHED_FILE);
    $id = $_POST['id'] ?? '';
    foreach ($servers as &$s) { if (($s['id'] ?? '') === $id) $s['enabled'] = !$s['enabled']; }
    json_write($CACHED_FILE, $servers);
    echo json_encode(['ok' => true]);
    break;

case 'select_server':
    $id = $_POST['id'] ?? '';
    $state = json_read($STATE_FILE);
    $state['active_outbound'] = $id;
    json_write($STATE_FILE, $state);
    echo json_encode(['ok' => true]);
    break;

case 'domains':
    $manual = lines_read($DOMAINS_FILE);
    $lists = json_read($GITHUB_LISTS_FILE);
    $v2flyDomains = [];
    foreach ($lists as $l) {
        if (empty($l['enabled']) || ($l['source'] ?? '') !== 'v2fly') continue;
        $f = "$V2FLY_LISTS_DIR/{$l['name']}.txt";
        if (!file_exists($f)) continue;
        $doms = array_filter(array_map('trim', file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)), fn($x) => $x !== '' && $x[0] !== '#');
        foreach ($doms as $d) $v2flyDomains[$d] = $l['name'];
    }
    echo json_encode(['manual' => $manual, 'v2fly' => $v2flyDomains]);
    break;

case 'add_domains':
    $domains = lines_read($DOMAINS_FILE);
    $new = trim($_POST['domains'] ?? '');
    if (!$new) { echo json_encode(['error' => 'No domains']); break; }
    $newList = array_filter(array_map(fn($d) => strtolower(trim($d)), preg_split('/[\s,;\n]+/', $new)));
    $domains = array_values(array_unique(array_merge($domains, $newList)));
    lines_write($DOMAINS_FILE, $domains);
    update_adguard_ipset();
    quick_apply();
    echo json_encode(['ok' => true, 'count' => count($domains)]);
    break;

case 'delete_domain':
    $domains = lines_read($DOMAINS_FILE);
    $d = trim($_POST['domain'] ?? '');
    $domains = array_values(array_filter($domains, fn($x) => $x !== $d));
    lines_write($DOMAINS_FILE, $domains);
    update_adguard_ipset();
    quick_apply();
    echo json_encode(['ok' => true]);
    break;

case 'ips': echo json_encode(lines_read($IPS_FILE)); break;

case 'add_ips':
    $ips = lines_read($IPS_FILE);
    $new = trim($_POST['ips'] ?? '');
    if (!$new) { echo json_encode(['error' => 'No IPs']); break; }
    $newList = array_filter(array_map('trim', preg_split('/[\s,;\n]+/', $new)));
    $ips = array_values(array_unique(array_merge($ips, $newList)));
    lines_write($IPS_FILE, $ips);
    quick_apply();
    echo json_encode(['ok' => true, 'count' => count($ips)]);
    break;

case 'delete_ip':
    $ips = lines_read($IPS_FILE);
    $ip = trim($_POST['ip'] ?? '');
    $ips = array_values(array_filter($ips, fn($x) => $x !== $ip));
    lines_write($IPS_FILE, $ips);
    quick_apply();
    echo json_encode(['ok' => true]);
    break;

case 'devices':
    $macs = lines_read($FULLVPN_FILE);
    $all = keenetic_get_devices();
    $devices = [];
    foreach ($macs as $mac) {
        $info = ['mac' => $mac, 'ip' => '', 'hostname' => ''];
        foreach ($all as $d) {
            if (strtoupper($d['mac']) === strtoupper($mac)) { $info['ip'] = $d['ip']; $info['hostname'] = $d['hostname']; break; }
        }
        $devices[] = $info;
    }
    echo json_encode($devices);
    break;

case 'add_device':
    $macs = lines_read($FULLVPN_FILE);
    $mac = strtoupper(trim($_POST['mac'] ?? ''));
    if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) { echo json_encode(['error' => 'Invalid MAC']); break; }
    if (!in_array($mac, $macs)) $macs[] = $mac;
    lines_write($FULLVPN_FILE, $macs);
    echo json_encode(['ok' => true]);
    break;

case 'delete_device':
    $macs = lines_read($FULLVPN_FILE);
    $mac = strtoupper(trim($_POST['mac'] ?? ''));
    $macs = array_values(array_filter($macs, fn($m) => strtoupper($m) !== $mac));
    lines_write($FULLVPN_FILE, $macs);
    echo json_encode(['ok' => true]);
    break;

case 'lan_devices': echo json_encode(keenetic_get_devices()); break;

case 'github_lists': echo json_encode(json_read($GITHUB_LISTS_FILE)); break;

case 'add_github_list':
    $lists = json_read($GITHUB_LISTS_FILE);
    $url = trim($_POST['url'] ?? '');
    $name = trim($_POST['name'] ?? '');
    if (!$url) { echo json_encode(['error' => 'No URL']); break; }
    if (!$name) $name = basename(parse_url($url, PHP_URL_PATH));
    $id = md5($url);
    foreach ($lists as $l) { if (($l['id'] ?? '') === $id) { echo json_encode(['error' => 'Already exists']); break 2; } }
    $lists[] = ['id' => $id, 'name' => $name, 'url' => $url, 'enabled' => true, 'count' => 0, 'updated' => ''];
    json_write($GITHUB_LISTS_FILE, $lists);
    echo json_encode(['ok' => true]);
    break;

case 'delete_github_list':
    $lists = json_read($GITHUB_LISTS_FILE);
    $id = $_POST['id'] ?? '';
    // Remove v2fly list file if exists
    foreach ($lists as $l) {
        if (($l['id'] ?? '') === $id && ($l['source'] ?? '') === 'v2fly' && !empty($l['name'])) {
            @unlink("$V2FLY_LISTS_DIR/{$l['name']}.txt");
        }
    }
    $lists = array_values(array_filter($lists, fn($l) => ($l['id'] ?? '') !== $id));
    json_write($GITHUB_LISTS_FILE, $lists);
    update_adguard_ipset();
    quick_apply();
    echo json_encode(['ok' => true]);
    break;

case 'toggle_github_list':
    $lists = json_read($GITHUB_LISTS_FILE);
    $id = $_POST['id'] ?? '';
    foreach ($lists as &$l) { if (($l['id'] ?? '') === $id) $l['enabled'] = !$l['enabled']; }
    json_write($GITHUB_LISTS_FILE, $lists);
    echo json_encode(['ok' => true]);
    break;

case 'update_github_lists':
    $lists = json_read($GITHUB_LISTS_FILE);
    $domains = lines_read($DOMAINS_FILE);
    $total_new = 0;
    foreach ($lists as &$list) {
        if (empty($list['enabled']) || empty($list['url'])) continue;
        $fetched = fetch_github_domains($list['url']);
        $list['count'] = count($fetched);
        $list['updated'] = date('Y-m-d H:i:s');
        foreach ($fetched as $d) {
            if (!in_array($d, $domains)) { $domains[] = $d; $total_new++; }
        }
    }
    json_write($GITHUB_LISTS_FILE, $lists);
    $domains = array_values(array_unique($domains));
    lines_write($DOMAINS_FILE, $domains);
    update_adguard_ipset();
    echo json_encode(['ok' => true, 'new_domains' => $total_new, 'total' => count($domains)]);
    break;

case 'v2fly_search':
    $q = strtolower(trim($_GET['q'] ?? ''));
    if (strlen($q) < 1) { echo json_encode(['results' => []]); break; }
    // Aliases: single-letter or short queries map to full service names
    $aliases = [
        'x' => 'twitter', 'tw' => 'twitter', 'twt' => 'twitter',
        'g' => 'google', 'yt' => 'youtube', 'fb' => 'facebook',
        'ig' => 'instagram', 'tt' => 'tiktok', 'tg' => 'telegram',
        'wh' => 'whatsapp', 'ds' => 'discord', 'gh' => 'github',
        'nb' => 'notion', 'nt' => 'netflix', 'sp' => 'spotify',
        'st' => 'steam', 'tv' => 'twitch', 'rd' => 'reddit',
        'li' => 'linkedin', 'am' => 'amazon', 'op' => 'openai',
        'an' => 'anthropic', 'cl' => 'claude', 'th' => 'threads',
    ];
    $catalog = [];
    if (file_exists($CATALOG_FILE)) {
        $catalog = json_decode(file_get_contents($CATALOG_FILE), true) ?: [];
    }
    if (empty($catalog) && file_exists($SS_DOWNLOADER)) {
        shell_run(escapeshellcmd($SS_DOWNLOADER) . ' -catalog ' . escapeshellarg($CATALOG_FILE));
        $catalog = json_decode(file_get_contents($CATALOG_FILE), true) ?: [];
    }
    if (empty($catalog)) {
        $catalog = ['openai','anthropic','google','youtube','facebook','instagram',
            'twitter','whatsapp','telegram','discord','github','notion','tiktok',
            'netflix','spotify','steam','twitch','reddit','linkedin','amazon'];
    }
    // Resolve aliases: if query matches an alias, use the target name
    $resolved = $q;
    if (isset($aliases[$q])) $resolved = $aliases[$q];
    // Also add alias as a search result if it maps to a real catalog entry
    $results = array_values(array_filter($catalog, fn($n) => strpos($n, $resolved) !== false));
    // If alias resolved to something, also include the alias itself as a clickable result
    if ($resolved !== $q && in_array($resolved, $catalog) && !in_array($q, $results)) {
        array_unshift($results, $q);
    }
    $added = array_column(json_read($GITHUB_LISTS_FILE), 'name');
    echo json_encode(['results' => array_slice($results, 0, 30), 'added' => $added]);
    break;

case 'v2fly_add':
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($_POST['name'] ?? ''));
    if (!$name) { echo json_encode(['error' => 'No name']); break; }
    if (!file_exists($SS_DOWNLOADER)) { echo json_encode(['error' => 'ss-downloader not found']); break; }
    @mkdir($V2FLY_LISTS_DIR, 0755, true);
    $out = shell_run(escapeshellcmd($SS_DOWNLOADER) . ' -list ' . escapeshellarg($name) . ' ' . escapeshellarg($V2FLY_LISTS_DIR));
    $listFile = "$V2FLY_LISTS_DIR/$name.txt";
    if (!file_exists($listFile)) { echo json_encode(['error' => 'Download failed: ' . $out]); break; }
    $newDomains = array_filter(array_map('trim', file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)), fn($l) => $l !== '' && $l[0] !== '#');
    // v2fly domains stay in their own list file, NOT in domains.txt
    $lists = json_read($GITHUB_LISTS_FILE);
    $exists = false;
    foreach ($lists as &$el) { if (($el['name'] ?? '') === $name) { $el['count'] = count($newDomains); $el['updated'] = date('Y-m-d H:i:s'); $el['enabled'] = true; $exists = true; } }
    if (!$exists) $lists[] = ['id' => md5($name), 'name' => $name, 'url' => '', 'enabled' => true, 'count' => count($newDomains), 'updated' => date('Y-m-d H:i:s'), 'source' => 'v2fly'];
    json_write($GITHUB_LISTS_FILE, $lists);
    update_adguard_ipset();
    quick_apply();
    echo json_encode(['ok' => true, 'name' => $name, 'count' => count($newDomains)]);
    break;

case 'v2fly_refresh':
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($_POST['name'] ?? ''));
    if (!$name || !file_exists($SS_DOWNLOADER)) { echo json_encode(['error' => 'Invalid']); break; }
    shell_run(escapeshellcmd($SS_DOWNLOADER) . ' -list ' . escapeshellarg($name) . ' ' . escapeshellarg($V2FLY_LISTS_DIR));
    $listFile = "$V2FLY_LISTS_DIR/$name.txt";
    if (!file_exists($listFile)) { echo json_encode(['error' => 'Refresh failed']); break; }
    $newDomains = array_filter(array_map('trim', file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)), fn($l) => $l !== '' && $l[0] !== '#');
    $lists = json_read($GITHUB_LISTS_FILE);
    foreach ($lists as &$l) { if (($l['name'] ?? '') === $name) { $l['count'] = count($newDomains); $l['updated'] = date('Y-m-d H:i:s'); } }
    json_write($GITHUB_LISTS_FILE, $lists);
    update_adguard_ipset();
    quick_apply();
    echo json_encode(['ok' => true, 'count' => count($newDomains)]);
    break;

case 'wg_peers': echo json_encode(wg_list_peers()); break;

case 'wg_add_peer':
    $name = trim($_POST['name'] ?? '');
    if (!$name) { echo json_encode(['error' => 'No name']); break; }
    echo json_encode(wg_add_peer($name));
    break;

case 'wg_delete_peer':
    $name = trim($_POST['name'] ?? '');
    if (!$name) { echo json_encode(['error' => 'No name']); break; }
    echo json_encode(wg_delete_peer($name));
    break;

case 'wg_get_config':
    $name = trim($_GET['name'] ?? '');
    if (!$name) { echo json_encode(['error' => 'No name']); break; }
    echo json_encode(wg_get_client_config($name));
    break;

case 'wg_qrcode':
    $name = trim($_GET['name'] ?? '');
    if (!$name) { echo json_encode(['error' => 'No name']); break; }
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    $conf_file = "$WG_DIR/$name.conf";
    if (!file_exists($conf_file)) { echo json_encode(['error' => 'Not found']); break; }
    $qr = shell_run("/opt/bin/qrencode -t UTF8 < " . escapeshellarg($conf_file));
    echo json_encode(['ok' => true, 'qr' => $qr, 'name' => $name]);
    break;

case 'wg_restart':
    shell_run('/opt/etc/init.d/S99wireguard restart 2>/dev/null');
    echo json_encode(['ok' => true]);
    break;

case 'logs':
    $type = $_GET['type'] ?? 'error';
    $lines = min((int)($_GET['lines'] ?? 50), 500);
    $file = $type === 'access' ? $LOG_ACCESS : $LOG_ERROR;
    if (!file_exists($file)) { echo json_encode([]); break; }
    $content = shell_run("tail -n " . escapeshellarg($lines) . " " . escapeshellarg($file));
    echo json_encode(explode("\n", $content));
    break;

case 'clear_logs':
    @file_put_contents($LOG_ACCESS, '');
    @file_put_contents($LOG_ERROR, '');
    echo json_encode(['ok' => true]);
    break;

case 'raw_config': echo file_get_contents($XRAY_CONF) ?: '{}'; break;

case 'test_connection':
    $real_ip = shell_run('/opt/bin/curl -s --max-time 5 http://api.ipify.org 2>/dev/null');
    $proxy_ip = shell_run('/opt/bin/curl -s --max-time 10 --socks5-hostname 127.0.0.1:1081 http://api.ipify.org 2>/dev/null');
    $pid = shell_run('cat /opt/var/run/xray.pid 2>/dev/null');
    $running = $pid && shell_run("kill -0 $pid 2>/dev/null; echo \$?") === '0';
    $domains = lines_read($DOMAINS_FILE);
    $test_domain = '';
    foreach (['github.com','google.com','anthropic.com'] as $td) {
        if (in_array($td, $domains)) { $test_domain = $td; break; }
    }
    $vpn_route_ok = false;
    if ($test_domain) {
        $code = shell_run('/opt/bin/curl -s -o /dev/null -w "%{http_code}" --max-time 8 --socks5-hostname 127.0.0.1:1081 https://' . escapeshellarg($test_domain) . ' 2>/dev/null');
        $vpn_route_ok = $code && $code !== '000';
    }
    echo json_encode([
        'real_ip' => $real_ip, 'proxy_ip' => $proxy_ip,
        'running' => $running, 'vpn_route_ok' => $vpn_route_ok,
        'test_domain' => $test_domain,
        'selective' => $proxy_ip === $real_ip,
        'ok' => $running && ($vpn_route_ok || !empty($proxy_ip))
    ]);
    break;

// ============ AUTH ============

case 'auth_status':
    echo json_encode([
        'authenticated' => is_authenticated(),
        'local' => is_local_request(),
        'password_set' => !empty(_auth_cfg()['hash']),
    ]);
    break;

case 'login':
    $pass = $_POST['password'] ?? '';
    $rl = _login_attempts_check();
    if (!empty($rl['blocked'])) {
        http_response_code(429);
        echo json_encode(['error' => 'too_many_attempts', 'retry_after' => $rl['retry_after']]);
        break;
    }
    if ($pass === '' || !check_login($pass)) {
        _login_attempts_record_fail();
        usleep(700000); // slow down brute force
        echo json_encode(['error' => 'invalid_credentials', 'remaining' => max(0, ($rl['remaining'] ?? 5) - 1)]);
        break;
    }
    _start_session();
    $_SESSION['authed'] = true;
    $_SESSION['login_at'] = time();
    _login_attempts_reset();
    echo json_encode(['ok' => true]);
    break;

case 'logout':
    _start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 86400, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
    }
    session_destroy();
    echo json_encode(['ok' => true]);
    break;

// ============ ONBOARDING ============

case 'get_onboarding_status':
    echo json_encode(get_onboarding_status());
    break;

case 'set_ui_password':
    // Allowed in onboarding even without auth IF password not yet set
    $cfg = _auth_cfg();
    if (!empty($cfg['hash']) && !is_authenticated()) { require_auth(); }
    $pass = $_POST['password'] ?? '';
    echo json_encode(set_ui_password($pass));
    break;

case 'test_kn_password':
    // Allowed during onboarding without UI auth (caller proves access by being local)
    if (!is_local_request() && !is_authenticated()) { require_auth(); }
    $pass = $_POST['password'] ?? '';
    if ($pass === '') { echo json_encode(['error' => 'no_password']); break; }
    echo json_encode(test_kn_password($pass));
    break;

case 'set_kn_password':
    if (!is_local_request() && !is_authenticated()) { require_auth(); }
    $pass = $_POST['password'] ?? '';
    if ($pass === '') { echo json_encode(['error' => 'no_password']); break; }
    echo json_encode(set_kn_password($pass));
    break;

case 'complete_onboarding':
    if (!is_local_request() && !is_authenticated()) { require_auth(); }
    echo json_encode(complete_onboarding());
    break;

case 'reset_onboarding':
    // Mutating — already gated by require_auth() above
    @unlink($ONBOARDED_FILE);
    echo json_encode(['ok' => true]);
    break;

// ============ FEATURES ============

case 'get_features':
    echo json_encode(get_features());
    break;

case 'set_features':
    $patch = [];
    $truthy = ['1', 1, 'true', true, 'on', 'yes'];
    foreach (['wireguard', 'adguard', 'auto_update'] as $k) {
        if (isset($_POST[$k])) $patch[$k] = in_array($_POST[$k], $truthy, true);
    }
    if (isset($_POST['theme'])) $patch['theme'] = $_POST['theme'];
    echo json_encode(set_features_patch($patch));
    break;

case 'get_version':
    echo json_encode([
        'installed' => get_installed_version(),
    ]);
    break;

// ============ UPDATES ============

case 'check_update':
    // Cache 6h to avoid hammering GitHub
    $cache = '/opt/tmp/xray-vpn-update-check.json';
    $fresh = file_exists($cache) && (time() - filemtime($cache)) < 6 * 3600;
    if (!$fresh || !empty($_GET['force']) || !empty($_POST['force'])) {
        $raw = shell_run('/opt/etc/xray/update.sh --check 2>/dev/null');
        if ($raw === '' || strpos($raw, '{') !== 0) {
            echo json_encode(['error' => 'check_failed', 'raw' => $raw]);
            break;
        }
        @file_put_contents($cache, $raw);
        echo $raw;
    } else {
        echo @file_get_contents($cache);
    }
    break;

case 'apply_update':
    // Mutating — auth already enforced. Run install.sh --upgrade in background.
    @unlink('/opt/tmp/xray-vpn-update-check.json');
    @file_put_contents('/opt/tmp/xray-vpn-update.state', "starting\n" . time() . "\nЗапускаю обновление...\n");
    shell_exec('nohup /opt/etc/xray/update.sh --apply > /dev/null 2>&1 &');
    echo json_encode(['ok' => true, 'started' => true]);
    break;

case 'status_update':
    $sf = '/opt/tmp/xray-vpn-update.state';
    $lf = '/opt/var/log/xray/update.log';
    $state = file_exists($sf) ? @file_get_contents($sf) : '';
    $parts = explode("\n", trim($state));
    $status = $parts[0] ?? 'idle';
    $ts = (int)($parts[1] ?? 0);
    $message = $parts[2] ?? '';
    $tail = '';
    if (file_exists($lf)) {
        $tail = shell_run('tail -n 30 ' . escapeshellarg($lf));
    }
    echo json_encode([
        'status' => $status,
        'updated_at' => $ts,
        'message' => $message,
        'log_tail' => $tail,
    ]);
    break;

case 'rollback_update':
    @file_put_contents('/opt/tmp/xray-vpn-update.state', "starting\n" . time() . "\nЗапускаю откат...\n");
    shell_exec('nohup /opt/etc/xray/update.sh --rollback > /dev/null 2>&1 &');
    echo json_encode(['ok' => true, 'started' => true]);
    break;

default:
    echo json_encode(['error' => 'Unknown action']);
}
