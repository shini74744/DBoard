# Docker 安装：默认、1Panel、host 与拆分模式

核对日期：2026-09-27。本文从登录服务器开始，区分自动安装与手工 Compose。完整探针还需[主安装指南](installation.md)中的 Dashboard/Connector 步骤。

## 0. 先决定用哪一种

| 方式 | 适合情况 | 入口 | 端口/网络提醒 |
| --- | --- | --- | --- |
| 自动单容器 | 首次装面板，希望安装器准备 Docker、数据和镜像 | 根安装器菜单 2 | 使用默认 bridge 示例；再次运行会覆盖 Compose 定制 |
| 手工默认 bridge | 已有 Docker，需要自己固定镜像和维护 Compose | compose.sample.yaml | 默认 7001:7001 是全部接口 |
| 手工 1Panel | 需要访问 1Panel 网络里的数据库/反代 | compose.1panel.sample.yaml | 必须已有实际名称匹配的 1panel-network |
| 手工 host | Linux 宿主网络，明确知道端口占用 | compose.host.sample.yaml | 容器服务共享宿主端口，不使用 ports 映射 |
| 手工拆分 | 分开管理 Web、队列、WS、调度和 Redis | compose.split.sample.yaml | 使用外部 Redis 容器，初始化选择与单容器不同 |

四个 Compose 示例是**互斥部署选择**。不要全部启动，也不要对已有数据依次运行初始化。根安装器不会询问这四种 Compose 子类型；选择高级示例需走下方手工路径。

## 1. 登录与准备

通过 SSH 登录面板服务器，普通用户先进入 root shell：

~~~bash
sudo -i
~~~

若已是 root，无需再执行。已有业务先做[完整备份](operations.md)。Ubuntu/Debian 缺少下载工具时：

~~~bash
apt-get update
apt-get install -y ca-certificates curl git
~~~

只运行与你选择方式对应的一条路径。

## 2. 路径 A：自动单容器

### 第一步：下载安装器并阅读选项

~~~bash
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/install.sh -o /root/dboard-install.sh
bash /root/dboard-install.sh --guide
bash /root/dboard-install.sh --mode docker --no-gateway
~~~

默认新装为 SQLite + 内置 Redis，按提示输入管理员邮箱；密码自动生成。想在底层选择已有数据库，在最后一条命令加 --database interactive。详细问题表见[逐项选择指南](installation-choices.md)。

### 第二步：记下凭据并检查

~~~bash
cd /opt/dboard/docker
docker compose ps
docker compose logs --tail=100 dboard
docker compose exec -u www dboard php artisan migrate:status
curl -fsS http://127.0.0.1:7001/api/v1/guest/comm/config
~~~

镜像运行用户为 **www**，不是独立版服务的 www-data。容器 Up 和 HTTP 200 不保证迁移成功；日志有 xboard:update failed 时先排错。

### 第三步：反代与收尾

配置域名 HTTPS，HTTP 与 /ws 都反代到面板容器的 7001。默认 Compose 向全部宿主接口发布；宿主反代可编辑 /opt/dboard/docker/compose.yaml，将 7001:7001 改为 127.0.0.1:7001:7001 后执行：

~~~bash
cd /opt/dboard/docker
docker compose config --quiet
docker compose up -d
~~~

确认反代进程能访问该地址后才限制监听。如果 1Panel 的 OpenResty 在 bridge 容器里，127.0.0.1 指向它自己，应按实际共享网络连接服务。根安装器下次运行会重写默认 Compose，保留并复核此修改。

通过域名登录，修改初始密码，再继续用户端与探针部署。

## 3. 路径 B：手工 Compose，共同准备

以下只用于**新安装**。先确认 Docker Engine 与 Compose V2 已存在；没有则先用[Docker 官方安装说明](https://docs.docker.com/engine/install/)按系统安装，或回到自动路径。已有 1Panel Docker 可复用，不重复启动第二套 Docker。

~~~bash
docker version
docker compose version
git clone --depth 1 https://github.com/shini74744/DBoard.git /opt/dboard-source
cd /opt/dboard-source
git rev-parse HEAD
bash scripts/init-shared-data.sh
install -d -m 755 /opt/dboard/docker
printf 'DBOARD_DATA_DIR=/opt/dboard/shared\n' > /opt/dboard/docker/.env
~~~

源码目录已存在时先确认版本，不重复 clone 或删除目录。init-shared-data.sh 只准备目录，不初始化业务数据；自定义目录用 DBOARD_DATA_DIR 传入并同步修改 Compose .env。

### 第二步：四选一，只复制一个示例

**默认 bridge：**

~~~bash
cp /opt/dboard-source/panel/compose.sample.yaml /opt/dboard/docker/compose.yaml
~~~

**1Panel：**

~~~bash
docker network inspect 1panel-network
cp /opt/dboard-source/panel/compose.1panel.sample.yaml /opt/dboard/docker/compose.yaml
~~~

网络不存在时，在 1Panel 核实真实名称并修改 Compose 的网络定义，不盲目新建一个与数据库无关的同名网络。数据库地址填 1Panel 提供的容器名/网络别名；它必须与 DBoard 共享网络。反代的 7001 上游也以其所在网络为准。

**host：**

~~~bash
ss -ltn
cp /opt/dboard-source/panel/compose.host.sample.yaml /opt/dboard/docker/compose.yaml
~~~

先确认 7001、内部 Octane 7002、WS 8076 等端口没有冲突。此模式下端口使用宿主网络；默认 Caddy 的 :7001 并不自动限为回环。核对 Caddy 监听、宿主防火墙与反代。

**拆分：**

~~~bash
cp /opt/dboard-source/panel/compose.split.sample.yaml /opt/dboard/docker/compose.yaml
install -d -m 755 /opt/dboard/docker/.docker/caddy
cp /opt/dboard-source/panel/.docker/caddy/Caddyfile.split /opt/dboard/docker/.docker/caddy/Caddyfile.split
~~~

不要漏掉相对路径引用的 Caddyfile.split。拆分服务名为 caddy、web、horizon、ws-server、scheduler、redis，不是 dboard。

### 第三步：核对配置与镜像版本

~~~bash
cd /opt/dboard/docker
docker compose config --quiet
docker compose pull
docker image inspect ghcr.io/shini74744/dboard:latest --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}'
~~~

核对 [Docker 构建任务](https://github.com/shini74744/DBoard/actions/workflows/docker-image.yml)是否已完成，以及镜像 revision 是否包含所需提交。main 推送后镜像可能还在构建，拉取成功也可能拿到前一版本。

需要固定版本时，在 Compose 中改成已存在的 sha 标签或 digest。若 GHCR 不可用，可在构建机运行仓库 scripts/build-docker.sh，然后把对应 Compose 镜像改为实际本机构建标签；多角色拆分时所有面板角色使用同一版本。

### 第四步：按所选方式初始化一次

**默认 / 1Panel / host 单容器，SQLite：**

~~~bash
cd /opt/dboard/docker
docker compose run --rm -e ENABLE_SQLITE=true -e ENABLE_REDIS=true dboard php artisan xboard:install
~~~

按提示输入管理员邮箱并保存生成的密码。选择外部数据库时去掉 -e ENABLE_SQLITE=true，按初始化向导填写；内置 Redis 保留。外部数据库须先准备专用库、账号和网络连接。

**拆分模式：**

~~~bash
cd /opt/dboard/docker
docker compose up -d redis
docker compose exec redis redis-cli ping
docker compose run --rm -e ENABLE_SQLITE=true -e ENABLE_REDIS= web php artisan xboard:install
~~~

应先得到 PONG。在“是否启用 Docker 内置 Redis”中选 **不启用**，随后 Redis 地址填 **redis**、端口 **6379**、密码留空（示例默认无密码，按实际修改）。

这里有意给 ENABLE_REDIS 传空值：当前 PHP 初始化器按字符串真值读取环境，字符串 false 仍为真，不能用 -e ENABLE_REDIS=false 代替空值。拆分模式只有独立 Redis 容器，不存在单容器的 /data/redis.sock。命令只运行初始化，后续由 Compose 的正常服务环境启动 Supervisor。

已有业务出现清空数据库提示应退出，不能用新装步骤进行恢复。初始化后数据目录 .env 应有 INSTALLED=true 或 INSTALLED=1。

### 第五步：启动并按模式验收

~~~bash
cd /opt/dboard/docker
docker compose up -d
docker compose ps
docker compose logs --tail=100
curl -fsS http://127.0.0.1:7001/api/v1/guest/comm/config
~~~

单容器查看迁移：

~~~bash
docker compose exec -u www dboard php artisan migrate:status
~~~

拆分模式查看迁移：

~~~bash
docker compose exec -u www web php artisan migrate:status
~~~

日志里迁移失败、WS/队列不断重启时先修复，再配置域名 HTTPS。随后按[完整安装指南](installation.md)部署用户端、可选 DUI、探针和 Connector。host/bridge 的名称本身不能证明 Connector 的真实来源满足面板回环鉴权，需要实际核对。

## 4. 已有 Docker 如何更新

1. 备份 shared、Compose/.env、镜像 ID 和其它组件配置。
2. 自动默认安装可以重新选择根安装器菜单 2；自定义 Compose 使用下面的手工更新，避免被默认模板覆盖。
3. 核对所需镜像已经构建完成，更新 Compose 的版本引用。
4. 拉取并重建，检查升级日志与迁移，再验收用户登录、报价、订单和订阅。

~~~bash
cd /opt/dboard/docker
docker compose config --quiet
docker compose pull
docker compose up -d
docker compose ps
docker compose logs --tail=100
~~~

不要再次执行 xboard:install，也不要删除 .env、卷或 shared。回退镜像不等于回退数据库结构；迁移不兼容时需按已验证的备份恢复方案处理。

