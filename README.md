# DBoard

DBoard 是基于 XBoard 持续二次开发的面板项目，配套用户端、**DBoard-node**、**整合 Nezha 探针/Agent** 与可选的 **DUI-Gateway** API 加密中间层。

本仓库提供两种正式部署方式：

- **独立版（Native）**：PHP / Swoole / Redis / systemd 直接运行在宿主机。
- **Docker 版**：使用预构建 DBoard 镜像运行，适合快速部署与标准化交付。

两种方式共用同一套持久数据结构，因此可以在独立版与 Docker 版之间迁移。

## 文档导航（2026-09-25 更新）

| 内容 | 阅读入口 |
| --- | --- |
| 用户注册、购买、新开/续费、订单与订阅 | [用户端使用指南](docs/user-guide.md) |
| 管理后台操作、认证、配置下发、计费处理 | [后台处理流程](docs/admin-workflows.md) |
| 面板、用户端、DUI、探针、Connector、Agent 逐步安装 | [完整安装指南](docs/installation.md) |
| 升级、域名迁移、备份恢复与排错 | [运维指南](docs/operations.md) |
| 全部文档与实现入口 | [文档索引](docs/README.md) |

**版本提示：** main 已包含探针后台入口限制、删除/排序同步与显示 ID。整合 Agent 已发布 v0.2.1，但该 Release 原有 Dashboard 附件早于这些服务端修复；新安装请按安装指南构建当前 Dashboard。源码推送不会自动更新 Release 附件。

## 项目结构

```text
DBoard/
├── panel/        # DBoard 面板、Dockerfile、Compose 示例
├── node/         # DBoard-node、xbctl、Xray / sing-box 双内核
├── frontend/     # 用户商店、订单、套餐与订阅界面
├── probe/        # Nezha Dashboard、桥接、Connector、整合 Agent 安装器
├── docs/         # 用户、后台、安装与运维指南
├── gateway/      # DUI-Gateway 加密 API 中间层
├── deploy/       # 部署与持久数据说明
├── scripts/      # 构建、发布、数据目录初始化工具
└── install.sh    # 交互式总安装器
```

## 推荐安装方式

根安装器负责面板和可选 DUI；不会自动安装整套探针。需要服务器通过探针管理时，请继续完成[完整安装指南](docs/installation.md)中的探针与 Connector 步骤。

新机器推荐直接执行交互式安装器：

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/install.sh)
```

安装器会引导选择：

```text
1. 独立版安装
2. Docker 版安装
3. 是否安装 DUI-Gateway
4. 面板初始化
5. 服务启动与状态检查
```

> 安装脚本不会把数据库、密钥或运行日志提交到 GitHub。

## 独立版还是 Docker 版

| 项目 | 独立版 | Docker 版 |
|---|---|---|
| 推荐场景 | 高频二开、调试、生产运维 | 快速部署、标准化交付 |
| PHP/Swoole | 宿主机安装 | 镜像内置 |
| Redis | 宿主机 Redis 8.4.2 | 镜像内置 Redis 8.4.2 |
| 进程管理 | systemd | Supervisor |
| 反向代理 | 1Panel/OpenResty/Nginx/Caddy | 容器内 Caddy + 可选宿主反代 |
| 持久数据 | `/opt/dboard/shared` | `/opt/dboard/shared` |
| 升级代码 | release + current 软链接 | 拉取新镜像 |
| 排错 | 最直接 | 多一层容器 |
当前持续开发和生产调试阶段更推荐 **独立版**；需要给第三方快速部署时推荐 **Docker 版**。

---

# 面板持久数据目录

无论使用哪种部署方式，DBoard 都把：

```text
/opt/dboard/shared
```

定义为面板唯一持久数据目录。探针、独立用户端和 DUI 还有各自配置与身份数据，整套系统的备份边界见[运维指南](docs/operations.md)。

标准结构：

```text
/opt/dboard/shared/
├── .env
├── data/
│   └── database.sqlite
├── redis/
│   └── dump.rdb
├── plugins/
├── storage/
│   ├── app/
│   ├── backup/
│   ├── logs/
│   └── theme/
└── public-theme/
```
其中：

- `data/database.sqlite`：用户、订单、套餐、节点、机器、设置、插件记录、路由、出站、负载均衡等永久业务数据。
- `redis/dump.rdb`：Redis 队列、缓存和部分运行状态。
- `.env`：生产配置与密钥。
- `plugins/`：安装的插件文件。
- `storage/app/`：插件及应用运行数据。
- `public-theme/`、`storage/theme/`：主题数据。
- `storage/logs/`：日志，可保留用于排错。

以下内容**不属于持久数据**，可以重新生成：

```text
vendor/
storage/framework/
storage/tmp/
Octane / WorkerMan PID 文件
编译视图与普通缓存
```

详细说明见 [deploy/PERSISTENT_DATA.md](deploy/PERSISTENT_DATA.md)。

---

# 独立版部署
## 推荐环境

自动安装优先支持并测试：

- Ubuntu 24.04 LTS x86_64 / arm64
- root 权限
- systemd

核心运行环境：

| 组件 | 要求 |
|---|---|
| PHP | 8.2+，推荐 8.3 |
| Swoole | 6.2.x |
| Redis | **8.4.2** |
| Composer | 2.x |
| SQLite | 默认数据库 |
| Web Server | OpenResty / Nginx / Caddy 均可 |

PHP 至少需要：

```text
bcmath curl mbstring mysql opcache pcntl redis
sqlite3 xml zip openssl sodium fileinfo
```

> Redis 版本不要随意降级。Redis 8.4.2 生成的 RDB 可能无法被 Redis 7.x 读取。
## 独立版运行结构

```text
OpenResty / Nginx / Caddy
          │
          ├── DBoard Octane       127.0.0.1:7001
          └── WebSocket /ws       127.0.0.1:8076

DBoard
├── dboard-octane.service
├── dboard-horizon.service
├── dboard-ws.service
├── dboard-scheduler.service
└── dboard-redis.service          127.0.0.1:6379
```

程序采用 release 目录：

```text
/opt/dboard/releases/<version-or-time>/app
/opt/dboard/current -> 当前 release
/opt/dboard/shared  -> 唯一持久数据
```

因此升级程序和业务数据彼此分离。

## 常用状态检查

```bash
systemctl status dboard-octane
systemctl status dboard-horizon
systemctl status dboard-ws
systemctl status dboard-scheduler
systemctl status dboard-redis
```
查看日志：

```bash
journalctl -u dboard-octane -f
journalctl -u dboard-horizon -f
journalctl -u dboard-ws -f
journalctl -u dboard-scheduler -f
```

---

# Docker 版部署

## Docker 环境

宿主机只需要：

- Linux x86_64 / arm64
- Docker Engine
- Docker Compose V2
- 至少开放面板入口端口（默认 7001）

官方项目镜像：

```text
ghcr.io/shini74744/dboard:latest
```

镜像直接从本仓库 `panel/` 源码构建，不再下载或替换成 cedar2025/Xboard 源码。

镜像包含：

```text
PHP 8.3
Swoole 6.2.x
Redis 8.4.2
Octane
Horizon
WorkerMan
Scheduler
Caddy
```
## Docker Compose

默认单容器部署示例：

```text
panel/compose.sample.yaml
```

其它示例：

- `compose.host.sample.yaml`：host network。
- `compose.1panel.sample.yaml`：接入 1Panel Docker network。
- `compose.split.sample.yaml`：Web / Horizon / WS / Scheduler / Redis 拆分部署。

所有 Compose 示例默认绑定：

```text
DBOARD_DATA_DIR=/opt/dboard/shared
```

可以通过环境变量改变位置，但生产环境建议保持默认路径。

本地构建镜像：

```bash
./scripts/build-docker.sh dboard:local
```

GitHub Actions 会从 `panel/Dockerfile` 构建 amd64 / arm64 镜像并发布到 GHCR。
---

# 面板初始化

底层初始化命令仍保持 XBoard 兼容命令名：

```bash
php artisan xboard:install
```

这是为了兼容现有升级逻辑和上游生态，并不代表运行的是上游 XBoard。

初始化过程可选择：

- SQLite（推荐，最容易备份与迁移）
- MySQL
- PostgreSQL
- Redis
- 管理员账号

使用 SQLite 时，数据库最终位于：

```text
/opt/dboard/shared/data/database.sqlite
```

---

# DUI-Gateway（可选）

DUI-Gateway 用于在前端与真实 DBoard API 之间增加一层加密路径中间件：

```text
浏览器 / 前端
      ↓ HTTPS
DUI-Gateway
      ↓
DBoard 后端
```
它适合需要隐藏真实 API 路径或隐藏真实后端入口的部署，但不是 DBoard 必需组件。

默认监听：

```text
0.0.0.0:3939
```

网关当前监听所有 IPv4 接口；部署时应限制 3939 的公网访问，仅通过 HTTPS 反向代理提供服务。

手动安装示例：

```bash
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/gateway/install.sh | \
  sudo bash -s -- install \
  --backend 'https://backend.example.com'
```

安装器会自动生成 AES key，也可以通过 `--aes-key` 指定。

注意：

- AES 层主要用于隐藏路径，不能替代 HTTPS。
- 前端需要知道 AES key，因此不要把它当作不可泄露的认证密钥。
- Gateway 应继续放在 HTTPS 反向代理之后。

完整说明见 [gateway/README.md](gateway/README.md)。

---

# DBoard-node 与整合 Agent

启用探针接入后，应在服务器管理生成探针安装命令，由单个整合 Agent 运行监控与节点功能。节点的管理通信和安装下载使用探针域名，用户代理流量仍经过节点内核。详见[探针说明](probe/README.md)。

## 传统直连模式（未使用探针对接）

DBoard 的节点程序独立于面板部署。以下 Machine Mode 命令会直接连接面板，不是整合探针安装命令：

```bash
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/node/install.sh | \
  sudo bash -s -- \
  --mode machine \
  --panel https://panel.example.com \
  --token TOKEN \
  --machine-id 1
```

安装器会从 DBoard GitHub Release 下载对应架构的：

```text
DBoard-node
xbctl
```

Node 支持 Xray / sing-box 双内核，并对结构化 Outbound、Route Rule 和 Balancer 做统一转换。

详细说明见 [node/README.md](node/README.md)。

---

# 端口建议

| 端口 | 用途 | 是否建议公网开放 |
|---|---|---|
| 7001 | DBoard HTTP 上游 | 仅需要直连时 |
| 8076 | WebSocket 内部端口 | 否，建议通过 `/ws` 反代 |
| 6379 | Redis | **禁止公网开放** |
| 3939 | DUI-Gateway 默认端口 | 否，限制公网访问并反代 |
| 8008 | 探针 HTTP / gRPC 上游 | 否，通过 TLS 443 反代 |
| 80/443 | Web / TLS | 是 |
管理入口建议公开 80/443；面板和探针上游绑定回环，DUI 的 3939 另行限制。代理业务端口仍按节点配置开放，不能因这一建议全部关闭。

---

# 备份、恢复与迁移

DBoard 面板业务的数据边界为：

```text
/opt/dboard/shared
```

整合部署还须备份探针数据库、身份注册表、控制密钥、Connector、用户端运行配置、DUI 和证书/反代配置，见[完整备份清单](docs/operations.md)。

**不要在 SQLite 和 Redis 正在写入时直接 tar 整个目录**。

正确备份流程：

1. 使用 SQLite online backup 生成一致性数据库副本。
2. 对 Redis 执行 BGSAVE。
3. 等待 RDB 落盘完成。
4. 校验 SQLite `PRAGMA integrity_check`。
5. 使用 `redis-check-rdb` 校验 RDB。
6. 打包 `.env`、数据库、Redis、插件、storage 与主题。
7. 保存 DBoard commit、PHP、Swoole、Redis 版本信息和 SHA256。

恢复新机器时：

```text
安装运行环境
→ 恢复 /opt/dboard/shared
→ 修复文件所有者
→ 启动 DBoard
→ 检查 migration / API / 节点心跳
```

因为独立版与 Docker 版使用同一数据布局，所以可以跨部署方式迁移。
---

# 开发与构建

Panel：

```bash
cd panel
composer install --no-dev --optimize-autoloader
```

管理后台已验证的编译产物位于：

```text
panel/public/assets/admin/
```

Node：

```bash
cd node
make build
go test ./...
```

发布 Node：

```bash
./scripts/release-node.sh v0.1.0
```

发布 Gateway：

```bash
./scripts/release-gateway.sh gateway-v0.1.0
```

Docker 镜像由独立 GitHub Actions workflow 发布，不会改变 DBoard-node 的 latest Release 逻辑。
## 主要定制

- 出站规则管理与 VMess / VLESS / Trojan / Shadowsocks 链接解析。
- 结构化路由规则，支持域名、IP/CIDR、来源、网络及协议条件。
- Xray 与 sing-box 双内核通用的出站、路由和负载均衡。
- 负载均衡支持随机、轮询、最低延迟、最低负载与 Fallback。
- GeoSite / GeoIP 统一规则处理及 IPv4 / IPv6。
- 管理后台路由、出站与负载均衡 UI。
- Telegram 管理通知、套餐快照字段继承等面板定制。
- DBoard-node Machine Mode 与 WebSocket 实时通信。
- 可选 DUI-Gateway 加密 API 中间层。
- 单个整合 Nezha Agent、探针管理通信、域名迁移和持久上报。
- 服务器与探针的创建/删除/排序同步及独立显示 ID。
- 管理员一次性授权进入探针后台，公开监控首页没有登录入口。

## 安全

仓库不会提交：

- 生产 `.env`
- SQLite / MySQL / PostgreSQL 生产数据
- Redis RDB
- SSL 私钥
- Node Token / 面板 Token
- Telegram Token
- 支付密钥
- 生产日志

生产密钥只能保存在服务器持久数据目录或密钥管理系统中，不应进入 Git。

## License

DBoard 保留并遵循所使用上游项目及依赖的原始许可证。

---

# 交互式安装器说明

推荐入口：

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/install.sh)
```

主菜单：

```text
1. 安装/更新 独立版
2. 安装/更新 Docker 版
3. 仅安装 DUI-Gateway
4. 查看 DBoard 状态
0. 退出
```

安装器的核心行为：

- 自动创建并复用 `/opt/dboard/shared`。
- 已检测到 `INSTALLED=1|true` 时，只做升级和 migration，不重新初始化或清空数据库。
- 独立版切换到 Docker 版时，会停用 native systemd 服务，防止重启后抢占端口。
- Docker 版切换到独立版时，会先停止 DBoard Compose，再启动 native 服务。
- 两种模式切换时自动调整 `.env` 中的 Redis 地址，但 Redis RDB 与 SQLite 仍保留在同一数据目录。
- Docker 版优先拉取 GHCR 镜像；镜像不可用时自动从 GitHub main 构建。
- DUI-Gateway 默认不安装，交互时由用户选择。
- 安装器不会自动删除 `/opt/dboard/shared`。

常用非交互参数：

```bash
# 独立版 + SQLite
bash install.sh --mode native --database sqlite --admin admin@example.com --no-gateway --yes

# Docker 版 + SQLite
bash install.sh --mode docker --database sqlite --admin admin@example.com --no-gateway --yes

# 单独安装 Gateway
bash install.sh --mode gateway --gateway-backend http://127.0.0.1:7001 --gateway-port 3939 --yes

# 查看状态
bash install.sh --mode status
```

如果需要 MySQL/PostgreSQL 或自定义外部 Redis，建议使用交互安装或手工部署；官方自动化路径以 SQLite + Redis 8.4.2 为基准。
