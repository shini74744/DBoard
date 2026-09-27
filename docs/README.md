# DBoard 文档导航

文档核对日期：2026-09-27；功能源码基线为 main / e06a583，本次同时补充安装器阶段提示。Release 与镜像需另行核对。

## 按角色阅读

| 要做的事 | 文档 |
| --- | --- |
| 注册、购买、续费、导入订阅、查看订单 | [用户端使用指南](user-guide.md) |
| 了解后台操作和实际处理链路 | [系统后台处理流程](admin-workflows.md) |
| 第一条命令、每个选择、默认值与安装提醒 | [逐项安装引导](installation-choices.md) |
| Docker 自动、手工、1Panel、host 与拆分 | [Docker 安装](docker-installation.md) |
| 从零安装完整系统 | [逐步安装指南](installation.md) |
| 更新版本、接管旧节点、换域名、备份恢复、排错 | [运维指南](operations.md) |
| 了解探针模块与源码构建 | [探针说明](../probe/README.md) |
| 查看探针实现和验收记录 | [实现记录](../probe/IMPLEMENTATION.md) |
| 用户 API 加密中间层 | [DUI-Gateway](../gateway/README.md) |
| 独立直连节点与高级内核配置 | [传统 DBoard-node](../node/README.md) |
| 落地仅允许指定前置接入 | [前置认证](front-gate-worklog.md) |
| 面板数据目录 | [持久数据](../deploy/PERSISTENT_DATA.md) |
| 套餐和用户端阶段更新 | [2026-09-25 更新记录](updates-2026-09-25.md) |

## 阅读前先确认

- 面板、用户端静态站点、DUI、探针后台、Connector、节点 Agent 是分别部署的组件。
- 根 install.sh 不会自动把整合探针全部安装完。
- main 更新不等于 Release 附件更新；旧 Dashboard 二进制不自动获得新的后台入口限制。
- 代理客户端的数据流量不经过网页 API 网关或监控桥接。
- 公开监控没有后台登录入口，管理员要从 DBoard 探针管理获取授权。
- 示例只使用占位域名，不能把示例配置直接当作生产配置。

## 关键说明核对的代码入口

| 说明 | 实现 |
| --- | --- |
| 购买来源、目标锁、报价、支付与开通 | [OrderService](../panel/app/Services/OrderService.php) |
| 原套餐专属价格 | [PackageBillingService](../panel/app/Services/PackageBillingService.php) |
| 库存容量与可购买校验 | [PlanService](../panel/app/Services/PlanService.php) |
| 用户端库存标签 | [planStock](../frontend/src/utils/planStock.js) |
| 管理后台探针对接 | [ProbeService](../panel/app/Services/ProbeService.php) |
| Connector 内部回环鉴权、上报去重 | [ProbeController](../panel/app/Http/Controllers/V2/Server/ProbeController.php) |
| 探针后台入口授权 | [AdminGate](../probe/bridge/admin_gate.go) |
| 整合节点安装 | [install-agent.sh](../probe/install-agent.sh) |
| 面板安装/更新 | [install.sh](../install.sh) |
| 探针构建 | [build.sh](../probe/build.sh) |

文档以代码现有行为为准。升级后若修改了价格、权限、通信或安装流程，应同步更新对应指南，而不只追加一条更新日志。

## 本次安装引导核对范围

- 对照根安装器、PHP 初始化、DUI 安装器、Agent 安装器、Docker 入口和四份 Compose 核对选项与默认值。
- 安装器新增 --guide、逐阶段提示和失败阶段定位；已安装提示不再引导清空 .env。
- 可在仓库根目录执行 python3 scripts/test-install-guidance.py，离线验证菜单、默认选择、新装/更新分支、阶段和错误提示。安装、下载、服务操作均使用替身，不操作真实业务。
- 同时检查文档相对链接、Shell/JavaScript 示例语法、PHP 语法和 Docker Compose 配置。
- 本次没有在全新机器完整重装所有模式；证书、Cloudflare、真实数据库/网络与节点业务仍按各模式验收表逐层确认。
