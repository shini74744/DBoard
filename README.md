# DBoard

DBoard 是当前定制版 Xboard 面板与 Xboard-Node 的独立源码仓库。

本仓库以单仓库方式保存面板与节点程序，不包含原项目的 Git 历史，也不包含运行环境中的密钥、数据库、日志或 `.env`。

## 目录结构

```text
DBoard/
├── panel/   # Xboard 面板后端、管理后台静态资源及主题
└── node/    # Xboard-Node、双内核兼容层与测试
```

## 当前主要改动

- 出站规则管理与 VMess / VLESS / Trojan / Shadowsocks 链接解析。
- 结构化路由规则，支持域名、IP/CIDR、来源、网络及协议条件。
- Xray 与 sing-box 双内核通用的出站、路由和负载均衡。
- 负载均衡支持随机、轮询、最低延迟、最低负载和 Fallback。
- GeoSite / GeoIP 统一规则处理以及 IPv4 / IPv6。
- 管理后台路由、出站与负载均衡 UI。
- Telegram 管理通知、套餐快照字段继承等面板定制。


## 服务器安装

管理后台生成的一键命令继续使用当前面板地址，可直接使用 `IP:端口`，不要求配置域名。

```bash
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/node/install.sh | \
  sudo bash -s -- --mode machine --panel http://203.0.113.10:8888 --token TOKEN --machine-id 1
```

安装器从 DBoard 的 GitHub Release 下载与 CPU 架构对应的 `DUI-node` 和 `xbctl`。

## 双内核说明

面板的结构化 Outbound / RouteRule / Balancer 使用统一模型，由 Node 分别编译到 Xray 和 sing-box。

标准化管理功能不会因为配置了负载均衡而自动切换内核。内核原生的 raw custom config 仍然属于高级逃生口，不保证跨内核通用。

`node/third_party/` 中包含本项目为并发安全与兼容性修正过的 sing / sing-box 固定版本源码。它们由 `node/go.mod` 的 replace 指令引用，因此是可复现构建所必需的源码，不应删除。

## 构建 Node

```bash
cd node
make build
```

完整测试：

```bash
cd node
go test ./...
```

正式构建会启用项目 Makefile 中定义的 uTLS、QUIC、WireGuard 等标签。

## 面板

面板后端位于 `panel/`，依赖 PHP 8.2+、Composer、Redis 等 Xboard 运行依赖。

```bash
cd panel
composer install --no-dev --optimize-autoloader
```

管理后台当前保留的是已经修改并验证过的编译产物：

```text
panel/public/assets/admin/
```

当前工作源码中没有完整的管理后台 React/TypeScript 原始工程，因此该目录不能被描述为完整的前端源码工程。后端与 Node 源码完整可继续开发、测试和构建。

## 安全

仓库不会提交：

- `.env`
- 生产数据库
- Laravel / 服务运行日志
- Node 的生产 `config.yml`
- GitHub Token、面板 Token、Telegram Token 等运行密钥
- 构建出来的可执行文件

部署时请从示例配置创建自己的配置文件，不要把生产密钥写入 Git。

## 验证

2026-09-20 的双内核版本已执行 Node 完整测试、真实 TCP/UDP 集成测试、Reality/SS2022 跨内核互通测试，以及面板端兼容性和浏览器回归测试。

## 发布 Node

构建机安装并登录 GitHub CLI 后，可发布新的 Node 二进制：

```bash
./scripts/release-node.sh v0.1.0
```

Release 会包含 amd64 / arm64 的 `DUI-node`、`xbctl` 以及 `SHA256SUMS`。
面板一键安装和 `xbctl upgrade` 默认读取 GitHub 的 latest Release。

> 从 v0.1.1 及更早的旧命名版本迁移到 `DUI-node` 时，请先执行一次新版安装脚本的 `upgrade` 动作完成目录、二进制和 systemd 服务迁移；迁移完成后后续版本继续使用 `xbctl upgrade`。

## DUI-Gateway

`gateway/` 提供独立的加密 API 中间层，参考 JC 现有中间件的调用方式实现：

```text
前端 / 用户 → HTTPS → DUI-Gateway → 真实 DBoard 后端
```

普通 API 路径使用 AES-CBC + PKCS7 加密后通过 `X-IV` 头传输；订阅和支付通知路径支持直通。真实后端地址只保存在 Gateway 服务端配置中。

安装示例：

```bash
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/gateway/install.sh | \
  sudo bash -s -- install \
  --backend 'https://backend.example.com'
```

详细说明见 `gateway/README.md`。

Gateway 使用独立的 `gateway-v*` prerelease，不会改变 Node 的 GitHub `latest` Release：

```bash
./scripts/release-gateway.sh gateway-v0.1.0
```
