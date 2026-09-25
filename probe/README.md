# DBoard 与 Nezha 整合探针

本目录包含固定版本的 Nezha Dashboard/Agent 源码、认证桥接网关和面板 Connector。节点上的整合程序入口位于 [node/cmd/nezha-agent](../node/cmd/nezha-agent)。

## 文档入口

- [一步一步安装](../docs/installation.md)：从面板到用户端、DUI、监控后台、Connector、第一台 Agent。
- [后台处理流程](../docs/admin-workflows.md)：创建/删除/排序、授权入口、节点配置、流量与订单。
- [升级与运维](../docs/operations.md)：版本、接管、域名迁移、备份和故障恢复。
- [实现与验收记录](IMPLEMENTATION.md)。
- [用户端使用流程](../docs/user-guide.md)。

## 当前版本边界（2026-09-25）

main 已包含服务器删除同步、排序同步、显示 ID，以及只能从 DBoard 管理员入口授权进入探针后台的修改，功能基线为 fe5079e。

整合 Agent 已发布版本为 v0.2.1。该 Release 原有的 probe-dashboard 附件早于上述修改；需要最新后台功能时，从 main 构建 Dashboard。推送源码不会自动替换旧 Release 的二进制附件。

根安装器只处理面板及可选 DUI；整合探针后台和 Connector 仍需按安装指南部署。

## 三条独立链路

1. **用户网页 API**：浏览器 → 可选 DUI-Gateway → DBoard。
2. **节点管理/监控**：Agent → 探针 HTTPS/gRPC/WebSocket；面板 Connector 主动连接探针，再访问面板回环 HTTP/WS。
3. **客户代理业务**：代理客户端 → 节点内核 → 路由出口/落地 → 目标站点。

Agent 只取得自己的探针 UUID 和专属密钥，不接收面板域名、内部机器 ID 或原机器 Token。客户代理流量不经过监控桥接网关。

拥有节点 root 或虚拟化宿主权限的人仍能检查二进制、文件、端口、内存和代理行为；整合隔离了面板管理地址，不提供进程不可见性。历史旧节点配置为恢复可能保留。

## 后台入口规则

- 公开监控首页没有登录/管理入口。
- 从 DBoard「系统管理 → 探针管理 → 进入监控后台」申请 60 秒、单次授权。
- 探针通过 POST 消费授权，签发 Secure/HttpOnly 的主机专属 Cookie；2 小时后或进程重启后失效。
- 未授权访问后台页面、静态资源、登录 API 和管理 API 返回 404。
- 既有 Nezha JWT/PAT 不能单独绕过入口层；原 Nezha 账号认证和权限仍继续执行。
- 已授权浏览器在会话期间可继续访问，过期重新从 DBoard 进入。
- 管理入口限制不会把访客隐藏机器自动公开，也不拦截 Agent 上报通道。

首次新装需先在回环/SSH 隧道内初始化账号并修改 admin/admin 初始密码，再启用桥接配置和公网入口。详细顺序见安装指南。

## 构建

Linux 构建需要 Go 1.26.6（以 Dashboard go.mod 为准）、Python 3、C 编译环境和依赖下载能力：

~~~bash
python3 probe/prepare-dashboard.py
bash probe/build.sh v0.2.1
~~~

版本参数是输出版本示例；正式发布使用新的未占用版本号。prepare-dashboard.py 校验 frontend-assets.lock.json；只有明确审查上游变更后才更新锁。

输出 probe/build/<版本>/：

- Linux amd64/arm64 整合 Agent。
- 构建机架构的 probe-dashboard 和 probe-connector。
- install.sh、release.json、SHA256SUMS。

构建不执行部署或发布。build.sh 的校验和仅覆盖 Agent；正式发布脚本 scripts/release-probe.sh 会生成包含其他发布文件的清单。服务端单独构建方式见安装指南。

## 生命周期与可靠性

- 添加服务器创建实际 Nezha 身份，并生成约 15 分钟有效的注册命令。
- 原厂 nezha-agent.service 与整合服务 nezha-integrated-agent.service 使用独立路径。
- 删除先撤销探针身份、清理监控和运行时记录；保留无凭据注销标记，避免旧身份回流。
- 面板排序同步到探针默认排序；显示 ID 重排不修改内部身份。
- 更换域名先验证目标，再保存离线/在线迁移任务；未知新地址无法通知完全失联机器。
- 流量报告先在 Agent 持久化，带稳定批次 ID 重试；面板计费与回执同事务去重。
- 永久拒绝的报告隔离到 quarantine，避免阻塞其他节点。
- 本地报告队列上限为 10,000；满时提交失败，既有 tracker 保留内存增量，应及时恢复连接与磁盘容量。
- 更新器校验版本、哈希及整合身份，并等待监控/节点通道恢复；失败尝试回退。
- 原厂 Agent 自更新、外部配置/归属替换不能覆盖整合 Agent 管理逻辑。

## 测试与边界

~~~bash
cd probe/bridge
go test -race ./...
cd ../../node
go test ./internal/probeagent ./internal/panel ./cmd/nezha-agent
cd ../panel
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Feature/ProbeIntegrationTest.php
~~~

Go 协议/身份测试与 PHP 接口测试不能代替实际 systemd、证书、反向代理、Cloudflare、跨域 Cookie 和节点业务验收。生产升级先做备份和单机试点。

当前入口限制开启后，旧版直接 POST /api/v1/login 的外部巡检方式需要改成先取得合法入口授权；不能新增回环免鉴权等后门来恢复巡检。

## 上游与许可

[UPSTREAM.json](UPSTREAM.json) 记录导入版本。agent/、dashboard/ 保留上游许可证与声明。整合 Agent 库从固定上游派生，更新原上游程序时还需同步审核整合库，不应只替换官方哪吒二进制。
