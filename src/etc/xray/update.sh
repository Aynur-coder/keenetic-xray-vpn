#!/bin/sh
# keenetic-xray-vpn auto-updater
# POSIX sh (BusyBox ash compatible).
#
# Modes:
#   --check        Print JSON {current, latest, available, changelog} to stdout
#   --apply        Run install.sh --upgrade with the latest tag
#   --cron         Like --apply, but only if features.auto_update == true
#   --rollback     Restore latest backup atomically
#
# All output goes to /opt/var/log/xray/update.log (mode --apply/--cron).

set -eu

REPO_OWNER="Aynur-coder"
REPO_NAME="keenetic-xray-vpn"
REPO="$REPO_OWNER/$REPO_NAME"
RAW_BASE="https://raw.githubusercontent.com/$REPO"

XRAY_DIR="/opt/etc/xray"
VERSION_FILE="$XRAY_DIR/.version"
FEATURES_FILE="$XRAY_DIR/features.json"
BACKUP_DIR="$XRAY_DIR/backups"
UPDATE_LOG="/opt/var/log/xray/update.log"
STATE_FILE="/opt/tmp/xray-vpn-update.state"

MODE="${1:-}"

current_version() {
    [ -f "$VERSION_FILE" ] && tr -d ' \r\n' < "$VERSION_FILE" || echo "0.0.0"
}

# gh_mirrors <url> — echo the URL plus GitHub mirror alternatives (one per line) that
# usually survive TSPU resets of raw.githubusercontent.com / github.com. Same verified
# set as install.sh: jsDelivr edges for repo files, gh-proxy/ghproxy.net/ghfast.top
# passthrough proxies for both repo files and release-download assets.
gh_mirrors() {
    _u="$1"
    printf '%s\n' "$_u"
    case "$_u" in
        https://raw.githubusercontent.com/*)
            _rest="${_u#https://raw.githubusercontent.com/}"   # USER/REPO/REF/PATH
            _tail="${_rest#"$REPO"/}"                          # REF/PATH
            printf '%s\n' "https://cdn.jsdelivr.net/gh/$REPO@$_tail"
            printf '%s\n' "https://testingcf.jsdelivr.net/gh/$REPO@$_tail"
            printf '%s\n' "https://gh-proxy.com/$_u"
            printf '%s\n' "https://ghproxy.net/$_u"
            printf '%s\n' "https://ghfast.top/$_u"
            ;;
        https://github.com/*)
            printf '%s\n' "https://gh-proxy.com/$_u"
            printf '%s\n' "https://ghproxy.net/$_u"
            printf '%s\n' "https://ghfast.top/$_u"
            ;;
    esac
}

# curl_gh <url> — tries SOCKS5 via local xray first (bypasses TSPU for the router's own
# traffic which doesn't go through the transparent proxy), then a direct request, then
# GitHub mirrors. Returns the response body on stdout.
_xray_running() {
    [ -f /opt/var/run/xray.pid ] && kill -0 "$(cat /opt/var/run/xray.pid 2>/dev/null)" 2>/dev/null
}
curl_gh() {
    _u="$1"
    if _xray_running; then
        _out=$(/opt/bin/curl -fsSL --max-time 8 --socks5-hostname 127.0.0.1:1081 "$_u" 2>/dev/null) \
            && { printf '%s' "$_out"; return 0; }
    fi
    # Direct, then mirrors — survives a TSPU reset of raw.githubusercontent.com
    _oldifs="$IFS"; IFS='
'
    for _m in $(gh_mirrors "$_u"); do
        IFS="$_oldifs"
        _out=$(/opt/bin/curl -fsSL --max-time 15 "$_m" 2>/dev/null) \
            && { printf '%s' "$_out"; return 0; }
        IFS='
'
    done
    IFS="$_oldifs"
    return 1
}

# download_gh <github-url> <out-file> — download a file with the same mirror fallback.
download_gh() {
    _du="$1"; _do="$2"
    _oldifs="$IFS"; IFS='
'
    for _m in $(gh_mirrors "$_du"); do
        IFS="$_oldifs"
        /opt/bin/curl -fsSL --max-time 60 --retry 2 --retry-delay 3 -o "$_do" "$_m" 2>/dev/null \
            && return 0
        IFS='
'
    done
    IFS="$_oldifs"
    return 1
}

latest_version() {
    curl_gh "$RAW_BASE/main/VERSION" | tr -d ' \r\n'
}

# semver compare: prints newer/same/older for A vs B
semver_cmp() {
    awk -v A="$1" -v B="$2" 'BEGIN{
        n=split(A,a,"."); m=split(B,b,".");
        for(i=1;i<=3;i++){a[i]=a[i]+0; b[i]=b[i]+0}
        for(i=1;i<=3;i++){
            if(a[i]>b[i]){print "newer"; exit}
            if(a[i]<b[i]){print "older"; exit}
        }
        print "same"
    }'
}

# Extract a section [vX.Y.Z] from changelog.md
changelog_section() {
    _ver="$1"
    curl -fsSL --max-time 10 "$RAW_BASE/main/changelog.md" 2>/dev/null \
        | awk -v v="$_ver" '
            $0 ~ "^## \\[" v "\\]" {flag=1; next}
            flag && /^## \[/ {flag=0}
            flag {print}
        '
}

# JSON-escape stdin to a string literal
json_escape_stdin() {
    awk '
        BEGIN{
            for(i=0;i<256;i++) ord[sprintf("%c",i)]=i;
            printf "\"";
        }
        {
            line = $0;
            gsub(/\\/, "\\\\", line);
            gsub(/"/, "\\\"", line);
            gsub(/\t/, "\\t", line);
            printf "%s\\n", line;
        }
        END{ printf "\"" }
    '
}

# Read features.logs_enabled flag (best-effort). Returns 0 (true) unless explicitly false.
logs_enabled() {
    [ -f "$FEATURES_FILE" ] || return 0
    grep -q '"logs_enabled":[[:space:]]*false' "$FEATURES_FILE" 2>/dev/null && return 1 || return 0
}

# Read features.auto_update flag (best-effort)
auto_update_enabled() {
    [ -f "$FEATURES_FILE" ] || { echo "0"; return; }
    if command -v php >/dev/null 2>&1 || command -v php-cgi >/dev/null 2>&1; then
        _php="$(command -v php || command -v php-cgi)"
        "$_php" -r '$f=json_decode(@file_get_contents("'"$FEATURES_FILE"'"),true); echo (isset($f["auto_update"]) && $f["auto_update"]) ? "1" : "0";' 2>/dev/null || echo "0"
    else
        # rough fallback: grep
        grep -q '"auto_update":[[:space:]]*true' "$FEATURES_FILE" 2>/dev/null && echo "1" || echo "0"
    fi
}

write_state() {
    # write_state <status> <message>
    # Line 4 carries our PID so the web UI can detect a dead/finished updater reliably
    # (instead of guessing by elapsed time).
    mkdir -p "$(dirname "$STATE_FILE")" 2>/dev/null || :
    printf '%s\n%s\n%s\n%s\n' "$1" "$(date '+%s')" "$2" "$$" > "$STATE_FILE"
}

# Re-exec ourselves in a brand-new session, fully detached from the web server's
# process tree. Without this, install.sh restarting lighttpd mid-update kills this
# script (and install.sh) before completion, leaving the state stuck at "applying".
reexec_detached() {
    [ -n "${XRAY_UPDATE_DETACHED:-}" ] && return 0
    XRAY_UPDATE_DETACHED=1; export XRAY_UPDATE_DETACHED
    if command -v setsid >/dev/null 2>&1; then
        setsid "$0" "$MODE" >/dev/null 2>&1 </dev/null &
    else
        nohup "$0" "$MODE" >/dev/null 2>&1 </dev/null &
    fi
    exit 0
}

cmd_check() {
    _cur="$(current_version)"
    _new="$(latest_version)"
    if [ -z "$_new" ]; then
        printf '{"error":"no_network","current":"%s"}\n' "$_cur"
        exit 1
    fi
    _cmp="$(semver_cmp "$_new" "$_cur")"
    _available="false"
    [ "$_cmp" = "newer" ] && _available="true"
    _changelog="$(changelog_section "$_new" | json_escape_stdin)"
    [ -z "$_changelog" ] && _changelog='""'
    printf '{"current":"%s","latest":"%s","available":%s,"changelog":%s}\n' \
        "$_cur" "$_new" "$_available" "$_changelog"
}

cmd_apply() {
    reexec_detached
    mkdir -p "$(dirname "$UPDATE_LOG")"
    {
        echo "============================================================"
        date '+[%F %T] update.sh --apply starting'

        _cur="$(current_version)"
        _new="$(latest_version)"
        if [ -z "$_new" ]; then
            echo "ERROR: cannot reach GitHub"
            write_state "failed" "Не удалось проверить обновления"
            exit 1
        fi
        _cmp="$(semver_cmp "$_new" "$_cur")"
        if [ "$_cmp" != "newer" ]; then
            echo "Already at latest ($_cur). Nothing to do."
            write_state "done" "Уже актуальная версия $_cur"
            exit 0
        fi

        write_state "downloading" "Скачиваю установщик v$_new"
        _inst="/opt/tmp/xray-vpn-install-$$.sh"
        # Primary: raw.githubusercontent.com (versioned) + jsDelivr/gh-proxy mirrors.
        # Same domain used for the version check, so it is usually in the DNS cache; the
        # mirrors carry it through when TSPU resets the direct connection.
        _dl_ok=0
        if download_gh "$RAW_BASE/v$_new/install.sh" "$_inst"; then
            _dl_ok=1
        fi
        # Fallback 1: GitHub release asset (versioned) + mirrors
        if [ "$_dl_ok" = "0" ]; then
            echo "raw + mirrors failed, trying release asset…"
            if download_gh "https://github.com/$REPO/releases/download/v$_new/install.sh" "$_inst"; then
                _dl_ok=1
            fi
        fi
        # Fallback 2: GitHub releases/latest (no version pin) + mirrors
        if [ "$_dl_ok" = "0" ]; then
            echo "release asset failed, trying releases/latest…"
            if download_gh "https://github.com/$REPO/releases/latest/download/install.sh" "$_inst"; then
                _dl_ok=1
            fi
        fi
        if [ "$_dl_ok" = "0" ]; then
            write_state "failed" "Не удалось скачать install.sh"
            echo "ERROR: install.sh download failed"
            exit 1
        fi
        chmod 755 "$_inst"

        write_state "applying" "Применяю обновление v$_cur → v$_new"
        if sh "$_inst" --upgrade --version "v$_new" --verbose; then
            write_state "done" "Обновлено до v$_new"
            echo "OK"
        else
            write_state "failed" "Обновление прервано — см. лог"
            echo "ERROR: install.sh failed"
            rm -f "$_inst"
            exit 1
        fi
        rm -f "$_inst"
    } >> "$UPDATE_LOG" 2>&1
}

cmd_cron() {
    _en="$(auto_update_enabled)"
    if [ "$_en" != "1" ]; then
        # Only write to disk if logging is enabled — the cron fires daily and the
        # write is otherwise pointless noise when auto-update is deliberately off.
        logs_enabled && echo "auto_update disabled, skipping" >> "$UPDATE_LOG" 2>/dev/null || :
        exit 0
    fi
    cmd_apply
}

cmd_rollback() {
    reexec_detached
    mkdir -p "$(dirname "$UPDATE_LOG")"
    {
        date '+[%F %T] update.sh --rollback starting'
        if [ ! -d "$BACKUP_DIR" ]; then
            write_state "failed" "Нет бэкапов"
            echo "No backup dir"
            exit 1
        fi
        # shellcheck disable=SC2012
        _last="$(ls -1 "$BACKUP_DIR" 2>/dev/null | sort -r | head -1)"
        if [ -z "$_last" ]; then
            write_state "failed" "Нет бэкапов"
            echo "No backups available"
            exit 1
        fi
        echo "Restoring from $BACKUP_DIR/$_last"
        write_state "applying" "Откатываю на бэкап $_last"
        for _f in "$BACKUP_DIR/$_last"/*; do
            [ -f "$_f" ] || continue
            _bn="$(basename "$_f")"
            case "$_bn" in
                .version) cp -p "$_f" "$VERSION_FILE" && echo "  restored .version" ;;
                *)
                    # filenames in backup are paths with / replaced by _ — reverse
                    _dst="$(printf '%s' "$_bn" | tr '_' '/')"
                    # We can't reliably reverse our path-encoded filenames; for safety,
                    # the backup is informational. Real restore is "wait for install.sh
                    # --version of previous" — recorded in .version.
                    echo "  (note) backup file $_bn — manual restore"
                    ;;
            esac
        done
        # Re-apply the OLDER version via install.sh
        _prev="$(cat "$VERSION_FILE" 2>/dev/null || echo "0.0.0")"
        echo "Re-installing v$_prev"
        write_state "applying" "Переустанавливаю v$_prev"
        _inst="/opt/tmp/xray-vpn-install-$$.sh"
        if /opt/bin/curl -fsSL --max-time 60 --retry 3 --retry-delay 5 \
                -o "$_inst" "$RAW_BASE/v$_prev/install.sh" 2>/dev/null || \
           /opt/bin/curl -fsSL --max-time 60 --retry 3 --retry-delay 5 \
                -o "$_inst" "$RAW_BASE/main/install.sh"; then
            chmod 755 "$_inst"
            if sh "$_inst" --upgrade --version "v$_prev"; then
                write_state "done" "Откачено к v$_prev"
                echo "OK"
            else
                write_state "failed" "install.sh failed during rollback"
                exit 1
            fi
            rm -f "$_inst"
        else
            write_state "failed" "install.sh download failed"
            exit 1
        fi
    } >> "$UPDATE_LOG" 2>&1
}

case "$MODE" in
    --check)    cmd_check ;;
    --apply)    cmd_apply ;;
    --cron)     cmd_cron ;;
    --rollback) cmd_rollback ;;
    *)
        cat <<EOF
Usage: $0 --check | --apply | --cron | --rollback

  --check     Print JSON status of available updates
  --apply     Download and apply the latest release (synchronous)
  --cron      Like --apply, but only runs if features.auto_update=true
  --rollback  Re-install the previously installed version

Logs:        $UPDATE_LOG
State file:  $STATE_FILE
EOF
        exit 0
        ;;
esac
