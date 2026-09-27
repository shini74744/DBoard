#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="https://github.com/shini74744/DBoard.git"
RAW_BASE="https://raw.githubusercontent.com/shini74744/DBoard/main"
IMAGE="ghcr.io/shini74744/dboard:latest"

DBOARD_ROOT="/opt/dboard"
DATA_DIR="${DBOARD_DATA_DIR:-/opt/dboard/shared}"
DOCKER_DIR="/opt/dboard/docker"

REDIS_VERSION="8.4.2"
SWOOLE_VERSION="6.2.2"

MODE=""
WITH_GATEWAY=""
GATEWAY_BACKEND=""
GATEWAY_PORT="3939"
ADMIN_ACCOUNT=""
DB_MODE="sqlite"
ASSUME_YES=0

C_RESET='\033[0m'
C_GREEN='\033[32m'
C_YELLOW='\033[33m'
C_RED='\033[31m'

info() { echo -e "${C_GREEN}[DBoard]${C_RESET} $*"; }
warn() { echo -e "${C_YELLOW}[WARN]${C_RESET} $*" >&2; }
die() { echo -e "${C_RED}[ERROR]${C_RESET} $*" >&2; exit 1; }

CURRENT_STAGE="选择安装方式"

stage() {
  CURRENT_STAGE="$*"
  info "[$CURRENT_STAGE]" >&2
}

installation_guide() {
  cat <<'EOF'
DBoard 安装前引导（只读，不会安装或修改服务）
  1 独立版：Ubuntu/Debian + systemd；完整探针对接优先按此路径。
  2 Docker：面板容器；默认发布 7001 到全部接口，需配置 HTTPS/端口边界。
  3 仅 DUI：首次安装或重新配置网关；已有 DUI 升级使用 gateway/install.sh upgrade。
  4 状态：只读查看面板/DUI；探针与实际代理连接另行验收。
  0 退出。

新装默认 SQLite + 内置/本机 Redis，只会继续询问管理员邮箱。
选择已有 MySQL/PostgreSQL：启动时加 --database interactive。
--yes 采用确认项默认值；不会自动完成外部数据库问题，新装需 --admin。
更新应使用原数据目录，已有系统先备份；切换模式会停原服务。
根安装器不部署 HTTPS、独立用户端、探针后台、Connector 或节点 Agent。

逐项选择、默认值、故障处理：
https://github.com/shini74744/DBoard/blob/main/docs/installation-choices.md
完整系统安装：
https://github.com/shini74744/DBoard/blob/main/docs/installation.md
EOF
}

show_plan() {
  info "安装模式: $MODE；持久数据目录: $DATA_DIR"
  if is_installed; then
    info "已识别 INSTALLED 标记：更新现有面板，保留管理员与业务数据。"
    warn "更新前完成备份；模式切换会停止原服务。脚本不提供完整自动回滚。"
  else
    info "未识别到已安装标记：将进入新装初始化。"
    warn "若这里原有业务，请先核对 --data-dir 和 .env；不要以清空数据库解决问题。"
  fi
  if [[ "$DB_MODE" == "sqlite" ]]; then
    info "数据库默认 SQLite，不会出现数据库类型选择；需自选数据库请用 --database interactive。"
  else
    info "将进入数据库选择；MySQL/PostgreSQL 须已准备好服务、账号与 PHP 驱动。"
  fi
  info "Redis 使用安装器预设；外部 Redis 请按手工部署指南配置。"
}

next_steps() {
  cat <<'EOF'

面板本机安装步骤已完成，接下来：
  1. 保存管理员凭据和实际后台路径，配置 HTTPS 反向代理并登录。
  2. 部署独立用户端，核对直连 API 或 DUI 配置。
  3. 需要整合探针时，继续部署 Dashboard、下载文件与 Connector。
  4. 安装第一台 Agent，确认监控、节点通道及实际代理业务。
  5. 配置备份与证书续期。
本机 HTTP 成功不等于整套系统已验收。
逐步指南：https://github.com/shini74744/DBoard/blob/main/docs/installation.md
EOF
}

installation_failed() {
  local code="$1" line="$2"
  trap - ERR
  warn "阶段 [$CURRENT_STAGE] 失败，退出码 $code，脚本行 $line。"
  warn "请保留并脱敏上方错误；先查服务/端口/数据目录，不要清空 .env 或业务数据库。"
  warn "中途可能已停服务或切换 current；恢复步骤见 docs/operations.md。"
  exit "$code"
}

usage() {
  cat <<'EOF'
DBoard 安装器

用法:
  install.sh [options]

选项:
  --mode native|docker|gateway|status
  --data-dir PATH
  --admin EMAIL
  --database sqlite|interactive
  --with-gateway
  --no-gateway
  --gateway-backend URL
  --gateway-port PORT
  --yes                         采用确认项默认值；新装需 --admin
  --guide                       只读安装选择引导，无需 root
  -h, --help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --guide) installation_guide; exit 0 ;;
    --mode) MODE="$2"; shift 2 ;;
    --data-dir) DATA_DIR="$2"; shift 2 ;;
    --admin) ADMIN_ACCOUNT="$2"; shift 2 ;;
    --database) DB_MODE="$2"; shift 2 ;;
    --with-gateway) WITH_GATEWAY="yes"; shift ;;
    --no-gateway) WITH_GATEWAY="no"; shift ;;
    --gateway-backend) GATEWAY_BACKEND="$2"; shift 2 ;;
    --gateway-port) GATEWAY_PORT="$2"; shift 2 ;;
    --yes|-y) ASSUME_YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "未知参数: $1" ;;
  esac
done

[[ $EUID -eq 0 ]] || die "请使用 root 运行，或使用 sudo。"
[[ "$DATA_DIR" = /* ]] || die "--data-dir 必须是绝对路径。"
[[ "$DATA_DIR" =~ [[:space:]] ]] &&
  die "--data-dir 当前不支持包含空格。"

confirm() {
  local prompt="$1" default="${2:-no}" answer
  if [[ "$ASSUME_YES" -eq 1 ]]; then
    [[ "$default" == "yes" ]] && return 0
    return 1
  fi
  if [[ "$default" == "yes" ]]; then
    read -r -p "$prompt [Y/n]: " answer
    [[ -z "$answer" || "$answer" =~ ^[Yy]$ ]]
  else
    read -r -p "$prompt [y/N]: " answer
    [[ "$answer" =~ ^[Yy]$ ]]
  fi
}

prompt_default() {
  local prompt="$1" default="$2" value
  read -r -p "$prompt [$default]: " value
  printf '%s' "${value:-$default}"
}
select_mode() {
  [[ -n "$MODE" ]] && return
  cat <<'EOF'

DBoard 安装管理
────────────────────────────
1. 安装/更新 独立版（完整探针对接推荐；Ubuntu/Debian + systemd）
2. 安装/更新 Docker 版（面板容器；需确认反代与 Connector 网络）
3. 仅安装 DUI-Gateway（首次安装/重配；已有网关升级请用 upgrade）
4. 查看 DBoard 状态（只读，不代表探针/业务验收）
0. 退出
────────────────────────────
EOF
  local choice
  info "默认新装使用 SQLite；选其它数据库请 Ctrl+C 后加 --database interactive 重启。"
  info "已有业务先备份，模式切换会停止原服务；完整系统还需部署用户端与探针。"
  read -r -p "请选择 [0-4]: " choice
  case "$choice" in
    1) MODE="native" ;;
    2) MODE="docker" ;;
    3) MODE="gateway" ;;
    4) MODE="status" ;;
    0) exit 0 ;;
    *) die "无效选择。" ;;
  esac
}

is_installed() {
  [[ -s "$DATA_DIR/.env" ]] &&
    grep -Eq '^INSTALLED=(1|true)$' "$DATA_DIR/.env"
}

set_env_value() {
  local key="$1" value="$2" file="$DATA_DIR/.env"
  if grep -q "^$key=" "$file" 2>/dev/null; then
    sed -i "s|^$key=.*|$key=$value|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

configure_native_env() {
  set_env_value REDIS_HOST 127.0.0.1
  set_env_value REDIS_PORT 6379
  set_env_value REDIS_PASSWORD null
}

configure_docker_env() {
  set_env_value REDIS_HOST /data/redis.sock
  set_env_value REDIS_PORT 0
  set_env_value REDIS_PASSWORD null
}

init_shared() {
  info "初始化持久数据目录: $DATA_DIR"
  install -d -m 0750 "$DATA_DIR"
  local dir
  for dir in data redis plugins storage/app storage/backup storage/logs storage/theme public-theme; do
    install -d -m 0750 "$DATA_DIR/$dir"
  done
  [[ -e "$DATA_DIR/.env" ]] || install -m 0640 /dev/null "$DATA_DIR/.env"
}

detect_os() {
  [[ -r /etc/os-release ]] || die "无法识别操作系统。"
  . /etc/os-release
  case "${ID:-}" in
    ubuntu|debian) ;;
    *) die "独立版自动安装当前支持 Ubuntu / Debian。检测到: ${ID:-unknown}" ;;
  esac
  command -v systemctl >/dev/null || die "独立版需要 systemd。"
}

apt_install_native_deps() {
  export DEBIAN_FRONTEND=noninteractive
  info "安装 PHP / Composer / 编译依赖..."
  apt-get update
  apt-get install -y \
    ca-certificates curl git rsync unzip sudo build-essential pkg-config \
    libssl-dev libbrotli-dev \
    php-cli php-common php-bcmath php-curl php-mbstring php-mysql \
    php-opcache php-readline php-redis php-sqlite3 php-xml php-zip \
    php-dev php-pear composer
}
install_swoole() {
  local current=""
  current="$(php -r 'echo phpversion("swoole") ?: "";' 2>/dev/null || true)"
  if [[ "$current" == "$SWOOLE_VERSION" ]]; then
    info "Swoole $SWOOLE_VERSION 已安装。"
    return
  fi

  info "编译安装 Swoole $SWOOLE_VERSION ..."
  local work="/usr/local/src/dboard-swoole-$SWOOLE_VERSION"
  rm -rf "$work"
  mkdir -p "$work"
  cd "$work"

  pecl download "swoole-$SWOOLE_VERSION"
  tar -xzf "swoole-$SWOOLE_VERSION.tgz"
  cd "swoole-$SWOOLE_VERSION"

  phpize
  ./configure     --enable-swoole     --enable-sockets     --enable-mysqlnd     --with-openssl-dir=/usr     --enable-brotli

  local jobs
  jobs="$(nproc 2>/dev/null || echo 1)"
  (( jobs > 2 )) && jobs=2
  make -j"$jobs"
  make install

  local php_minor ini_dir
  php_minor="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  ini_dir="/etc/php/$php_minor/mods-available"
  mkdir -p "$ini_dir"
  echo 'extension=swoole.so' > "$ini_dir/swoole.ini"

  if command -v phpenmod >/dev/null 2>&1; then
    phpenmod -v "$php_minor" -s cli swoole
  else
    mkdir -p "/etc/php/$php_minor/cli/conf.d"
    ln -sfn "$ini_dir/swoole.ini"       "/etc/php/$php_minor/cli/conf.d/20-swoole.ini"
  fi

  php -m | grep -qx swoole || die "Swoole 安装后未能加载。"
  info "Swoole $(php -r 'echo phpversion("swoole");') 安装完成。"
}
ensure_redis_user() {
  if ! id redis >/dev/null 2>&1; then
    useradd --system --home /nonexistent       --shell /usr/sbin/nologin redis
  fi
}

install_redis_runtime() {
  local prefix="$DBOARD_ROOT/runtime/redis-$REDIS_VERSION"
  if [[ -x "$prefix/bin/redis-server" ]] &&
     "$prefix/bin/redis-server" --version |
       grep -q "v=$REDIS_VERSION"; then
    info "Redis $REDIS_VERSION runtime 已安装。"
    return
  fi

  info "编译安装 Redis $REDIS_VERSION ..."
  local src="/usr/local/src/redis-$REDIS_VERSION"
  local archive="/usr/local/src/redis-$REDIS_VERSION.tar.gz"

  mkdir -p /usr/local/src "$DBOARD_ROOT/runtime"
  rm -rf "$src" "$archive"

  curl -fL --retry 3     "https://github.com/redis/redis/archive/refs/tags/$REDIS_VERSION.tar.gz"     -o "$archive"
  tar -C /usr/local/src -xzf "$archive"

  cd "$src"
  local jobs
  jobs="$(nproc 2>/dev/null || echo 1)"
  (( jobs > 2 )) && jobs=2

  make -j"$jobs" MALLOC=libc
  make PREFIX="$prefix" MALLOC=libc install

  "$prefix/bin/redis-server" --version |
    grep -q "v=$REDIS_VERSION" ||
    die "Redis runtime 版本校验失败。"
}
ensure_www_user() {
  if ! id www-data >/dev/null 2>&1; then
    useradd --system --home /var/www       --shell /usr/sbin/nologin www-data
  fi
}

fetch_release() {
  local stamp src release app commit
  stamp="$(date +%Y%m%d-%H%M%S)"
  src="/tmp/dboard-source-$$"
  release="$DBOARD_ROOT/releases/$stamp"
  app="$release/app"

  rm -rf "$src"
  info "获取 DBoard main 源码..." >&2
  git clone --depth 1 --branch main "$REPO_URL" "$src" >&2
  commit="$(git -C "$src" rev-parse HEAD)"

  mkdir -p "$app"
  rsync -a --delete "$src/panel/" "$app/"
  printf '%s
' "$commit" > "$release/COMMIT"

  info "安装 Composer 生产依赖..." >&2
  (
    cd "$app"
    COMPOSER_ALLOW_SUPERUSER=1 composer install       --no-dev --prefer-dist --no-interaction       --optimize-autoloader
  ) >&2

  rm -rf "$src"
  printf '%s' "$app"
}

link_native_shared() {
  local app="$1"
  mkdir -p "$app/.docker" "$app/storage" "$app/public"

  rm -rf     "$app/.env" "$app/.docker/.data" "$app/plugins"     "$app/storage/logs" "$app/storage/theme"     "$app/storage/app" "$app/storage/backup"     "$app/public/theme"

  ln -s "$DATA_DIR/.env" "$app/.env"
  ln -s "$DATA_DIR/data" "$app/.docker/.data"
  ln -s "$DATA_DIR/plugins" "$app/plugins"
  ln -s "$DATA_DIR/storage/logs" "$app/storage/logs"
  ln -s "$DATA_DIR/storage/theme" "$app/storage/theme"
  ln -s "$DATA_DIR/storage/app" "$app/storage/app"
  ln -s "$DATA_DIR/storage/backup" "$app/storage/backup"
  ln -s "$DATA_DIR/public-theme" "$app/public/theme"

  mkdir -p     "$app/storage/framework/cache/data"     "$app/storage/framework/sessions"     "$app/storage/framework/views"     "$app/storage/tmp" "$app/bootstrap/cache"     "$DATA_DIR/home"

  ln -sfn "$app" "$DBOARD_ROOT/current"
}
prepare_native_permissions() {
  ensure_www_user
  ensure_redis_user

  chown www-data:www-data "$DATA_DIR/.env"
  chmod 600 "$DATA_DIR/.env"
  chown -R www-data:www-data     "$DATA_DIR/data" "$DATA_DIR/plugins"     "$DATA_DIR/storage" "$DATA_DIR/public-theme"     "$DATA_DIR/home"
  chown -R redis:redis "$DATA_DIR/redis"

  local app="$DBOARD_ROOT/current"
  chown -R www-data:www-data     "$app/storage/framework" "$app/storage/tmp"     "$app/bootstrap/cache"
}

write_redis_service() {
  local prefix="$DBOARD_ROOT/runtime/redis-$REDIS_VERSION"
  mkdir -p /etc/dboard

  cat > /etc/dboard/redis.conf <<EOF
bind 127.0.0.1
protected-mode yes
port 6379
daemonize no
supervised no
dir $DATA_DIR/redis
dbfilename dump.rdb
appendonly no
save 900 1
save 300 10
save 60 10000
logfile ""
EOF

  cat > /etc/systemd/system/dboard-redis.service <<EOF
[Unit]
Description=DBoard Redis $REDIS_VERSION
After=network.target

[Service]
Type=simple
User=redis
Group=redis
ExecStart=$prefix/bin/redis-server /etc/dboard/redis.conf
ExecStop=$prefix/bin/redis-cli -p 6379 shutdown
Restart=always
RestartSec=2
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
EOF
}
write_octane_service() {
  local php_bin
  php_bin="$(command -v php)"
  cat > /etc/systemd/system/dboard-octane.service <<EOF
[Unit]
Description=DBoard Octane
After=network.target dboard-redis.service
Requires=dboard-redis.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$DBOARD_ROOT/current
Environment=HOME=$DATA_DIR/home
ExecStart=$php_bin artisan octane:start --server=swoole --host=127.0.0.1 --port=7001 --workers=2 --task-workers=1 --max-requests=500
ExecReload=$php_bin artisan octane:reload
Restart=always
RestartSec=2
KillMode=mixed
TimeoutStopSec=20

[Install]
WantedBy=multi-user.target
EOF
}

write_horizon_service() {
  local php_bin
  php_bin="$(command -v php)"
  cat > /etc/systemd/system/dboard-horizon.service <<EOF
[Unit]
Description=DBoard Horizon
After=network.target dboard-redis.service
Requires=dboard-redis.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$DBOARD_ROOT/current
Environment=HOME=$DATA_DIR/home
ExecStart=$php_bin artisan horizon
ExecStop=$php_bin artisan horizon:terminate
Restart=always
RestartSec=2
KillMode=mixed
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
EOF
}
write_ws_service() {
  local php_bin
  php_bin="$(command -v php)"
  cat > /etc/systemd/system/dboard-ws.service <<EOF
[Unit]
Description=DBoard WebSocket
After=network.target dboard-redis.service
Requires=dboard-redis.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$DBOARD_ROOT/current
Environment=HOME=$DATA_DIR/home
ExecStart=$php_bin artisan ws-server start --host=127.0.0.1 --port=8076
Restart=always
RestartSec=2
KillMode=mixed
TimeoutStopSec=15

[Install]
WantedBy=multi-user.target
EOF
}

write_scheduler_service() {
  local php_bin
  php_bin="$(command -v php)"
  cat > /etc/systemd/system/dboard-scheduler.service <<EOF
[Unit]
Description=DBoard Scheduler
After=network.target dboard-redis.service
Requires=dboard-redis.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$DBOARD_ROOT/current
Environment=HOME=$DATA_DIR/home
ExecStart=$php_bin artisan schedule:work
Restart=always
RestartSec=2
KillMode=mixed
TimeoutStopSec=15

[Install]
WantedBy=multi-user.target
EOF
}

write_native_services() {
  write_redis_service
  write_octane_service
  write_horizon_service
  write_ws_service
  write_scheduler_service
  systemctl daemon-reload
}
stop_docker_if_needed() {
  if [[ -f "$DOCKER_DIR/compose.yaml" ]] &&
     command -v docker >/dev/null 2>&1; then
    if docker compose -f "$DOCKER_DIR/compose.yaml" ps -q 2>/dev/null |
       grep -q .; then
      warn "检测到 Docker 版正在运行。"
      confirm "切换到独立版并停止 Docker 版？" yes ||
        die "已取消切换。"
      docker compose -f "$DOCKER_DIR/compose.yaml" down
    fi
  fi
}

stop_native_if_needed() {
  local native_present=0
  systemctl is-active --quiet dboard-octane.service 2>/dev/null &&
    native_present=1
  systemctl is-enabled --quiet dboard-octane.service 2>/dev/null &&
    native_present=1

  if [[ "$native_present" -eq 1 ]]; then
    warn "检测到独立版服务。"
    confirm "切换到 Docker 版并停用独立版？" yes ||
      die "已取消切换。"
    systemctl disable --now       dboard-octane.service dboard-horizon.service       dboard-ws.service dboard-scheduler.service       dboard-redis.service || true
  fi
}

check_native_redis_port() {
  if systemctl is-active --quiet dboard-redis.service 2>/dev/null; then
    return
  fi
  if ss -ltn 2>/dev/null | grep -qE '[:.]6379[[:space:]]'; then
    die "127.0.0.1:6379 已被其它服务占用，请先处理端口冲突。"
  fi
}

native_initialize_or_update() {
  local installed_before="$1"
  cd "$DBOARD_ROOT/current"

  if [[ "$installed_before" == "yes" ]]; then
    info "检测到已有生产数据：只执行安全更新，不重新初始化。"
    sudo -u www-data env HOME="$DATA_DIR/home"       php artisan xboard:update --no-interaction
    return
  fi

  [[ "$DB_MODE" == "sqlite" || "$DB_MODE" == "interactive" ]] ||
    die "--database 仅支持 sqlite 或 interactive。"

  if [[ "$ASSUME_YES" -eq 1 && -z "$ADMIN_ACCOUNT" ]]; then
    die "非交互新装请同时提供 --admin EMAIL。"
  fi

  info "开始初始化 DBoard..."
  local -a env_args=(
    "HOME=$DATA_DIR/home"
    "ENABLE_REDIS=true"
    "REDIS_HOST=127.0.0.1"
    "REDIS_PORT=6379"
  )
  [[ "$DB_MODE" == "sqlite" ]] &&
    env_args+=("ENABLE_SQLITE=true")
  [[ -n "$ADMIN_ACCOUNT" ]] &&
    env_args+=("ADMIN_ACCOUNT=$ADMIN_ACCOUNT")

  sudo -u www-data env "${env_args[@]}"     php artisan xboard:install

  is_installed || die "面板初始化未完成。"
}
wait_http() {
  local url="$1"
  for _ in {1..45}; do
    if curl -fsS --max-time 3 "$url" >/dev/null 2>&1; then
      info "HTTP 健康检查通过: $url"
      return 0
    fi
    sleep 1
  done
  die "HTTP 健康检查失败: $url"
}

print_native_proxy_hint() {
  cat <<'EOF'

反向代理建议：
  HTTP 上游: 127.0.0.1:7001
  WebSocket:  location = /ws -> 127.0.0.1:8076

生产环境建议只公开 80/443。
不要将 Redis 6379 暴露到公网。
EOF
}

quiesce_native_apps() {
  systemctl stop     dboard-octane.service dboard-horizon.service     dboard-ws.service dboard-scheduler.service     2>/dev/null || true
}

install_native() {
  stage "独立版 1/6：数据目录与部署模式"
  detect_os
  show_plan
  init_shared

  local installed_before="no"
  is_installed && installed_before="yes"

  stop_docker_if_needed
  stage "独立版 2/6：安装 PHP、Swoole、Redis 依赖"
  apt_install_native_deps
  install_swoole
  install_redis_runtime

  stage "独立版 3/6：暂停旧应用并准备发布目录"
  quiesce_native_apps

  local app
  app="$(fetch_release)"
  link_native_shared "$app"
  prepare_native_permissions
  write_native_services

  check_native_redis_port
  systemctl enable --now dboard-redis.service

  [[ "$installed_before" == "yes" ]] && configure_native_env
  stage "独立版 4/6：初始化或更新数据库"
  native_initialize_or_update "$installed_before"

  stage "独立版 5/6：启动服务与本机 HTTP 检查"
  systemctl enable --now     dboard-octane.service     dboard-horizon.service     dboard-ws.service     dboard-scheduler.service

  wait_http "http://127.0.0.1:7001/api/v1/guest/comm/config"
  info "独立版安装/更新完成。"
  stage "独立版 6/6：准备 HTTPS 与后续组件"
  print_native_proxy_hint
}
install_basic_tools() {
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    apt-get install -y ca-certificates curl git
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y ca-certificates curl git
  elif command -v yum >/dev/null 2>&1; then
    yum install -y ca-certificates curl git
  else
    die "无法识别包管理器，请先手动安装 curl、git 与 Docker。"
  fi
}

ensure_docker() {
  if command -v docker >/dev/null 2>&1 &&
     docker compose version >/dev/null 2>&1; then
    info "Docker / Compose 已安装。"
    return
  fi

  install_basic_tools
  info "安装 Docker Engine 与 Compose..."
  local script="/tmp/get-docker-$$.sh"
  curl -fsSL https://get.docker.com -o "$script"
  sh "$script"
  rm -f "$script"

  if command -v systemctl >/dev/null 2>&1; then
    systemctl enable --now docker
  fi

  docker compose version >/dev/null 2>&1 ||
    die "Docker Compose V2 安装失败。"
}

prepare_docker_compose() {
  mkdir -p "$DOCKER_DIR"
  curl -fsSL "$RAW_BASE/panel/compose.sample.yaml"     -o "$DOCKER_DIR/compose.yaml"

  cat > "$DOCKER_DIR/.env" <<EOF
DBOARD_DATA_DIR=$DATA_DIR
EOF
}

ensure_docker_image() {
  if docker pull "$IMAGE"; then
    return
  fi

  warn "无法拉取 GHCR 镜像，回退为从 GitHub 源码本机构建。"
  local src="/tmp/dboard-docker-source-$$"
  rm -rf "$src"
  git clone --depth 1 --branch main "$REPO_URL" "$src"
  docker build -t "$IMAGE" "$src/panel"
  rm -rf "$src"
}
docker_initialize() {
  local installed_before="$1"
  cd "$DOCKER_DIR"

  if [[ "$installed_before" == "yes" ]]; then
    info "检测到已有生产数据，不重新初始化数据库。"
    return
  fi

  if [[ "$ASSUME_YES" -eq 1 && -z "$ADMIN_ACCOUNT" ]]; then
    die "非交互新装请同时提供 --admin EMAIL。"
  fi

  local -a args=(run --rm -e ENABLE_REDIS=true)
  if [[ "$DB_MODE" == "sqlite" ]]; then
    args+=(-e ENABLE_SQLITE=true)
  elif [[ "$DB_MODE" != "interactive" ]]; then
    die "--database 仅支持 sqlite 或 interactive。"
  fi

  [[ -n "$ADMIN_ACCOUNT" ]] &&
    args+=(-e "ADMIN_ACCOUNT=$ADMIN_ACCOUNT")

  info "初始化 Docker 版 DBoard..."
  docker compose "${args[@]}" dboard     php artisan xboard:install

  is_installed || die "Docker 面板初始化未完成。"
}

print_docker_proxy_hint() {
  cat <<'EOF'

Docker 版默认入口：
  本机 HTTP + WebSocket 检查地址: 127.0.0.1:7001
  默认 Compose 为 7001:7001，实际向宿主机全部接口发布。
  仅宿主反代时可改为 127.0.0.1:7001:7001；下次安装器会重写 Compose。

容器内 Caddy 已自动分流 /ws。
生产环境建议由宿主机 Nginx/OpenResty/Caddy 提供 HTTPS，
然后反代到 127.0.0.1:7001。
EOF
}

install_docker() {
  stage "Docker 1/5：数据目录与部署模式"
  show_plan
  init_shared
  local installed_before="no"
  is_installed && installed_before="yes"

  stop_native_if_needed
  stage "Docker 2/5：检查 Docker Engine 与 Compose"
  ensure_docker
  stage "Docker 3/5：默认 Compose 与镜像"
  warn "此步骤会重写 /opt/dboard/docker/compose.yaml 和 .env；请先保留定制配置。"
  prepare_docker_compose
  ensure_docker_image
  [[ "$installed_before" == "yes" ]] && configure_docker_env
  stage "Docker 4/5：初始化或复用现有数据"
  docker_initialize "$installed_before"

  cd "$DOCKER_DIR"
  stage "Docker 5/5：启动容器与本机 HTTP 检查"
  docker compose up -d

  wait_http "http://127.0.0.1:7001/api/v1/guest/comm/config"
  info "Docker 版安装/更新完成。"
  warn "请继续检查 docker compose logs 与 migrate:status；容器启动不保证迁移成功。"
  info "Compose 文件: $DOCKER_DIR/compose.yaml"
  print_docker_proxy_hint
}
install_gateway() {
  stage "DUI 1/3：确认网关后端与配置范围"
  warn "此入口执行 install，会重写 gateway.env 并生成 AES key；已有网关普通升级请使用 gateway/install.sh upgrade。"
  local backend="$GATEWAY_BACKEND"

  if [[ -z "$backend" ]]; then
    if [[ "$ASSUME_YES" -eq 1 ]]; then
      backend="http://127.0.0.1:7001"
    else
      backend="$(prompt_default         "DUI-Gateway 后端地址"         "http://127.0.0.1:7001")"
    fi
  fi

  [[ "$GATEWAY_PORT" =~ ^[0-9]+$ ]] ||
    die "Gateway 端口无效: $GATEWAY_PORT"

  stage "DUI 2/3：下载并运行网关安装器"
  local script="/tmp/dui-gateway-install-$$.sh"
  info "安装 DUI-Gateway..."
  curl -fsSL "$RAW_BASE/gateway/install.sh" -o "$script"
  bash "$script" install     --backend "$backend"     --port "$GATEWAY_PORT"
  rm -f "$script"

  stage "DUI 3/3：HTTPS 与前端配置"
  cat <<EOF

DUI-Gateway 已安装。
默认监听所有 IPv4 接口，端口: $GATEWAY_PORT
请限制公网直连；将安装输出的 AES key 配套填写到用户端。
请使用 HTTPS 域名反向代理到 127.0.0.1:$GATEWAY_PORT。
EOF
}

maybe_install_gateway() {
  if [[ "$WITH_GATEWAY" == "yes" ]]; then
    install_gateway
    return
  fi

  if [[ "$WITH_GATEWAY" == "no" ]]; then
    return
  fi

  info "DUI 是可选用户 API 层；已有正常网关选 N，普通升级使用 gateway/install.sh upgrade。"
  if confirm "是否首次安装或重新配置 DUI-Gateway（会写入配置/AES key）？" no; then
    install_gateway
  fi
}
show_status() {
  echo "DBoard persistent data: $DATA_DIR"
  if is_installed; then
    echo "data: installed"
  else
    echo "data: not initialized"
  fi

  local current_app release_dir
  current_app="$(readlink -f "$DBOARD_ROOT/current" 2>/dev/null || true)"
  if [[ -n "$current_app" ]]; then
    release_dir="$(dirname "$current_app")"
    echo "native current: $current_app"
    [[ -f "$release_dir/COMMIT" ]] &&
      echo "native commit: $(cat "$release_dir/COMMIT")"
  fi

  echo
  echo "Native services:"
  local service enabled active
  if command -v systemctl >/dev/null 2>&1; then
    for service in dboard-redis dboard-octane dboard-horizon dboard-ws dboard-scheduler; do
      enabled="$(systemctl is-enabled "$service.service" 2>/dev/null || true)"
      active="$(systemctl is-active "$service.service" 2>/dev/null || true)"
      [[ -n "$enabled" ]] || enabled="n/a"
      [[ -n "$active" ]] || active="inactive"
      printf '  %-18s enabled=%-8s active=%s\n'         "$service" "$enabled" "$active"
    done
  else
    echo "  systemd unavailable"
  fi

  echo
  echo "Docker:"
  if command -v docker >/dev/null 2>&1 &&
     [[ -f "$DOCKER_DIR/compose.yaml" ]]; then
    docker compose -f "$DOCKER_DIR/compose.yaml" ps || true
  else
    echo "  not configured"
  fi

  echo
  echo "DUI-Gateway:"
  if command -v systemctl >/dev/null 2>&1; then
    enabled="$(systemctl is-enabled DUI-Gateway.service 2>/dev/null || true)"
    active="$(systemctl is-active DUI-Gateway.service 2>/dev/null || true)"
    [[ -n "$enabled" ]] || enabled="n/a"
    [[ -n "$active" ]] || active="inactive"
    printf '  enabled=%s active=%s\n' "$enabled" "$active"
  else
    echo "  systemd unavailable"
  fi
}

trap 'installation_failed "$?" "$LINENO"' ERR
select_mode

case "$MODE" in
  native)
    install_native
    maybe_install_gateway
    next_steps
    ;;
  docker)
    install_docker
    maybe_install_gateway
    next_steps
    ;;
  gateway)
    install_gateway
    ;;
  status)
    show_status
    ;;
  *)
    die "无效模式: $MODE"
    ;;
esac

info "操作完成。"
