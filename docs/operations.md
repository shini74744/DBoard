# 升级、备份、地址迁移与排错

适用基线：2026-09-25 main。安装见[完整步骤](installation.md)，功能行为见[后台处理流程](admin-workflows.md)。

## 1. 先判断需要更新哪一部分

| 变更内容 | 更新对象 | 是否通常需要更新 Agent |
| --- | --- | --- |
| 用户购买页、卡片、订单显示 | frontend 静态文件；如涉及接口则同时更新面板 | 否 |
| 套餐价格/容量/后台表单/显示 ID | 面板代码、迁移、管理前端 | 否 |
| 探针入口限制、删除/排序同步 | 面板 + probe-dashboard | 否 |
| 桥接协议或 Connector 实现 | 按变更说明更新 Dashboard/Connector/面板 | 视协议兼容性 |
| 节点内核、上报协议、新节点能力 | 对应 Agent 或传统 DBoard-node | 是，分批验证 |

main 是源码；Release 附件是某次发布的构建产物，两者不会自动保持相同版本。2026-09-25 已发布 Agent v0.2.1，但该 Release 的旧 probe-dashboard 不含后来 main 的入口限制等修复。

不要覆盖旧标签下的二进制却仍声称它是原构建；发布新二进制应使用新的版本与校验和，保留可回退版本。

## 2. 面板与前端升级顺序

1. 记录当前源码提交、程序版本、发布目录和所有入口配置。
2. 备份本节后面的完整数据边界。
3. 在暂存/发布目录更新面板源码与依赖。
4. 使用面板正常更新入口执行迁移；已有安装应走 xboard:update，不再次初始化。
5. 清理需要重建的路由/视图缓存，重启 Octane、队列及相关常驻服务。
6. 更新用户端 dist/，保留生产 runtime-config.js 与站点定制资源。
7. 如果涉及探针后台协议，同步更新 probe-dashboard/Connector。
8. 检查后台、购买报价、订单、节点通道和监控，再结束维护。

独立版可重新运行根安装器选择「安装/更新 独立版」。安装器检测到已初始化数据后走更新路径，但不代替业务备份，也不自动更新探针所有组件。

最近迁移包括：

| 迁移 | 用途 |
| --- | --- |
| 000023 | 节点展示费率 |
| 000024 | 探针对接、身份和流量回执 |
| 000025 | 整合 Agent 分批接管任务 |
| 000026 | 服务器显示 ID 与编号序列 |

准确文件名及执行状态以 panel/database/migrations/ 和 php artisan migrate:status 为准；不要只手动执行某一条 SQL 而跳过正常迁移顺序。

## 3. 探针后台二进制更新

1. 根据源码构建新 Dashboard；对比 SHA-256。
2. 把新程序放到一个新版本目录，不覆盖正在运行的文件。
3. 发布目录应允许 nezha 用户进入（例如 0755），程序应可执行。
4. 保存旧软链接目标；原子切换程序链接后重启 nezha-dashboard。
5. 检查 HTTPS 首页、从 DBoard 获取授权后的管理访问、监控与节点通道。
6. 检查失败时切回旧二进制，再确认面板与服务端协议相容。

入口会话保存在内存中，Dashboard 重启会使入口授权失效。管理员需从 DBoard 重新进入；这与原账号密码无关。

探针重启会使管理连接暂时重连。节点代理内核在节点主机运行，但仍应核对重连和实际代理业务，不能用「systemctl active」替代业务验收。

## 4. 整合 Agent 升级

1. 先构建或取得目标版本的 amd64/arm64 二进制。
2. 在探针 artifacts/<版本>/ 准备二进制与 SHA256SUMS。
3. 校验版本字符串、文件名和二进制内置版本一致。
4. 在「探针管理 → 接入设置」设置目标 Agent 版本。
5. 在服务器管理先选一台测试机器执行升级。
6. 后台会等待监控和节点通道恢复，不能只看到下载完成就认定成功。
7. 验证路由、流量、连接数等业务指标，再分批升级其余机器。

升级器校验下载的 SHA-256 与整合程序身份，保留旧程序；连接健康检查失败时尝试恢复旧程序。网络慢、磁盘不足或服务配置错误仍需人工排查。

已有独立安装的原厂 nezha-agent.service 与 nezha-integrated-agent.service 独立，不应替换或停止前者来升级整合程序。

## 5. 旧 DBoard-node 接管

### 手动接管单实例

- 为原机器生成新的探针命令，在命令末尾明确追加 --takeover。
- 安装器识别旧 /etc/DBoard-node/config.yml，保留内核选择。
- 完成下载和注册后才切换旧服务，并验证监控与节点通道。
- 失败时尝试恢复旧服务；历史配置文件会为恢复保留。
- 多实例部署不要未经核对直接套用单实例接管。

### 面板分批迁移队列

面板已部署支持此功能的源码时：

~~~bash
cd /opt/dboard/current
sudo -u www-data php artisan probe:rollout --status
sudo -u www-data php artisan probe:rollout 3 7
~~~

上面的 3、7 是示例内部机器 ID，执行前换成自己确认的目标，不能用重编号后的显示 ID 猜测内部 ID。

迁移分为先升级兼容的旧节点、再使用内置接管安装器切换整合 Agent。调度器约每分钟继续队列；离线机器等待新心跳，失败任务不无限自动重试。确认新通道成功后才停用原直连入口。

若要求跳过某台机器，应保留明确清单，不把一次失败自动理解为可反复强制接管。

## 6. 变更探针连接域名

1. 新域名先指向同一探针服务，配置有效证书、gRPC 和 WebSocket。
2. 新旧域名暂时同时可用。
3. 打开「系统管理 → 探针管理 → 更新连接地址」。
4. 输入新地址和可选备用地址，选择需要迁移的服务器，提交检查与更新。
5. 后端校验目标网关身份，持久保存任务并更新连接器的地址来源。
6. 在线 Agent 验证新地址上的 HTTPS、监控 gRPC 和节点通道，再保存新地址并重连。
7. 核对每台机器的实际连接地址与迁移状态；「备用地址」状态不等于成功迁到目标地址。
8. 离线机器恢复旧/备用连接后才会取得迁移任务。
9. 所有目标确认完成后再考虑撤掉旧域名。

如果所有已知域名都不可达，后台无法把未知新域名送到离线节点。应恢复已知地址，或在目标机器本地修改配置/重新安装。

换一台探针主机还必须搬迁 Nezha 数据库、bridge/devices.json、对接密钥和配置；仅修改 DNS/URL 不会迁移身份。

## 7. 备份边界

/opt/dboard/shared 是**面板**的持久数据边界。启用探针、独立前端和 DUI 后，整套系统备份还包括下表：

| 数据 | 默认位置或来源 | 原因 |
| --- | --- | --- |
| 面板数据库、APP_KEY、插件和主题 | /opt/dboard/shared | 业务与加密字段恢复 |
| Redis RDB | /opt/dboard/shared/redis | 队列/运行状态，按实际配置确认 |
| 探针数据库 | /var/lib/nezha-dashboard/dashboard.db | 监控账号、服务器及管理数据 |
| 探针时序数据 | /var/lib/nezha-dashboard/tsdb | 历史监控曲线 |
| 身份注册表 | /var/lib/nezha-dashboard/bridge/devices.json | 服务器认证与注销状态 |
| 探针配置和 control key | /etc/nezha-dashboard | 身份桥接与上游地址 |
| Connector 配置 | /etc/nezha-connector/config.json | 面板内部连接与密钥 |
| Node 本机配置/持久上报 | /etc/nezha-integrated-agent、/var/lib/nezha-integrated-agent | 节点身份、端点与未确认上报 |
| DUI 配置 | /etc/DUI-Gateway/gateway.env | 后端目标、AES/CORS/直通路径 |
| 用户端运行配置与定制资源 | 实际静态站点目录 | 发布时不能被示例覆盖 |
| TLS、反代、systemd 和版本清单 | 按部署环境 | 能重建运行环境 |

备份密钥和身份必须与数据库一起保留。只恢复数据库而更换 APP_KEY，可能无法解密原有节点身份等数据。

### 一致性备份

维护窗口暂停配置变更；需要跨组件严格一致时，停止相关写入服务后一起备份。不要直接复制正在写入的 SQLite 文件及其 WAL 后声称是完整快照。

SQLite online backup 示例（在有两个默认数据库的主机执行；分机部署分别执行）：

~~~bash
umask 077
BACKUP_DIR="/opt/dboard/backups/manual-$(date +%Y%m%d-%H%M%S)"
export BACKUP_DIR
mkdir -p "$BACKUP_DIR"
python3 - <<'PY'
import os, sqlite3
from pathlib import Path
out = Path(os.environ['BACKUP_DIR'])
pairs = [
    ('/opt/dboard/shared/data/database.sqlite', 'panel.sqlite'),
    ('/var/lib/nezha-dashboard/dashboard.db', 'monitor.sqlite'),
]
for source, name in pairs:
    src = sqlite3.connect('file:' + source + '?mode=ro', uri=True)
    dst = sqlite3.connect(out / name)
    src.backup(dst)
    assert dst.execute('PRAGMA integrity_check').fetchone()[0] == 'ok'
    dst.close()
    src.close()
print('SQLite backups verified')
PY
~~~

随后：

1. 对实际 Redis 执行 BGSAVE，等待本次快照结束，确认 rdb_bgsave_in_progress 为 0 且最近保存成功。
2. 用与生产 Redis 相容的 redis-check-rdb 检查 RDB；不要用旧 Redis 强行读取新格式。
3. 将上表的配置、密钥、身份、时序数据、插件和站点定制文件纳入备份；数据库使用已校验的副本。
4. 记录源码提交、Agent 版本、二进制 SHA-256、服务链接目标与端口。
5. 生成备份校验清单，复制到另一台受控机器并再次校验。

MySQL/PostgreSQL 使用其一致性备份方案，不能套用上面的 SQLite 命令。备份包含真实凭据，不能放进 GitHub 仓库或公开 Release。

### 恢复

1. 选择匹配的面板、探针和节点版本。
2. 暂停相关写服务，保留故障现场和当前数据副本。
3. 恢复面板数据库及 APP_KEY、探针数据库/注册表/控制密钥、Connector 和反代配置。
4. 修复目录和密钥文件所有者，保证服务用户能访问。
5. 启动服务，验证数据库、报价、订单、订阅、监控与节点上报。
6. 确认恢复点之后的订单/流量是否需要核对补偿；不能直接用旧库覆盖新业务而忽略数据差异。

## 8. 按层排查问题

| 现象 | 排查顺序 |
| --- | --- |
| 首页打开，但无机器 | 先区分访客与管理员；默认机器对访客隐藏，再看 Agent 监控是否在线 |
| 手动 /dashboard 返回 404 | 新版预期行为；从 DBoard 探针管理进入 |
| 从 DBoard 也进不去 | 浏览器弹窗拦截 → 探针启用/地址/对接密钥 → 服务端版本 → 一次性授权是否过期 |
| 密码登录正常过，但刷新后失效 | 入口会话 2 小时/探针重启、浏览器 Cookie、原 Nezha 登录状态 |
| 监控在线，节点离线 | Connector 是否连接、面板回环端口、内部接口鉴权、业务节点绑定 |
| 节点在线，监控离线 | gRPC 路由、Cloudflare gRPC 开关、TLS/HTTP2、Agent 专属身份 |
| Connector 提示 403 | 密钥是否配套；PHP 实际 REMOTE_ADDR 是否回环，特别检查 Docker 网络 |
| 本机 curl 返回 real ip header not found | 按当前 WebRealIPHeader 提供内部健康检查头；不要删除生产鉴权 |
| 探针 service 无法执行 | 程序权限、发布目录可进入权限、CPU 架构、动态库 |
| 更新域名长时间 pending | 旧/备用入口是否仍可达、机器是否在线、新域名三个通道是否都通过 |
| 升级下载超时 | 节点到探针连通性、下载文件与版本目录、磁盘空间，失败保留原版本 |
| 报价显示 0 或未确认 | 套餐价格是否 null、周期/目标、网关 GET 参数是否完整、后端错误响应 |
| 已付款未开通 | 支付回调、同步开通错误日志、订单状态与套餐目标；再检查队列/调度任务 |

常用只读检查：

~~~bash
systemctl status nezha-dashboard probe-connector
journalctl -u nezha-dashboard -n 80 --no-pager
journalctl -u probe-connector -n 80 --no-pager
systemctl status dboard-octane dboard-horizon dboard-ws dboard-scheduler
~~~

在节点机器：

~~~bash
systemctl status nezha-integrated-agent
journalctl -u nezha-integrated-agent -n 80 --no-pager
~~~

日志和安装命令可能含身份信息，发给他人前删除密钥、Token 和 enrollment。健康检查可使用实际配置的真实 IP 头，例如 X-Real-IP；直接测试回环上游时要区分返回的 JSON 错误与真正的首页 HTML。

## 9. 运维记录模板

每次更新记录：

- 时间、操作者、涉及的组件与源码提交。
- 更新前版本、更新后版本、二进制校验和。
- 数据备份路径及异机校验结果。
- 修改了哪些域名、端口、服务或数据库迁移。
- 试点机器与跳过机器（内部 ID 和名称）。
- 管理入口、用户下单、订阅连接、监控和节点通道的验证结果。
- 回退位置、失败处理、临时凭据/传输授权是否清理。
- GitHub 源码是否推送、Release 是否另行发布；两项分别记录。
