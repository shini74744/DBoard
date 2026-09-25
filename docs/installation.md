# 从零安装：面板、用户端、DUI 与整合探针

本文以 **Ubuntu 24.04 + systemd + 独立版面板 + 同机 Connector** 为完整操作路径，适用于 2026-09-25 main 源码。命令中的 example.com、邮箱和文件来源均须换成自己的配置。

[用户使用](user-guide.md) · [后台流程](admin-workflows.md) · [升级备份](operations.md)

## 0. 先分清版本与安装范围

| 项目 | 本文基线 |
| --- | --- |
| 面板与探针后台 | main 已包含 fe5079e 的后台入口限制、删除/排序同步、显示 ID |
| 已发布整合 Agent | v0.2.1 |
| v0.2.1 Release 中的 probe-dashboard | 早于上述后台入口等修复；需要从当前源码构建新版后台 |
| 本次文档更新 | 不自动重新发布 Release 二进制 |
| 根目录 install.sh | 安装/更新面板，可选安装 DUI；不会自动部署整合探针全部组件 |
| probe/install-agent.sh | 安装节点机器上的整合 Agent，不是面板或监控后台安装器 |

不要认为 main 更新之后，旧 Release 附件也会自动更新。现有 v0.2.1 Agent 可以配合新版面板和探针后台，不必为纯后台修改重复升级 Agent。

下面命令用于新装。已有业务先做[完整备份](operations.md)，不要重新运行初始化向导覆盖原配置。

## 1. 规划域名、机器和端口

示例域名：

| 域名 | 用途 | 上游 |
| --- | --- | --- |
| user.example.com | 用户网页 | frontend 构建后的静态站点 |
| panel.example.com | DBoard 管理与后端 | 本机 7001；节点 WebSocket 为 8076 |
| api.example.com | 可选 DUI 用户 API 网关 | 网关主机 3939 |
| monitor.example.com | 探针公开监控与管理通信 | 探针主机 8008，同时支持 HTTP 与 gRPC |

可以在一台服务器配置不同虚拟主机，也可拆分探针主机。Connector 始终部署在可以通过真实回环地址访问面板的环境中。

| 端口 | 用途 | 暴露范围 |
| --- | --- | --- |
| 443 | 用户网站、面板入口、探针 TLS | 按域名提供公网服务 |
| 80 | HTTPS 跳转/证书验证 | 视证书方案决定 |
| 7001 / 8076 | 独立版面板 HTTP / 节点 WebSocket | 回环 |
| 8008 | 探针 HTTP + h2c gRPC 上游 | 回环 |
| 3939 | DUI HTTP 上游 | 通过 TLS 反代，核对监听范围 |
| 6379 | Redis | 回环，不公开 |
| 节点配置的监听端口 | 实际代理业务 | 按节点协议与业务要求开放 |

「管理服务只公开 80/443」不代表代理业务只需要这两个端口；代理入站端口仍需可达。

准备 root/sudo、systemd、有效 DNS、HTTPS 证书和可用构建空间。不要在已有 1Panel/OpenResty 占用 80/443 的机器再启动第二套抢占端口的 Web 服务。

## 2. 安装独立版面板

在面板服务器：

~~~bash
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/install.sh -o /root/dboard-install.sh
bash /root/dboard-install.sh
~~~

选择「安装/更新 独立版」，按向导配置数据库与管理员账号。默认路径使用 SQLite + Redis，数据保存在 /opt/dboard/shared。

也可明确指定：

~~~bash
bash /root/dboard-install.sh \
  --mode native --database sqlite \
  --admin admin@example.com --no-gateway --yes
~~~

安装器安装 PHP/Swoole/Redis 依赖、创建发布目录与服务，并完成首次初始化。记录安装输出中的实际后台路径与管理员凭据，随后登录修改初始密码。后台路径不保证叫 /666。

安装后检查：

~~~bash
systemctl is-active dboard-octane dboard-horizon dboard-ws dboard-scheduler dboard-redis
curl -fsS http://127.0.0.1:7001/api/v1/guest/comm/config
~~~

所有服务应处于 active，API 应返回有效 JSON。新装失败时先看对应 journalctl 日志，不继续配置下一层。

### 面板 HTTPS 反向代理

在 panel.example.com 的 TLS 虚拟主机加入：

~~~nginx
location /ws {
    proxy_pass http://127.0.0.1:8076;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600s;
}
location / {
    proxy_pass http://127.0.0.1:7001;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
~~~

这只是 server 块内的 location 片段；域名、监听和证书仍需由 Web 服务器配置。1Panel 中应测试配置后重载其 OpenResty 容器，不能假设宿主机存在 nginx.service。

## 3. 可选：安装 DUI-Gateway

在网关主机：

~~~bash
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/gateway/install.sh -o /root/dui-install.sh
bash /root/dui-install.sh install --backend 'https://panel.example.com'
~~~

同机时 backend 可使用 http://127.0.0.1:7001。保存安装器生成的 AES key，在 api.example.com 配置 TLS 并反代到 3939。详细配置见 [gateway/README.md](../gateway/README.md)。

检查网关 /healthz，再完成：

1. 用户端填写网关 URL、AES key 与 /dui/gw 路径。
2. 允许的浏览器来源按实际用户端域名配置。
3. 如订阅与支付通知也使用网关域名，配置对应直通前缀和允许的通知路径。
4. 在 DBoard 后台设置正确的订阅域名与支付通知域名。
5. 验证报价请求的查询参数和 POST 内容均能完整转发。

AES key 会存在于前端配置中，这层是 API 路径加密，不是替代 HTTPS 或用户登录认证的秘密通道。节点上报不使用 DUI。

## 4. 安装用户端

在构建机取得源码并记录实际提交：

~~~bash
git clone --depth 1 https://github.com/shini74744/DBoard.git /opt/dboard-source
cd /opt/dboard-source
git rev-parse HEAD
cd frontend
npm ci
cp public/runtime-config.example.js public/runtime-config.js
~~~

构建机需要 Node.js 与 npm；当前维护构建环境为 Node.js 22.23.2 / npm 10.9.8。可按 [Node.js 官方安装说明](https://nodejs.org/en/download)准备兼容环境，再执行上述命令。生产运行配置不提交 Git。

不使用 DUI、用户端与 API 不同域时，运行配置示例：

~~~javascript
window.EZ_CONFIG = {
  PANEL_TYPE: 'Xboard',
  API_CONFIG: {
    urlMode: 'static',
    staticBaseUrl: 'https://panel.example.com/api/v1'
  },
  API_MIDDLEWARE_ENABLED: false
};
~~~

使用 DUI 时的示例：

~~~javascript
window.EZ_CONFIG = {
  PANEL_TYPE: 'Xboard',
  API_MIDDLEWARE_ENABLED: true,
  API_MIDDLEWARE_URL: 'https://api.example.com',
  API_MIDDLEWARE_KEY: 'REPLACE_WITH_YOUR_GATEWAY_AES_KEY',
  API_MIDDLEWARE_PATH: '/dui/gw'
};
~~~

构建：

~~~bash
npm run build
~~~

将 dist/ 内容发布到 user.example.com 的静态目录，并使用 SPA 回退：

~~~nginx
location / {
    try_files $uri $uri/ /index.html;
}
~~~

给 runtime-config.js 设置不长期缓存的策略。后续更新静态文件时保留现有生产 runtime-config.js 与站点图片，不要让构建机的示例配置覆盖生产配置。

验收：浏览器能注册/登录、显示商店、正确查询报价、进入订单页。若依赖跨域 API，需配套后端 CORS；不要关闭浏览器安全策略排错。

## 5. 构建当前探针服务端

以下在 Linux 构建机运行。探针后台依赖 Go 1.26.6（以 go.mod 为准）、C 编译工具、Python 3，以及下载 Go 依赖和固定前端资源的网络。

在与探针服务器相同架构的 Linux 环境构建服务端；Dashboard 使用 SQLite CGO，跨架构不能仅替换 GOARCH 就假定可运行。

~~~bash
cd /opt/dboard-source
python3 probe/prepare-dashboard.py
mkdir -p probe/build/current
(
  cd probe/dashboard
  go build -p 2 -trimpath -ldflags '-s -w' \
    -o ../build/current/probe-dashboard ./cmd/dashboard
)
(
  cd probe/bridge
  CGO_ENABLED=0 go build -p 2 -trimpath -ldflags '-s -w' \
    -o ../build/current/probe-connector ./cmd/probe-connector
)
sha256sum probe/build/current/probe-dashboard probe/build/current/probe-connector
~~~

准备脚本使用 frontend-assets.lock.json 校验固定的 Nezha 前端资源，不随意运行 --update-lock 接受未知资源。

将两个程序、probe/examples/ 和 probe/install-agent.sh 传到对应服务器。跨主机传输后核对 SHA-256。下面示例假定源码/构建文件也位于目标服务器 /opt/dboard-source；否则先传入暂存目录，并相应修改 install 命令的源路径。

## 6. 初始化探针后台，先不发布公网入口

在探针主机：

~~~bash
id nezha >/dev/null 2>&1 || useradd --system --home-dir /var/lib/nezha-dashboard --shell /usr/sbin/nologin nezha
install -d -m 755 /opt/nezha
install -d -m 750 -o nezha -g nezha /etc/nezha-dashboard /var/lib/nezha-dashboard
install -d -m 750 -o nezha -g nezha /var/lib/nezha-dashboard/bridge
install -m 755 /opt/dboard-source/probe/build/current/probe-dashboard /opt/nezha/probe-dashboard
install -m 600 -o nezha -g nezha /opt/dboard-source/probe/examples/dashboard.yaml.example /etc/nezha-dashboard/config.yaml
install -m 600 /dev/null /etc/nezha-dashboard/bridge.env
install -m 644 /opt/dboard-source/probe/examples/nezha-dashboard.service /etc/systemd/system/nezha-dashboard.service
systemctl daemon-reload
systemctl enable --now nezha-dashboard
~~~

配置默认只监听 127.0.0.1:8008。首次启动时 bridge.env 暂为空，因此仅在这个未公开的初始化阶段不启用入口限制。

在自己的电脑通过 SSH 隧道访问：

~~~bash
ssh -N -L 18008:127.0.0.1:8008 root@YOUR_PROBE_HOST
~~~

浏览器打开 http://127.0.0.1:18008/dashboard/，新建空数据库的初始账号为 admin/admin。立即在后台修改初始密码，确认用于接入的管理员用户 ID；新数据库通常为 1，以实际数据库为准。初始化完关闭隧道。

如果探针设置中的真实 IP 请求头非空，隧道直连可能提示 real ip header not found；首次初始化使用示例配置默认值，公网反代设置在下一步完成。

## 7. 启用探针桥接与后台入口限制

在探针主机生成密钥：

~~~bash
systemctl stop nezha-dashboard
umask 077
openssl rand -hex 32 > /etc/nezha-dashboard/bridge.key
chown nezha:nezha /etc/nezha-dashboard/bridge.key
chmod 600 /etc/nezha-dashboard/bridge.key
~~~

将 /etc/nezha-dashboard/bridge.env 编辑为：

~~~dotenv
NEZHA_BRIDGE_URL=https://monitor.example.com
NEZHA_BRIDGE_KEY_FILE=/etc/nezha-dashboard/bridge.key
NEZHA_BRIDGE_OWNER_ID=1
NEZHA_BRIDGE_DATA=/var/lib/nezha-dashboard/bridge
~~~

OWNER_ID 换成刚才确认的用户 ID。文件由 root 保存为 0600。对接密钥后续需要填写到 DBoard，务必通过可信管理通道读取，不写入公开文档、工单或 Git。

~~~bash
systemctl start nezha-dashboard
systemctl is-active nezha-dashboard
~~~

一旦启用桥接，新版后台就要求由 DBoard 发放入口授权。此时手动访问 /dashboard/ 得到 404 是预期行为。不能为了方便恢复空 bridge.env 后继续公开服务。

## 8. 发布探针 HTTPS、gRPC 和 WebSocket

使用 [probe/examples/nginx.conf.example](../probe/examples/nginx.conf.example)：

1. 将 monitor.example.com 和证书路径换成实际值。
2. map 指令放在 Nginx/OpenResty 的 http 上下文，不放进 server/location。
3. 在探针域名 server 块保留 /proto.NezhaService/ 的 grpc_pass。
4. 其他 HTTP 与 WebSocket 请求反代到同一个 127.0.0.1:8008。
5. 保留 Host、X-Real-IP、WebSocket Upgrade 和长连接超时设置。
6. 运行 Web 服务器配置检查，通过后重载。
7. 不把探针首页跳转到面板后台，不缓存授权 POST、管理 API 和管理页面。

1Panel 用户可由面板配置证书和虚拟主机；不要把完整 server 配置嵌入一个只允许 location 的编辑框。若 1Panel 的反代容器使用桥接网络，容器内 127.0.0.1 不是宿主机，需调整网络布局和受限上游地址；示例按可访问宿主回环的环境编写。

使用 Cloudflare 橙云时，域名必须启用 gRPC，入口为 TLS 443 且支持 HTTP/2/ALPN，SSL 模式至少 Full，建议有效源站证书配合 Full (strict)。WebSocket 也要可用。参考 [Cloudflare gRPC](https://developers.cloudflare.com/network/grpc-connections/) 和 [WebSockets](https://developers.cloudflare.com/network/websockets/) 官方要求。

证书续期要同时保证源站有效、复制到正确位置并重载实际 Web 服务。不要以浏览器能打开首页代替 gRPC 和节点通道验收。

## 9. 在探针准备 Agent 下载文件

当前可使用 v0.2.1 的已发布 Agent。下载来自本仓库的对应 Release，并验证两个 Agent 文件：

~~~bash
install -d -m 700 /root/probe-agent-download
cd /root/probe-agent-download
AGENT_VERSION=v0.2.1
RELEASE_URL="https://github.com/shini74744/DBoard/releases/download/$AGENT_VERSION"
for file in nezha-agent-linux-amd64 nezha-agent-linux-arm64 SHA256SUMS; do
  curl -fL --retry 3 "$RELEASE_URL/$file" -o "$file"
done
grep -E '  nezha-agent-linux-(amd64|arm64)$' SHA256SUMS > AGENT-SHA256SUMS
test "$(wc -l < AGENT-SHA256SUMS)" -eq 2
sha256sum -c AGENT-SHA256SUMS
install -d -m 750 -o nezha -g nezha "/var/lib/nezha-dashboard/bridge/artifacts/$AGENT_VERSION"
install -m 644 -o nezha -g nezha nezha-agent-linux-amd64 nezha-agent-linux-arm64 SHA256SUMS "/var/lib/nezha-dashboard/bridge/artifacts/$AGENT_VERSION/"
install -m 644 -o nezha -g nezha /opt/dboard-source/probe/install-agent.sh /var/lib/nezha-dashboard/bridge/artifacts/install.sh
~~~

不要从这个旧 Release 再覆盖第 5 步构建的新 probe-dashboard。生产场景可在受控构建机下载、校验后再传到探针。

自行构建所有整合程序可使用 bash probe/build.sh <版本号>；脚本生成的服务端为构建机架构，Agent 包含 Linux amd64/arm64。版本名必须和探针设置、目录名、二进制内的版本一致。

## 10. 面板接入探针并安装 Connector

在 DBoard：

1. 打开「系统管理 → 探针管理 → 接入设置」。
2. 启用接入，填写 https://monitor.example.com、bridge.key 中同一把对接密钥、已准备好的 Agent 版本 v0.2.1。
3. 保存后展开「系统连接配置」，下载 Connector 配置。
4. 核对 Panel 为 http://127.0.0.1:7001，WebSocket 为 ws://127.0.0.1:8076/ws（独立版默认）。
5. 将文件通过安全通道传到面板服务器。示例假设暂存为 /root/probe-connector.json。

在面板服务器：

~~~bash
install -d -m 755 /opt/nezha
install -d -m 750 -o www-data -g www-data /etc/nezha-connector
install -m 755 /opt/dboard-source/probe/build/current/probe-connector /opt/nezha/probe-connector
install -m 600 -o www-data -g www-data /root/probe-connector.json /etc/nezha-connector/config.json
install -m 644 /opt/dboard-source/probe/examples/probe-connector.service /etc/systemd/system/probe-connector.service
systemctl daemon-reload
systemctl enable --now probe-connector
systemctl is-active probe-connector
~~~

Connector 配置包含控制密钥和面板连接密钥，不上传仓库。确认正式文件可读后，清理自己传输产生的临时副本。

回到「监控后台」点「检查连接」，应显示探针与系统已连接。随后点击「进入监控后台」，通过一次性授权打开新窗口，再用刚才设置的探针账号登录。过期后重新从 DBoard 进入。

## 11. 安装第一台服务器并创建节点

1. 在「服务器管理」添加一台测试服务器。
2. 复制这台服务器刚生成的完整命令，在该目标服务器执行。
3. 命令应从自己的探针域名下载 install.sh，参数包含 --endpoint、--uuid、--enrollment、--version。
4. 等待监控与节点通道健康检查通过，再查看服务器在线状态。
5. 在「节点管理」添加业务节点，绑定刚才的服务器，设置协议、监听端口与权限组。
6. 添加有此权限组的测试套餐，开通给测试账号，导入订阅。
7. 验证实际连接、TCP/UDP、用户流量，以及配置过的出站/分流。
8. 第一台通过后再分批安装其他服务器。

默认路径：

~~~text
服务：nezha-integrated-agent.service
程序：/usr/local/bin/nezha-integrated-agent
配置：/etc/nezha-integrated-agent/config.json
状态：/var/lib/nezha-integrated-agent
~~~

原厂哪吒的 nezha-agent.service 与这些路径独立。旧 DBoard 节点接管需明确使用 --takeover，详见[升级与迁移](operations.md)，不能把普通新装命令直接套用到复杂的多实例机器。

## 12. Docker 版的范围

只安装面板可选择根安装器的 Docker 菜单，或参考 [README](../README.md) 和 Compose 示例。拉取的镜像必须包含所需代码；main 已更新不代表旧缓存镜像已经更新。

完整探针对接要求面板实际收到的 REMOTE_ADDR 为 127.0.0.1 或 ::1。普通 Docker bridge 的宿主端口映射可能让面板看到桥接地址，Connector 即使填写 127.0.0.1 也不一定满足条件。

Docker 整合需按实际网络命名空间部署 Connector，并核对容器内 HTTP/WS 端口。不能靠伪造 X-Forwarded-For 放行，也不能直接把内部接口改成公网开放。本文完整命令路径使用独立版，避免把 Docker 面板安装成功误当作探针对接全部完成。

## 13. 最终验收表

- [ ] 面板登录、用户端登录与报价正常。
- [ ] 原卡片专属价、商店标价、新开与指定续费符合预期。
- [ ] 支付回调与套餐开通正常，订单详情与套餐卡片一致；队列/调度服务健康。
- [ ] 探针首页无后台入口；无授权访问后台返回 404。
- [ ] 从 DBoard 授权能进入后台；过期后可重新进入。
- [ ] 监控在线与节点通道在线均正常，用户代理连接可用。
- [ ] 服务器排序同步、删除同步和显示 ID 操作可在测试记录上完成。
- [ ] 证书续期、数据库/身份备份与恢复方式已记录。
- [ ] 新域名迁移先保留旧入口，未迁移/离线机器有清单。
