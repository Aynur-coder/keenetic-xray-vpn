#!/bin/sh
# keenetic-xray-vpn installer
# POSIX sh (BusyBox ash compatible). No bash-isms.
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/<USER>/keenetic-xray-vpn/main/install.sh | sh
#   curl -fsSL .../install.sh | sh -s -- --upgrade
#   curl -fsSL .../install.sh | sh -s -- --uninstall
#   curl -fsSL .../install.sh | sh -s -- --version v0.9.0 --no-adguard --verbose

set -eu

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------
REPO_OWNER="${REPO_OWNER:-Aynur-coder}"
REPO_NAME="${REPO_NAME:-keenetic-xray-vpn}"
REPO="$REPO_OWNER/$REPO_NAME"
RAW_BASE="https://raw.githubusercontent.com/$REPO"
REL_BASE="https://github.com/$REPO/releases/download"

INSTALL_LOG="/opt/var/log/xray-vpn-install.log"
LOCK_FILE="/opt/var/run/xray-vpn-install.lock"
STAGING="/opt/tmp/xray-vpn-staging"
BACKUP_DIR="/opt/etc/xray/backups"
VERSION_FILE="/opt/etc/xray/.version"
MIN_FREE_KB=20480
BACKUP_KEEP=5

# Defaults that may be overridden by CLI flags
MODE="install"           # install | upgrade | reinstall | uninstall
TARGET_VERSION=""        # empty = latest from main/VERSION
SKIP_ADGUARD=0
DRY_RUN=0
VERBOSE=0

# -----------------------------------------------------------------------------
# Logging
# -----------------------------------------------------------------------------
_log() {
    _ts="$(date '+%F %T')"
    _line="[$_ts] $*"
    printf '%s\n' "$_line"
    [ -w "$(dirname "$INSTALL_LOG")" ] 2>/dev/null && printf '%s\n' "$_line" >> "$INSTALL_LOG" 2>/dev/null || :
}

info() { _log "INFO  $*"; }
warn() { _log "WARN  $*"; }
verb() { [ "$VERBOSE" = "1" ] && _log "DEBUG $*" || :; }
die()  { _log "ERROR $*"; exit 1; }

step() { printf '\n==> %s\n' "$*"; _log "STEP  $*"; }

dryrun() {
    if [ "$DRY_RUN" = "1" ]; then
        info "(dry-run) would: $*"
        return 0
    fi
    return 1
}

# -----------------------------------------------------------------------------
# CLI parsing
# -----------------------------------------------------------------------------
while [ $# -gt 0 ]; do
    case "$1" in
        --upgrade)     MODE="upgrade" ;;
        --reinstall)   MODE="reinstall" ;;
        --uninstall)   MODE="uninstall" ;;
        --no-adguard)  SKIP_ADGUARD=1 ;;
        --dry-run)     DRY_RUN=1 ;;
        --verbose|-v)  VERBOSE=1 ;;
        --version)
            shift
            [ $# -gt 0 ] || die "--version requires an argument"
            TARGET_VERSION="$1"
            ;;
        --repo)
            shift
            [ $# -gt 0 ] || die "--repo requires owner/name"
            REPO="$1"
            RAW_BASE="https://raw.githubusercontent.com/$REPO"
            REL_BASE="https://github.com/$REPO/releases/download"
            ;;
        --help|-h)
            cat <<EOF
keenetic-xray-vpn installer

Usage: install.sh [options]

Options:
  --upgrade           Update files & run migrations; keep user config and opkg state
  --reinstall         Wipe /opt/etc/xray (except .kn_pass + ui-auth.json) and install fresh
  --uninstall         Stop services, remove our files, keep logs and backups
  --no-adguard        Skip AdGuard Home installation (advanced)
  --version vX.Y.Z    Install a specific tagged release instead of the latest
  --repo owner/name   Use a different GitHub repo (for forks)
  --dry-run           Print actions without executing them
  --verbose, -v       More log output
  --help, -h          Show this help

After install, open http://192.168.1.1:91 to run the setup wizard.
EOF
            exit 0
            ;;
        *) die "Unknown option: $1 (try --help)" ;;
    esac
    shift
done

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------
need_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

curl_fetch() {
    # curl_fetch <url> <dst>
    _url="$1"; _dst="$2"
    verb "fetch $_url -> $_dst"
    curl -fsSL --max-time 60 -o "$_dst" "$_url" \
        || die "Failed to download $_url"
}

sha256_of() {
    sha256sum "$1" 2>/dev/null | awk '{print $1}'
}

is_pkg_installed() {
    opkg list-installed 2>/dev/null | awk '{print $1}' | grep -qx "$1"
}

# PHP binary path and mode (set by _find_php)
_PHP_BIN=""
_PHP_MODE=""  # "cli" (-r flag) or "cgi" (file execution)
_PHP_HELPER="/tmp/_xray_mfget_$$.php"

# Detect available PHP binary — sets _PHP_BIN and _PHP_MODE
_find_php() {
    # CLI binaries support -r (inline code)
    for _b in php php8; do
        if command -v "$_b" >/dev/null 2>&1 && "$_b" -r 'echo 1;' >/dev/null 2>&1; then
            _PHP_BIN="$_b"; _PHP_MODE=cli; return 0
        fi
    done
    # CGI binaries only support -f (file); need a temp script wrapper
    for _b in php8-cgi php-cgi /opt/bin/php8-cgi /opt/bin/php-cgi; do
        if command -v "$_b" >/dev/null 2>&1 || [ -x "$_b" ]; then
            _PHP_BIN="$_b"; _PHP_MODE=cgi; return 0
        fi
    done
    return 1
}

# Write the CGI helper once; it reads manifest path + expression from $argv
_init_php_helper() {
    # Use getenv() instead of $argv — $argv is unreliable in CGI mode
    cat > "$_PHP_HELPER" << 'PHPEOF'
<?php
$m = json_decode(file_get_contents(getenv('_MF')), true);
eval(getenv('_EXPR'));
PHPEOF
}

# Strip HTTP response headers php-cgi may emit.
# If first line looks like an HTTP header (Word: value), skip until blank line.
# If no HTTP headers present, print everything as-is.
_strip_cgi_headers() {
    awk '
        NR==1 && /^[A-Za-z][A-Za-z0-9-]*:[[:space:]]/ { in_hdr=1 }
        in_hdr && /^[[:space:]]*$/ { in_hdr=0; next }
        in_hdr { next }
        { print }
    '
}

# Parse manifest.json — detects CLI vs CGI PHP automatically.
# Usage: mf_get <manifest.json> '<php-expression>'
mf_get() {
    _mf="$1"; _expr="$2"
    [ -n "$_PHP_BIN" ] || _find_php || die "PHP not found. Run: opkg install php8"
    if [ "$_PHP_MODE" = "cli" ]; then
        "$_PHP_BIN" -r "\$m=json_decode(file_get_contents('$_mf'),true); $_expr"
    else
        [ -f "$_PHP_HELPER" ] || _init_php_helper
        # Unset CGI env vars inherited from lighttpd before calling php-cgi.
        # When REQUEST_METHOD is set, php-cgi enforces force-cgi-redirect and uses
        # SCRIPT_FILENAME (=api.php) instead of the -f argument, causing it to run
        # api.php (which triggers another update.sh). Unsetting these vars in a
        # subshell makes php-cgi use the -f flag and run the helper script normally.
        ( unset REQUEST_METHOD SCRIPT_FILENAME REDIRECT_STATUS QUERY_STRING
          _MF="$_mf" _EXPR="$_expr" exec "$_PHP_BIN" -q -f "$_PHP_HELPER" ) 2>/dev/null \
            | _strip_cgi_headers
    fi
}

# Install PHP early so mf_get works before the full package list is applied.
# PHP is a required dependency anyway — we just need it before manifest parsing.
bootstrap_php() {
    _find_php && {
        info "PHP ready: $_PHP_BIN ($_PHP_MODE mode)"
        return 0
    }
    step "Bootstrap PHP (needed for manifest parsing)"
    opkg update >/dev/null 2>&1 || warn "opkg update had errors"
    for _p in php8 php8-cli php; do
        opkg install "$_p" >/dev/null 2>&1 || true
        if _find_php; then
            info "PHP ready: $_PHP_BIN ($_PHP_MODE mode)"
            return 0
        fi
    done
    die "Could not install PHP. Run manually: opkg install php8"
}

# Compare semver A B: print 'newer' / 'same' / 'older' (A relative to B)
semver_cmp() {
    echo "$1 $2" | awk '{
        n=split($1,a,"."); m=split($2,b,".");
        for (i=1; i<=3; i++) { a[i]=a[i]+0; b[i]=b[i]+0 }
        for (i=1; i<=3; i++) {
            if (a[i] > b[i]) { print "newer"; exit }
            if (a[i] < b[i]) { print "older"; exit }
        }
        print "same"
    }'
}

# Acquire lock or die
acquire_lock() {
    mkdir -p "$(dirname "$LOCK_FILE")" 2>/dev/null || :
    if [ -e "$LOCK_FILE" ]; then
        _pid="$(cat "$LOCK_FILE" 2>/dev/null || echo 0)"
        _stale=1
        if [ "$_pid" != "0" ] && kill -0 "$_pid" 2>/dev/null; then
            # PID exists — but also check lock age: >30 min means PID was recycled
            _age=$(( $(date +%s) - $(date -r "$LOCK_FILE" +%s 2>/dev/null || echo 0) ))
            if [ "$_age" -lt 1800 ]; then
                die "Another install is running (PID $_pid). Wait or delete $LOCK_FILE"
            fi
            _stale=1
        fi
        [ "$_stale" = "1" ] && { warn "Stale lock (PID $_pid, age ${_age:-?}s), removing"; rm -f "$LOCK_FILE"; }
    fi
    printf '%s\n' "$$" > "$LOCK_FILE"
    trap 'rm -f "$LOCK_FILE"' EXIT INT TERM
}

# Restore backup at $1 (atomic best-effort)
rollback() {
    _bk="$1"
    [ -d "$_bk" ] || { warn "No backup to roll back to"; return; }
    warn "Rolling back from $_bk"
    for _f in "$_bk"/*.tar; do
        [ -f "$_f" ] || continue
        tar -xf "$_f" -C / 2>/dev/null || warn "Failed to restore $_f"
    done
}

# -----------------------------------------------------------------------------
# Step 0: Preflight
# -----------------------------------------------------------------------------
preflight() {
    step "Preflight checks"

    [ "$(id -u)" = "0" ] || die "Must run as root (try: sudo $0)"

    # /opt sanity
    [ -d /opt ] || die "/opt not found (is Entware installed?)"
    [ -d /opt/etc ] || die "/opt/etc not found"

    mkdir -p /opt/var/log /opt/var/run /opt/tmp

    # Disk space
    _free_kb="$(df -k /opt | awk 'NR==2 {print $4}')"
    [ -n "$_free_kb" ] || _free_kb=0
    if [ "$_free_kb" -lt "$MIN_FREE_KB" ]; then
        die "Not enough free space in /opt (need ${MIN_FREE_KB} KB, have ${_free_kb})"
    fi

    # Required commands at minimum
    need_cmd curl
    need_cmd sha256sum
    need_cmd awk

    # Internet
    info "Checking internet connectivity"
    curl -fsS --max-time 10 https://raw.githubusercontent.com/ >/dev/null \
        || die "Cannot reach GitHub. Check internet and DNS."

    # Keenetic model detection (informational only, never blocks install)
    if command -v ndmc >/dev/null 2>&1; then
        _model="$(ndmc -c "show version" 2>/dev/null | awk -F': *' '/device|hw_id|Device|title/ {print $2; exit}')"
        if [ -n "$_model" ]; then
            info "Keenetic: $_model"
        else
            info "Keenetic model not identified — OK, continuing"
        fi
    else
        info "ndmc not found — continuing without model check"
    fi
}

# -----------------------------------------------------------------------------
# Step 1: Entware presence
# -----------------------------------------------------------------------------
check_entware() {
    step "Checking Entware (opkg)"
    if [ ! -x /opt/bin/opkg ]; then
        die "Entware (opkg) not found at /opt/bin/opkg.
Please install Entware first:
  https://help.keenetic.com/hc/en-us/articles/360021403700"
    fi
    info "opkg: $(/opt/bin/opkg --version 2>&1 | head -1)"
}

# -----------------------------------------------------------------------------
# Step 2: Resolve target version + download manifest
# -----------------------------------------------------------------------------
download_manifest() {
    step "Resolving version & downloading manifest"
    mkdir -p "$STAGING"
    rm -rf "$STAGING"/manifest.json "$STAGING"/files "$STAGING"/defaults
    mkdir -p "$STAGING/files" "$STAGING/defaults"

    if [ -z "$TARGET_VERSION" ]; then
        TARGET_VERSION="$(curl -fsSL --max-time 10 "$RAW_BASE/main/VERSION" | tr -d ' \r\n')"
        [ -n "$TARGET_VERSION" ] || die "Could not resolve latest VERSION"
        TARGET_REF="v$TARGET_VERSION"
    else
        # strip leading 'v'
        TARGET_REF="$TARGET_VERSION"
        case "$TARGET_REF" in v*) ;; *) TARGET_REF="v$TARGET_REF" ;; esac
        TARGET_VERSION="${TARGET_REF#v}"
    fi
    info "Target version: $TARGET_VERSION ($TARGET_REF)"

    # Primary source: GitHub Release asset (manifest.json is uploaded by release.yml)
    _mf_url="$REL_BASE/$TARGET_REF/manifest.json"
    if ! curl -fsSL --max-time 30 -o "$STAGING/manifest.json" "$_mf_url"; then
        # Fallback 1: raw at tag (if maintainer committed manifest.json into tag tree)
        warn "Release asset not found, trying raw at $TARGET_REF"
        if ! curl -fsSL --max-time 30 -o "$STAGING/manifest.json" "$RAW_BASE/$TARGET_REF/manifest.json"; then
            # Fallback 2: main branch (useful before first tagged release; sha256 will be skipped)
            warn "Tag tree has no manifest.json, falling back to main"
            curl_fetch "$RAW_BASE/main/manifest.json" "$STAGING/manifest.json"
            TARGET_REF="main"
        fi
    fi

    # Sanity: manifest version matches?
    _mf_ver="$(mf_get "$STAGING/manifest.json" 'echo $m["version"];')"
    info "Manifest reports version: $_mf_ver"
}

# -----------------------------------------------------------------------------
# Step 3: opkg dependencies (skipped on --upgrade unless missing)
# -----------------------------------------------------------------------------
install_packages() {
    step "Installing opkg dependencies"

    # Parse package list from manifest
    _pkg_list="$(mf_get "$STAGING/manifest.json" \
        'foreach($m["opkg_packages"] as $p) echo $p."\n";')"
    [ -n "$_pkg_list" ] || { warn "No packages declared in manifest"; return; }

    _to_install=""
    for _pkg in $_pkg_list; do
        if [ "$_pkg" = "adguardhome-go" ] && [ "$SKIP_ADGUARD" = "1" ]; then
            verb "Skipping adguardhome-go (--no-adguard)"
            continue
        fi
        if is_pkg_installed "$_pkg"; then
            verb "already installed: $_pkg"
        else
            _to_install="$_to_install $_pkg"
        fi
    done

    if [ -z "$_to_install" ]; then
        info "All packages already installed"
        return
    fi

    info "Need to install:$_to_install"
    if dryrun "opkg install$_to_install"; then return; fi

    info "Running opkg update (may take a minute)..."
    opkg update >/dev/null 2>&1 || warn "opkg update reported errors, continuing"

    # shellcheck disable=SC2086
    opkg install $_to_install \
        || die "opkg install failed. See $INSTALL_LOG"
}

# -----------------------------------------------------------------------------
# Step 4: AdGuard Home bootstrap
# -----------------------------------------------------------------------------
bootstrap_adguard() {
    [ "$SKIP_ADGUARD" = "1" ] && { info "Skipping AdGuard setup (--no-adguard)"; return; }

    step "AdGuard Home"

    # --- 4a. Create directories ---
    mkdir -p /opt/etc/AdGuardHome /opt/var/log /opt/etc/ndm/netfilter.d
    if ! dryrun "create AGH dirs"; then :; fi

    # --- 4b. Deploy AdGuardHome.yaml (only if missing) ---
    _agh_yaml="/opt/etc/AdGuardHome/AdGuardHome.yaml"
    _agh_conf="/opt/etc/AdGuardHome/adguardhome.conf"
    _agh_binary="$(command -v AdGuardHome 2>/dev/null || echo /opt/sbin/AdGuardHome)"

    if [ ! -f "$_agh_yaml" ]; then
        info "Creating AdGuardHome.yaml from template"
        if ! dryrun "download AdGuardHome.yaml from staging"; then
            _staged="$STAGING/defaults/defaults_AdGuardHome.yaml"
            if [ -f "$_staged" ]; then
                cp -f "$_staged" "$_agh_yaml"
            else
                # Inline minimal yaml if staging file not yet available (Step 3 runs before Step 5)
                cat > "$_agh_yaml" << 'AGHYAML'
http:
  pprof:
    port: 6060
    enabled: false
  address: 0.0.0.0:3000
  session_ttl: 720h
users: []
auth_attempts: 5
block_auth_min: 15
dns:
  bind_hosts:
    - 0.0.0.0
  port: 53
  ratelimit: 20
  refuse_any: true
  upstream_dns:
    - https://dns.cloudflare.com/dns-query
    - https://dns.google/dns-query
  bootstrap_dns:
    - 1.1.1.1
    - 8.8.8.8
    - 94.140.14.14
  fallback_dns:
    - 1.1.1.1
    - 8.8.8.8
  upstream_mode: load_balance
  cache_size: 4194304
  cache_optimistic: true
  aaaa_disabled: false
  enable_dnssec: false
  ipset: []
  ipset_file: ""
filtering:
  filtering_enabled: true
  parental_enabled: false
  safebrowsing_enabled: false
  protection_enabled: true
os:
  group: ""
  user: ""
  rlimit_nofile: 0
schema_version: 29
AGHYAML
            fi
        fi
    else
        info "AdGuardHome.yaml already present — leaving as-is"
    fi

    # --- 4c. Deploy adguardhome.conf (options file, always overwrite) ---
    if ! dryrun "write adguardhome.conf"; then
        cat > "$_agh_conf" << 'AGHCONF'
DIR="-w /opt/etc/AdGuardHome"
LOG="-l /opt/var/log/AdGuardHome.log"
PID="--pidfile /opt/var/run/AdGuardHome.pid"
UPD="--no-check-update"
OPTIONS="$DIR $LOG $PID $UPD"
AGHCONF
    fi

    # --- 4d. DNS redirect hook for Keenetic netfilter ---
    _dns_hook="/opt/etc/ndm/netfilter.d/10-dns-redirect.sh"
    if ! dryrun "write DNS redirect hook"; then
        cat > "$_dns_hook" << 'DNSHOOK'
#!/bin/sh
# Redirect all LAN DNS queries to AdGuard Home (port 53 on this router).
# Called by Keenetic ndm on each firewall reload.
[ "$type" = "ip6tables" ] && exit 0
[ "$table" = "nat" ] || exit 0

iptables -t nat -C PREROUTING -i br0 -p udp --dport 53 -j REDIRECT --to-port 53 2>/dev/null || \
    iptables -t nat -I PREROUTING 1 -i br0 -p udp --dport 53 -j REDIRECT --to-port 53
iptables -t nat -C PREROUTING -i br0 -p tcp --dport 53 -j REDIRECT --to-port 53 2>/dev/null || \
    iptables -t nat -I PREROUTING 2 -i br0 -p tcp --dport 53 -j REDIRECT --to-port 53
exit 0
DNSHOOK
        chmod 755 "$_dns_hook"
    fi

    # --- 4e. Apply DNS redirect NOW (don't wait for next firewall reload) ---
    if ! dryrun "apply DNS redirect iptables rules"; then
        type=iptables table=nat sh "$_dns_hook" 2>/dev/null || warn "DNS redirect rule already set or iptables unavailable"
    fi

    # --- 4f. Start AdGuard Home ---
    if [ -x /opt/etc/init.d/S99adguardhome ]; then
        if ! dryrun "S99adguardhome start"; then
            /opt/etc/init.d/S99adguardhome start >/dev/null 2>&1 \
                || warn "AdGuardHome did not start cleanly — check /opt/var/log/AdGuardHome.log"
        fi
    else
        warn "S99adguardhome init script not found — AdGuard Home may not start automatically"
    fi

    # Verify AGH is listening on port 53
    _listening=0
    _attempt=0
    while [ "$_attempt" -lt 5 ]; do
        if netstat -lnup 2>/dev/null | grep -q ':53 '; then
            _listening=1; break
        fi
        sleep 1; _attempt=$((_attempt+1))
    done

    if [ "$_listening" = "1" ]; then
        info "AdGuard Home is listening on port 53"
    else
        warn "Port 53 not detected. AdGuard Home may still be starting."
    fi

    info "AGH web UI: http://192.168.1.1:3000"
    info "Complete the AdGuard Home wizard to set admin password and upstream DNS."
    info "After the wizard, all LAN DNS will automatically go through AGH."
}

# -----------------------------------------------------------------------------
# Step 5: Download all release files into staging + verify SHA256
# -----------------------------------------------------------------------------
download_release() {
    step "Downloading release files"

    # files[] — required, with checksums
    _files_count="$(mf_get "$STAGING/manifest.json" 'echo count($m["files"]);')"
    [ "$_files_count" -gt 0 ] || die "Manifest has no files[]"

    _i=0
    while [ "$_i" -lt "$_files_count" ]; do
        _src="$(mf_get "$STAGING/manifest.json" "echo \$m[\"files\"][$_i][\"src\"];")"
        _expected="$(mf_get "$STAGING/manifest.json" "echo isset(\$m[\"files\"][$_i][\"sha256\"]) ? \$m[\"files\"][$_i][\"sha256\"] : '';")"
        _bn="$(printf '%s' "$_src" | tr '/' '_')"
        _dst_staging="$STAGING/files/$_bn"
        curl_fetch "$RAW_BASE/$TARGET_REF/$_src" "$_dst_staging"
        if [ -n "$_expected" ]; then
            _actual="$(sha256_of "$_dst_staging")"
            if [ "$_actual" != "$_expected" ]; then
                die "Integrity check failed for $_src
  expected: $_expected
  actual:   $_actual"
            fi
            verb "ok sha256 $_src"
        else
            verb "no checksum declared for $_src, skipping verify"
        fi
        _i=$((_i + 1))
    done

    # defaults[] — optional, no checksum (templates)
    _def_count="$(mf_get "$STAGING/manifest.json" 'echo count($m["defaults"] ?? []);')"
    _i=0
    while [ "$_i" -lt "$_def_count" ]; do
        _src="$(mf_get "$STAGING/manifest.json" "echo \$m[\"defaults\"][$_i][\"src\"];")"
        _bn="$(printf '%s' "$_src" | tr '/' '_')"
        _dst_staging="$STAGING/defaults/$_bn"
        curl_fetch "$RAW_BASE/$TARGET_REF/$_src" "$_dst_staging"
        _i=$((_i + 1))
    done

    info "Downloaded $_files_count file(s) and $_def_count default(s)"
}

# -----------------------------------------------------------------------------
# Step 6: Backup current install
# -----------------------------------------------------------------------------
backup_current() {
    step "Backing up current installation"
    _ts="$(date '+%Y%m%d-%H%M%S')"
    _bk="$BACKUP_DIR/$_ts"
    mkdir -p "$_bk"
    BACKUP_PATH="$_bk"

    # Backup each existing file that we're about to overwrite
    _files_count="$(mf_get "$STAGING/manifest.json" 'echo count($m["files"]);')"
    _i=0
    _backed=0
    while [ "$_i" -lt "$_files_count" ]; do
        _dst="$(mf_get "$STAGING/manifest.json" "echo \$m[\"files\"][$_i][\"dst\"];")"
        if [ -f "$_dst" ]; then
            _safe="$(printf '%s' "$_dst" | tr '/' '_')"
            cp -p "$_dst" "$_bk/$_safe" 2>/dev/null && _backed=$((_backed + 1)) || :
        fi
        _i=$((_i + 1))
    done
    [ -f "$VERSION_FILE" ] && cp -p "$VERSION_FILE" "$_bk/.version" 2>/dev/null || :
    info "Backed up $_backed file(s) to $_bk"

    # Rotate
    # shellcheck disable=SC2012  # ls is safe here: backup names are timestamps
    _kept=$(ls -1 "$BACKUP_DIR" 2>/dev/null | sort -r | wc -l)
    if [ "$_kept" -gt "$BACKUP_KEEP" ]; then
        # shellcheck disable=SC2012
        ls -1 "$BACKUP_DIR" | sort -r | tail -n +$((BACKUP_KEEP + 1)) | while read -r _old; do
            [ -n "$_old" ] || continue
            verb "rotating old backup: $_old"
            rm -rf "${BACKUP_DIR:?}/${_old:?}"
        done
    fi
}

# -----------------------------------------------------------------------------
# Step 7: Atomic apply files + create directories + chmod
# -----------------------------------------------------------------------------
apply_release() {
    step "Applying files"

    # Create directories
    _d_count="$(mf_get "$STAGING/manifest.json" 'echo count($m["directories"] ?? []);')"
    _i=0
    while [ "$_i" -lt "$_d_count" ]; do
        _path="$(mf_get "$STAGING/manifest.json" "echo \$m[\"directories\"][$_i][\"path\"];")"
        _mode="$(mf_get "$STAGING/manifest.json" "echo \$m[\"directories\"][$_i][\"mode\"] ?? '755';")"
        if dryrun "mkdir -p $_path && chmod $_mode $_path"; then
            :
        else
            mkdir -p "$_path"
            chmod "$_mode" "$_path"
        fi
        _i=$((_i + 1))
    done

    # Apply files
    _f_count="$(mf_get "$STAGING/manifest.json" 'echo count($m["files"]);')"
    _i=0
    while [ "$_i" -lt "$_f_count" ]; do
        _src="$(mf_get "$STAGING/manifest.json" "echo \$m[\"files\"][$_i][\"src\"];")"
        _dst="$(mf_get "$STAGING/manifest.json" "echo \$m[\"files\"][$_i][\"dst\"];")"
        _mode="$(mf_get "$STAGING/manifest.json" "echo \$m[\"files\"][$_i][\"mode\"];")"
        _bn="$(printf '%s' "$_src" | tr '/' '_')"
        _staged="$STAGING/files/$_bn"
        if dryrun "install $_staged -> $_dst (mode $_mode)"; then
            _i=$((_i + 1)); continue
        fi
        mkdir -p "$(dirname "$_dst")"
        cp -f "$_staged" "$_dst.new" || die "cp failed for $_dst"
        chmod "$_mode" "$_dst.new"
        mv -f "$_dst.new" "$_dst" || die "mv failed for $_dst"
        _i=$((_i + 1))
    done

    # Apply defaults (only if missing)
    _d_count="$(mf_get "$STAGING/manifest.json" 'echo count($m["defaults"] ?? []);')"
    _i=0
    while [ "$_i" -lt "$_d_count" ]; do
        _src="$(mf_get "$STAGING/manifest.json" "echo \$m[\"defaults\"][$_i][\"src\"];")"
        _dst="$(mf_get "$STAGING/manifest.json" "echo \$m[\"defaults\"][$_i][\"dst\"];")"
        _mode="$(mf_get "$STAGING/manifest.json" "echo \$m[\"defaults\"][$_i][\"mode\"];")"
        _only_missing="$(mf_get "$STAGING/manifest.json" "echo (\$m[\"defaults\"][$_i][\"only_if_missing\"] ?? true) ? 1 : 0;")"
        if [ "$_only_missing" = "1" ] && [ -e "$_dst" ]; then
            verb "default already present: $_dst (skipped)"
            _i=$((_i + 1)); continue
        fi
        _bn="$(printf '%s' "$_src" | tr '/' '_')"
        _staged="$STAGING/defaults/$_bn"
        if dryrun "install default $_staged -> $_dst (mode $_mode)"; then
            _i=$((_i + 1)); continue
        fi
        mkdir -p "$(dirname "$_dst")"
        cp -f "$_staged" "$_dst" || die "cp failed for default $_dst"
        chmod "$_mode" "$_dst"
        _i=$((_i + 1))
    done

    # Tighten sensitive files
    [ -f /opt/etc/xray/.kn_pass ]      && chmod 600 /opt/etc/xray/.kn_pass      || :
    [ -f /opt/etc/xray/ui-auth.json ]  && chmod 600 /opt/etc/xray/ui-auth.json  || :
    [ -d /opt/etc/wireguard ]          && chmod 700 /opt/etc/wireguard          || :
    [ -f /opt/etc/wireguard/wg0.conf ] && chmod 600 /opt/etc/wireguard/wg0.conf || :
    for _conf in /opt/etc/wireguard/*.conf; do
        [ -f "$_conf" ] && chmod 600 "$_conf"
    done

    # Record version
    printf '%s\n' "$TARGET_VERSION" > "$VERSION_FILE"
}

# -----------------------------------------------------------------------------
# Step 8: Migrations (best-effort)
# -----------------------------------------------------------------------------
run_migrations() {
    step "Running migrations"
    _old="${OLD_VERSION:-0.0.0}"
    if [ -x /opt/etc/xray/migrate.sh ]; then
        if dryrun "migrate.sh $_old $TARGET_VERSION"; then return; fi
        /opt/etc/xray/migrate.sh "$_old" "$TARGET_VERSION" \
            || warn "Some migrations failed (continuing). See $INSTALL_LOG"
    else
        verb "migrate.sh not found, skipping"
    fi
}

# -----------------------------------------------------------------------------
# Step 9: Auto-update cron
# -----------------------------------------------------------------------------
register_cron() {
    step "Registering auto-update cron"
    _cron_dir="/opt/etc/cron.d"
    mkdir -p "$_cron_dir"
    _file="$_cron_dir/xray-vpn-update"
    _line="30 4 * * * root /opt/etc/xray/update.sh --cron >> /opt/var/log/xray/update.log 2>&1"
    if dryrun "write $_file"; then return; fi
    printf '%s\n' "$_line" > "$_file"
    chmod 644 "$_file"
    if [ -x /opt/etc/init.d/S10cron ]; then
        /opt/etc/init.d/S10cron restart >/dev/null 2>&1 || warn "could not restart cron"
    fi
}

# -----------------------------------------------------------------------------
# Step 10: Restart services
# -----------------------------------------------------------------------------
restart_services() {
    step "Restarting services"
    if dryrun "restart lighttpd / adguard / xray / wireguard"; then return; fi

    [ -x /opt/etc/init.d/S80lighttpd ] && /opt/etc/init.d/S80lighttpd restart >/dev/null 2>&1 || warn "lighttpd restart issue"

    if [ "$SKIP_ADGUARD" != "1" ] && [ -x /opt/etc/init.d/S99adguardhome ]; then
        /opt/etc/init.d/S99adguardhome restart >/dev/null 2>&1 || warn "AdGuardHome restart issue"
        # Re-apply DNS redirect after AGH restart (iptables rules may have been flushed)
        _dns_hook="/opt/etc/ndm/netfilter.d/10-dns-redirect.sh"
        [ -x "$_dns_hook" ] && type=iptables table=nat sh "$_dns_hook" 2>/dev/null || true
    fi

    # Restart Xray only if already onboarded (otherwise wizard will start it)
    if [ -f /opt/etc/xray/.onboarded ] && [ -x /opt/etc/init.d/S22xray ]; then
        /opt/etc/init.d/S22xray restart >/dev/null 2>&1 || warn "Xray restart issue"
    fi

    # WireGuard only if enabled in features.json
    _wg_enabled="$(grep -q '"wireguard"[[:space:]]*:[[:space:]]*false' /opt/etc/xray/features.json 2>/dev/null && echo 0 || echo 1)"
    if [ "$_wg_enabled" = "1" ] && [ -x /opt/etc/init.d/S99wireguard ]; then
        /opt/etc/init.d/S99wireguard restart >/dev/null 2>&1 \
            || /opt/etc/init.d/S99wireguard start >/dev/null 2>&1 \
            || warn "WireGuard start issue"
    fi
}

# -----------------------------------------------------------------------------
# Uninstall path
# -----------------------------------------------------------------------------
do_uninstall() {
    step "Uninstalling keenetic-xray-vpn"
    info "Stopping services..."
    [ -x /opt/etc/init.d/S22xray ]      && /opt/etc/init.d/S22xray stop >/dev/null 2>&1 || :
    [ -x /opt/etc/init.d/S99wireguard ] && /opt/etc/init.d/S99wireguard stop >/dev/null 2>&1 || :

    info "Removing files..."
    rm -f /opt/share/www/xray/index.php /opt/share/www/xray/api.php
    rm -f /opt/etc/xray/xray-manager.sh /opt/etc/xray/update.sh /opt/etc/xray/migrate.sh
    rm -f /opt/etc/init.d/S22xray
    rm -f /opt/etc/lighttpd/conf.d/91-shadowsocks.conf
    rm -f /opt/etc/cron.d/xray-vpn-update

    warn "User data preserved in: /opt/etc/xray/ (subscriptions, keys, rules, .kn_pass, ui-auth.json, backups)"
    warn "WireGuard configs preserved in: /opt/etc/wireguard/"
    warn "Logs preserved in: /opt/var/log/xray/"
    warn "To delete everything: rm -rf /opt/etc/xray /opt/etc/wireguard /opt/var/log/xray"

    if [ -x /opt/etc/init.d/S80lighttpd ]; then
        /opt/etc/init.d/S80lighttpd restart >/dev/null 2>&1 || :
    fi
    info "Uninstall complete"
}

# -----------------------------------------------------------------------------
# Main
# -----------------------------------------------------------------------------
main() {
    info "keenetic-xray-vpn installer (mode: $MODE)"
    info "Repo: $REPO"
    [ "$DRY_RUN" = "1" ] && warn "DRY RUN — no changes will be made"

    acquire_lock

    if [ "$MODE" = "uninstall" ]; then
        do_uninstall
        exit 0
    fi

    preflight
    check_entware
    bootstrap_php       # must run before download_manifest (mf_get needs php)

    # Remember old version (for migrations + skip-same)
    OLD_VERSION="$(cat "$VERSION_FILE" 2>/dev/null || echo "0.0.0")"
    info "Currently installed version: $OLD_VERSION"

    download_manifest

    if [ "$MODE" != "reinstall" ]; then
        _cmp="$(semver_cmp "$TARGET_VERSION" "$OLD_VERSION")"
        if [ "$_cmp" = "same" ] && [ "$MODE" = "install" ]; then
            warn "Version $TARGET_VERSION already installed. Use --reinstall to force, or --upgrade if newer."
        fi
        if [ "$_cmp" = "older" ]; then
            warn "Target $TARGET_VERSION is OLDER than installed $OLD_VERSION. Continuing (rollback)."
        fi
    fi

    install_packages

    if [ "$SKIP_ADGUARD" != "1" ]; then
        bootstrap_adguard
    fi

    download_release
    backup_current

    # From here on, errors should attempt rollback
    if ! apply_release; then
        rollback "$BACKUP_PATH"
        die "apply failed, rolled back"
    fi

    run_migrations
    register_cron
    restart_services

    cat <<EOF

==============================================
  Установка завершена — v$TARGET_VERSION
  Backup:  $BACKUP_PATH
  Лог:     $INSTALL_LOG

  Открой:  http://192.168.1.1:91
  Мастер настройки проведёт за 5 шагов.
==============================================
EOF
}

main "$@"
