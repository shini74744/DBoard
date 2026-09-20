#!/usr/bin/env bash
set -Eeuo pipefail

APP="DUI-Gateway"
INSTALL_ROOT="/etc/DUI-Gateway"
CONFIG_FILE="$INSTALL_ROOT/gateway.env"
BINARY_PATH="/usr/local/bin/DUI-Gateway"
SERVICE_NAME="DUI-Gateway.service"
SERVICE_PATH="/etc/systemd/system/$SERVICE_NAME"
VERSION_URL="https://raw.githubusercontent.com/shini74744/DBoard/main/gateway/VERSION"
RELEASE_BASE="https://github.com/shini74744/DBoard/releases/download"

ACTION="install"
BACKEND=""
AES_KEY_VALUE=""
PORT_VALUE="3939"
PATH_PREFIX_VALUE="/dui/gw"
API_PREFIX_VALUE="/api/v1"
SUB_PREFIX_VALUE="/s"
BACKEND_SUB_PREFIX_VALUE=""
CORS_ORIGIN_VALUE="*"
ALLOWED_ORIGINS_VALUE="*"
REQUEST_TIMEOUT_VALUE="30000"
PAYMENT_PATHS_VALUE="/api/v1/guest/payment/notify"
ENABLE_LOGGING_VALUE="false"
DEBUG_MODE_VALUE="false"
VERSION_VALUE=""
BINARY_SOURCE=""
PURGE=0
YES=0
ARCH=""

log() { printf '[DUI-Gateway] %s\n' "$*"; }
fail() { printf '[DUI-Gateway] ERROR: %s\n' "$*" >&2; exit 1; }

usage() {
cat <<'EOF'
DUI-Gateway installer

Usage:
  install.sh install --backend https://backend.example.com [options]
  install.sh upgrade
  install.sh status
  install.sh uninstall [--purge] [--yes]

Options:
  --backend URL
  --aes-key KEY                  16/24/32-byte key; generated if omitted
  --port PORT                    default 3939
  --path-prefix PATH             default /dui/gw
  --api-prefix PATH              default /api/v1
  --subscription-prefix PATH     default /s
  --backend-subscription-prefix PATH
  --cors-origin ORIGIN           default *
  --allowed-origins LIST         comma separated, default *
  --request-timeout MS           default 30000
  --payment-notify-paths LIST    comma separated direct paths
  --enable-logging true|false
  --debug true|false
  --version TAG                  e.g. gateway-v0.1.0
  --binary PATH                  local binary instead of GitHub Release
  --purge                        delete /etc/DUI-Gateway on uninstall
  --yes                          non-interactive uninstall
EOF
}

parse_args() {
  while [ $# -gt 0 ]; do
    case "$1" in
      install|upgrade|status|uninstall|help) ACTION="$1"; shift ;;
      --backend) BACKEND="$2"; shift 2 ;;
      --aes-key) AES_KEY_VALUE="$2"; shift 2 ;;
      --port) PORT_VALUE="$2"; shift 2 ;;
      --path-prefix) PATH_PREFIX_VALUE="$2"; shift 2 ;;
      --api-prefix) API_PREFIX_VALUE="$2"; shift 2 ;;
      --subscription-prefix) SUB_PREFIX_VALUE="$2"; shift 2 ;;
      --backend-subscription-prefix) BACKEND_SUB_PREFIX_VALUE="$2"; shift 2 ;;
      --cors-origin) CORS_ORIGIN_VALUE="$2"; shift 2 ;;
      --allowed-origins) ALLOWED_ORIGINS_VALUE="$2"; shift 2 ;;
      --request-timeout) REQUEST_TIMEOUT_VALUE="$2"; shift 2 ;;
      --payment-notify-paths) PAYMENT_PATHS_VALUE="$2"; shift 2 ;;
      --enable-logging) ENABLE_LOGGING_VALUE="$2"; shift 2 ;;
      --debug) DEBUG_MODE_VALUE="$2"; shift 2 ;;
      --version) VERSION_VALUE="$2"; shift 2 ;;
      --binary) BINARY_SOURCE="$2"; shift 2 ;;
      --purge) PURGE=1; shift ;;
      --yes|-y) YES=1; shift ;;
      -h|--help) ACTION="help"; shift ;;
      *) fail "unknown argument: $1" ;;
    esac
  done
}

check_root() {
  [ "$(id -u)" -eq 0 ] || fail "run with sudo/root"
}

detect_arch() {
  case "$(uname -m)" in
    x86_64|amd64) ARCH="amd64" ;;
    aarch64|arm64) ARCH="arm64" ;;
    *) fail "unsupported architecture: $(uname -m)" ;;
  esac
}

ensure_curl() {
  if command -v curl >/dev/null 2>&1; then return; fi
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y curl ca-certificates
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y curl ca-certificates
  elif command -v yum >/dev/null 2>&1; then
    yum install -y curl ca-certificates
  else
    fail "curl is required"
  fi
}

resolve_version() {
  if [ -n "$VERSION_VALUE" ]; then return; fi
  VERSION_VALUE="$(curl -fsSL "$VERSION_URL" 2>/dev/null | tr -d '\r\n ' || true)"
  [ -n "$VERSION_VALUE" ] || VERSION_VALUE="gateway-v0.1.0"
}

generate_key() {
  if [ -n "$AES_KEY_VALUE" ]; then return; fi
  if command -v openssl >/dev/null 2>&1; then
    AES_KEY_VALUE="$(openssl rand -hex 8)"
  else
    AES_KEY_VALUE="$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
  fi
}

validate_key() {
  local n="${#AES_KEY_VALUE}"
  case "$n" in
    16|24|32) ;;
    *) fail "AES key must be 16, 24 or 32 characters" ;;
  esac
}

stage_binary() {
  local tmp="$1"
  if [ -n "$BINARY_SOURCE" ]; then
    [ -f "$BINARY_SOURCE" ] || fail "binary not found: $BINARY_SOURCE"
    cp "$BINARY_SOURCE" "$tmp"
  else
    resolve_version
    local url="$RELEASE_BASE/$VERSION_VALUE/DUI-Gateway-linux-$ARCH"
    log "Downloading $url"
    curl -fsSL "$url" -o "$tmp"
  fi
  chmod 755 "$tmp"
  "$tmp" -v >/dev/null
}

write_config() {
  mkdir -p "$INSTALL_ROOT"
  chmod 700 "$INSTALL_ROOT"
  [ -n "$BACKEND_SUB_PREFIX_VALUE" ] || BACKEND_SUB_PREFIX_VALUE="$SUB_PREFIX_VALUE"

  cat >"$CONFIG_FILE" <<EOF
PORT=$PORT_VALUE
BACKEND_API_URL=$BACKEND
PATH_PREFIX=$PATH_PREFIX_VALUE
API_PREFIX=$API_PREFIX_VALUE
SUBSCRIPTION_PREFIX=$SUB_PREFIX_VALUE
BACKEND_SUBSCRIPTION_PREFIX=$BACKEND_SUB_PREFIX_VALUE
CORS_ORIGIN=$CORS_ORIGIN_VALUE
ALLOWED_ORIGINS=$ALLOWED_ORIGINS_VALUE
REQUEST_TIMEOUT=$REQUEST_TIMEOUT_VALUE
ENABLE_LOGGING=$ENABLE_LOGGING_VALUE
DEBUG_MODE=$DEBUG_MODE_VALUE
ALLOWED_PAYMENT_NOTIFY_PATHS=$PAYMENT_PATHS_VALUE
AES_KEY=$AES_KEY_VALUE
EOF
  chmod 600 "$CONFIG_FILE"
}

write_service() {
  cat >"$SERVICE_PATH" <<EOF
[Unit]
Description=DUI-Gateway encrypted API gateway
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
ExecStart=$BINARY_PATH -env $CONFIG_FILE
Restart=always
RestartSec=3
LimitNOFILE=1048576
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
}

wait_health() {
  local i
  for i in $(seq 1 20); do
    if curl -fsS "http://127.0.0.1:$PORT_VALUE/healthz" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  return 1
}

perform_install() {
  [ -n "$BACKEND" ] || fail "--backend is required for install"
  generate_key
  validate_key
  local tmp
  tmp="$(mktemp)"
  stage_binary "$tmp"
  if systemctl is-active "$SERVICE_NAME" >/dev/null 2>&1; then systemctl stop "$SERVICE_NAME"; fi
  install -m 755 "$tmp" "$BINARY_PATH"
  rm -f "$tmp"
  write_config
  write_service
  systemctl enable --now "$SERVICE_NAME" >/dev/null
  wait_health || {
    journalctl -u "$SERVICE_NAME" -n 40 --no-pager || true
    fail "health check failed"
  }
  log "Installed successfully"
  log "Service: $SERVICE_NAME"
  log "Config: $CONFIG_FILE"
  log "Path prefix: $PATH_PREFIX_VALUE"
  log "AES key: $AES_KEY_VALUE"
}

read_existing_port() {
  PORT_VALUE="$(sed -n 's/^PORT=//p' "$CONFIG_FILE" 2>/dev/null | tail -1)"
  [ -n "$PORT_VALUE" ] || PORT_VALUE="3939"
}

perform_upgrade() {
  [ -f "$CONFIG_FILE" ] || fail "existing config not found; use install"
  local tmp
  tmp="$(mktemp)"
  stage_binary "$tmp"
  read_existing_port
  systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
  install -m 755 "$tmp" "$BINARY_PATH"
  rm -f "$tmp"
  write_service
  systemctl enable --now "$SERVICE_NAME" >/dev/null
  wait_health || fail "health check failed after upgrade"
  log "Upgrade complete"
  "$BINARY_PATH" -v
}

perform_status() {
  echo "DUI-Gateway status"
  echo
  "$BINARY_PATH" -v 2>/dev/null || true
  systemctl status "$SERVICE_NAME" --no-pager -l 2>/dev/null | sed -n '1,25p' || true
  if [ -f "$CONFIG_FILE" ]; then
    echo
    echo "Config:"
    grep -Ev '^AES_KEY=' "$CONFIG_FILE" || true
    echo "AES_KEY=[hidden]"
  fi
}

perform_uninstall() {
  if [ "$YES" -ne 1 ]; then
    read -r -p "Uninstall DUI-Gateway? [y/N]: " ans
    [[ "$ans" =~ ^[Yy]$ ]] || exit 0
  fi
  systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
  systemctl disable "$SERVICE_NAME" >/dev/null 2>&1 || true
  rm -f "$SERVICE_PATH" "$BINARY_PATH"
  systemctl daemon-reload || true
  if [ "$PURGE" -eq 1 ]; then rm -rf "$INSTALL_ROOT"; fi
  log "Uninstall complete"
}

main() {
  parse_args "$@"
  case "$ACTION" in
    help) usage; exit 0 ;;
    status) perform_status; exit 0 ;;
  esac
  check_root
  detect_arch
  ensure_curl
  case "$ACTION" in
    install) perform_install ;;
    upgrade) perform_upgrade ;;
    uninstall) perform_uninstall ;;
    *) fail "unknown action: $ACTION" ;;
  esac
}

main "$@"
