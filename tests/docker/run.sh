#!/bin/sh
# Bring up the mock Keenetic environment and run install.sh inside it.
# Usage: ./run.sh [install|update|smoke|shell|down]

set -eu

CMD="${1:-install}"
cd "$(dirname "$0")"

case "$CMD" in
    up|build)
        docker compose build
        docker compose up -d
        ;;
    install)
        docker compose up -d --build
        echo ">>> running install.sh inside mock"
        docker compose exec keenetic-mock sh /repo/install.sh --verbose --repo Aynur-coder/keenetic-xray-vpn
        ;;
    update)
        docker compose exec keenetic-mock /opt/etc/xray/update.sh --check
        ;;
    smoke)
        docker compose exec keenetic-mock sh /repo/tests/smoke.sh
        ;;
    shell)
        docker compose exec keenetic-mock sh
        ;;
    down)
        docker compose down
        ;;
    *)
        cat <<EOF
Usage: $0 {up|install|update|smoke|shell|down}

  up       Build image and start container
  install  Up + run install.sh inside (--verbose)
  update   Run update.sh --check inside
  smoke    Run smoke.sh inside
  shell    Drop into BusyBox shell inside
  down     Stop and remove container
EOF
        ;;
esac
