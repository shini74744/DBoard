# DBoard 持久数据目录

DBoard 面板统一使用 `/opt/dboard/shared` 作为面板唯一持久数据目录。

程序代码、Composer vendor、缓存及临时文件均可重新生成，不应作为迁移数据源。

## 目录布局

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

其中 SQLite 保存永久业务数据；Redis 保存缓存、队列和运行时状态。插件自己的文件与运行数据分别位于 `plugins/` 和 `storage/app/`。

独立部署通过符号链接把这些目录挂入程序 release。
Docker 部署通过 bind mount 把同一目录挂入容器，因此两种部署方式可以使用相同的数据包。

以下内容不属于持久数据：

- `storage/framework`
- Composer `vendor`
- Octane / WorkerMan PID 与状态文件
- 临时缓存和编译视图

迁移或恢复时，应以整个 `/opt/dboard/shared` 为数据边界，并在备份前正确刷新 SQLite 与 Redis 快照。

## 整合探针的附加数据边界

上述目录只覆盖面板。完整系统还必须保存：

- /var/lib/nezha-dashboard/dashboard.db 与时序数据目录 tsdb。
- /var/lib/nezha-dashboard/bridge/devices.json（含注销标记）。
- /etc/nezha-dashboard 的配置和控制密钥，以及 /etc/nezha-connector/config.json。
- 节点本机整合 Agent 配置与未确认报告队列。
- 独立用户端的 runtime-config.js、定制资源、DUI 配置、证书与反代配置。

APP_KEY、探针数据库、身份注册表与相关密钥必须配套恢复；不要只恢复其中一个文件。
逐步备份、校验和恢复流程见[运维指南](../docs/operations.md)。
