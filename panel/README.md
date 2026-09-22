# DBoard Panel

这里是 DBoard 面板源码目录。

DBoard 面板基于 Laravel 12 + Octane，包含管理后台编译资源、插件系统、节点管理、出站规则、路由规则和负载均衡等定制。

> 完整安装、升级、Docker、独立部署、DBoard-node、DUI-Gateway、备份与迁移说明统一维护在仓库根目录 [README.md](../README.md)。

## 部署方式

正式支持两种方式：

1. **独立版**：宿主机运行 PHP / Swoole / Redis / systemd。
2. **Docker 版**：使用 DBoard 自己的 Docker 镜像。

两种方式共用同一个持久数据根目录：

```text
/opt/dboard/shared
```

不要把生产数据库、Redis RDB、插件运行数据或 `.env` 放进源码目录作为迁移依据。

## Docker 镜像

项目镜像：

```text
ghcr.io/shini74744/dboard:latest
```

`Dockerfile` 直接复制本目录源码进行构建，不会再 clone 或替换为 cedar2025/Xboard。

镜像当前包含：

- PHP 8.3
- Swoole 6.2.x
- Redis 8.4.2
- Laravel Octane
- Horizon
- WorkerMan WebSocket
- Scheduler
- Caddy

默认 Compose：

```text
compose.sample.yaml
```
其它模板：

- `compose.host.sample.yaml`
- `compose.1panel.sample.yaml`
- `compose.split.sample.yaml`

本地构建：

```bash
cd ..
./scripts/build-docker.sh dboard:local
```

## 手工安装依赖

开发或独立部署时：

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

底层兼容安装命令仍然保留：

```bash
php artisan xboard:install
```

命令名保留 `xboard:*` 是为了兼容原有升级链路和插件生态，不代表部署的是上游 Cedar XBoard。

## 数据库

默认推荐 SQLite：

```text
/opt/dboard/shared/data/database.sqlite
```

同时仍支持 MySQL 与 PostgreSQL。

Redis 推荐并在官方部署中固定为：

```text
Redis 8.4.2
```

不要把 Redis 8.4.2 的 RDB 随意降级交给 Redis 7.x 读取。

## 管理后台资源

已验证的管理后台编译产物位于：

```text
public/assets/admin/
```

当前仓库保留的是可直接部署的编译资源。若重新构建前端，请确保 DBoard 的 DBoard-node、路由、出站和负载均衡相关改动仍然存在。

## 上游与许可证

DBoard 基于 XBoard 进行二次开发。上游版权、依赖许可证及原始 License 要求继续保留并遵守。
