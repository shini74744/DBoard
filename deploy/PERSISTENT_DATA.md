# DBoard 持久数据目录

DBoard 统一使用 `/opt/dboard/shared` 作为唯一持久数据目录。

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