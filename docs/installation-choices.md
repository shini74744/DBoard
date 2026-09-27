# 安装时每一步怎么选

核对日期：2026-09-27。依据仓库当前安装器、初始化命令和 Compose 配置核对；完整命令见[安装指南](installation.md)，已有业务先看[升级与备份](operations.md)。

## 0. 从第一步开始：登录并下载

以下命令在**面板服务器**执行。已经是 root 就跳过 sudo -i。Ubuntu/Debian 缺少下载工具时先安装：

~~~bash
sudo -i
apt-get update
apt-get install -y ca-certificates curl git
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/install.sh -o /root/dboard-install.sh
bash /root/dboard-install.sh --guide
~~~

先读下面的模式与数据目录说明，再启动交互菜单：

~~~bash
bash /root/dboard-install.sh
~~~

上面 apt 命令仅用于 Ubuntu/Debian；其它系统走已具备 Docker/Compose 的容器路径或对应系统的包管理器。完整探针示例采用 Ubuntu 24.04 独立版。新手首次操作不要加 --yes 跳过选择。

## 1. 先选择要做什么

| 你的情况 | 选择 / 操作 | 完成范围 |
| --- | --- | --- |
| 新机器，准备装完整面板与探针 | 根安装器选 **1：独立版**，再按完整指南部署用户端、探针、Connector | 菜单 1 本身只安装面板 |
| 只装容器面板，已有 Docker 运维经验 | 选 **2：Docker 版** | 面板容器；探针对接还需要核对网络命名空间 |
| 已有面板，只新增 DUI | 选 **3：仅安装 DUI-Gateway** | API 网关；不会自动修改用户端配置 |
| 已有 DUI，只升级程序 | 使用 gateway/install.sh 的 **upgrade** | 保留 gateway.env 和 AES key |
| 查看安装状态 | 选 **4：查看 DBoard 状态** | 只读检查面板/DUI；不代表探针或代理业务已验收 |
| 升级已有面板 | 备份后选择与原来相同的 **1 或 2** | 已安装标记有效时复用数据；不重新生成管理员 |
| 独立版与 Docker 互换 | 维护窗口、完整备份后再选择另一模式 | 会停止原模式，切换 Redis 配置；不是无中断操作 |
| 给一台节点机器装 Agent | 在后台服务器管理复制该机器的命令 | **不要在节点机器运行根安装器** |

只想先看提示、不执行安装：

~~~bash
bash /root/dboard-install.sh --guide
~~~

此文件须先按[完整指南](installation.md)下载；--guide 和 --help 不需要 root，不安装依赖、不启停服务。

## 2. 执行前准备表

| 项目 | 填什么 / 如何确认 |
| --- | --- |
| 执行机器 | 面板安装命令在面板服务器；Agent 命令在对应节点服务器；构建可在单独构建机 |
| 系统 | 完整示例使用 Ubuntu 24.04 + systemd；独立版脚本接受 Ubuntu/Debian，但还需仓库提供 PHP 8.2+ 与编译依赖 |
| 数据目录 | 默认 /opt/dboard/shared；已有业务必须指向原来的目录。自定义用 --data-dir，后续状态/更新也带同一参数 |
| 数据状态 | 脚本只凭该目录 .env 的 INSTALLED=1 或 INSTALLED=true 判定已安装，不能凭数据库文件存在就自动识别 |
| 域名 | 分别记录用户端、面板、可选 DUI、探针域名；证书与反代不由根安装器自动完成 |
| 已有软件 | 确认 1Panel/OpenResty、Redis、Docker 的端口和网络；不要让不同服务争用同一个端口 |
| 容量与版本 | 确认磁盘/内存足够编译依赖，记录源码提交和镜像版本；不要把 main、镜像 latest 和 Agent Release 当成同一个版本 |
| 备份 | 已有系统保存数据库、.env/APP_KEY、插件、探针身份、网关配置与证书；见完整备份表 |

可先做以下只读检查：

~~~bash
cat /etc/os-release
uname -m
df -h /opt /tmp
free -h
ss -ltn
~~~

如果数据库已有业务但 .env 的已安装标记丢失，应先恢复正确配置。看到“是否清空数据库”时，已有业务选择退出，不用清空来解决安装失败。

## 3. 菜单 1：独立版，每一步实际做什么

### 启动这条路径

完成第 0 节后，直接指定独立版，默认使用 SQLite：

~~~bash
bash /root/dboard-install.sh --mode native --no-gateway
~~~

--no-gateway 先跳过可选网关，待面板和 HTTPS 验收后按第 5 节安装。若希望面板完成时询问 DUI，去掉此参数。已有系统先看第 7 节，不直接套用新装初始化步骤。

| 阶段 | 屏幕提示 / 自动动作 | 你应怎么做 | 通过标志 |
| --- | --- | --- | --- |
| 1/6 数据与模式 | 数据目录、是否识别到已安装；可能询问停止 Docker | 路径与原部署一致才继续；普通升级不要切模式 | 已有业务被识别为更新 |
| 2/6 依赖 | 安装 PHP/Composer，编译 Swoole 和 Redis | 等待编译；报错看本阶段输出，不把失败当完成 | PHP 能加载 Swoole，Redis 版本校验通过 |
| 3/6 发布目录 | 暂停原应用服务，拉取 main，安装 Composer 依赖，切换 current | 此阶段开始可能中断面板，安排维护窗口 | 发布目录和 shared 链接创建成功 |
| 4/6 初始化或更新 | 新装运行 xboard:install，旧数据运行 xboard:update | 按下方数据库/管理员说明填写 | 数据初始化或迁移结束 |
| 5/6 启动与本机检查 | 启动 5 个服务，请求 guest/comm/config | 出现失败先查对应日志 | HTTP 检查通过，服务 active |
| 6/6 下一步 | 显示面板 HTTP/WS 上游 | 配置 HTTPS 和真实域名，再验证后台 | 通过域名正常登录 |

**默认不会询问数据库类型**：根安装器默认 --database sqlite，直接选择 SQLite，并预设本机 Redis。只有管理员邮箱可能需要输入。不要等一个不会出现的数据库选择框。

需要已有 MySQL/PostgreSQL 时，开始前明确指定：

~~~bash
bash /root/dboard-install.sh --mode native --database interactive --no-gateway
~~~

| 初始化问题 | 默认或选择 | 提醒 |
| --- | --- | --- |
| 数据库类型 | 默认 SQLite；interactive 时可选 MySQL/PostgreSQL | MySQL/PostgreSQL 服务、数据库、账号须提前准备 |
| 数据库地址 | 运行 PHP 的环境能够访问的地址 | Docker 里的 127.0.0.1 通常不是宿主机数据库 |
| 端口/库名/用户/密码 | 填实际已有数据库配置 | 使用项目专用数据库；PostgreSQL 还需 PHP pdo_pgsql，根安装器未安装 php-pgsql |
| 已有表，是否清空 | 新空库不应遇到；已有业务退出 | MySQL/PostgreSQL 选择不清空会重新询问配置，Ctrl+C 可退出 |
| Redis | 根安装器预设独立版 127.0.0.1:6379 | 数据库选 interactive 不会改成外部 Redis 向导；外部 Redis 用手工部署并核对更新行为 |
| 管理员账号 | 填自己的邮箱，可用 --admin 提前传入 | 密码由程序生成；记录输出，不是输入邮箱密码 |
| 安装结束 | 显示管理员密码与后台路径 | 后台路径由 APP_KEY 派生，不固定为 /666；登录后修改初始密码 |

若选择 PostgreSQL，在已确认的 PHP 版本中安装并启用匹配的 pdo_pgsql 扩展，再运行初始化。不要以此文宣称默认自动安装覆盖所有外部数据库/Redis 组合。

**--yes 的含义**：采用安装器确认项的默认值，不是“全部同意”。它会接受默认 yes 的模式切换，默认跳过 DUI；新装必须同时指定 --admin，且底层数据库异常确认仍可能需要处理。首次体验建议交互运行。


### 面板装完后的核对

~~~bash
systemctl is-active dboard-octane dboard-horizon dboard-ws dboard-scheduler dboard-redis
cd /opt/dboard/current
sudo -u www-data php artisan migrate:status
curl -fsS http://127.0.0.1:7001/api/v1/guest/comm/config
~~~

五个服务应为 active，迁移无待执行项，接口返回 JSON。失败时按对应阶段查询日志，例如：

~~~bash
journalctl -u dboard-octane -n 80 --no-pager
journalctl -u dboard-redis -n 80 --no-pager
~~~

本机通过后，在 1Panel 或现有反代中为面板域名配置 HTTPS，HTTP 上游 127.0.0.1:7001、WS 上游 127.0.0.1:8076。完整 location 配置见[安装指南第 2 节](installation.md)。使用安装输出的实际后台路径登录，修改初始密码，再部署用户端。

## 4. 菜单 2：Docker 版

从第一条命令开始，按[Docker 分方式安装](docker-installation.md)执行；包括自动单容器和四种手工 Compose 选择。

| 阶段 | 实际动作 | 提醒 / 验收 |
| --- | --- | --- |
| 1/5 数据目录 | 创建/复用 shared，必要时停用 native 服务 | 先备份，勿误切部署模式 |
| 2/5 Docker | 检查 Engine/Compose，不存在时安装 | 1Panel 已有 Docker 时复用；确认 Compose V2 可用 |
| 3/5 镜像与配置 | 获取默认 Compose，拉取 GHCR latest；拉取失败才从 main 构建 | 拉取成功不等于镜像含最新提交；查看 Actions、镜像 revision。脚本会重写 Compose 文件和其 .env，先保存定制配置 |
| 4/5 数据 | 新装初始化，已安装时跳过重新初始化 | 默认 SQLite + 容器内 Unix socket Redis |
| 5/5 启动 | docker compose up -d 与本机 HTTP 检查 | 容器启动时尝试 xboard:update；失败可能仍继续启动，必须查看日志和 migrate:status |

默认 Compose 的端口写法是 **7001:7001，会向宿主机所有接口发布**，不是只监听 127.0.0.1。宿主反代场景可在安装后将其改成 127.0.0.1:7001:7001，再重新创建容器；下次运行根安装器会重写默认 Compose，需复核定制项。

~~~bash
cd /opt/dboard/docker
docker compose ps
docker compose logs --tail=100 dboard
docker compose exec -u www dboard php artisan migrate:status
~~~

同机外部 Connector 经 Docker bridge 访问不保证通过面板的真实回环检查。完整探针部署优先按独立版路径；Docker 需另行设计 Connector 所在网络命名空间，见[安装指南](installation.md)。

## 5. DUI 选 Y 还是 N

面板完成后会询问“是否同时安装 DUI-Gateway”。回车默认 **N**；--no-gateway 不询问。

| 选择 | 适用情况 | 之后还要做什么 |
| --- | --- | --- |
| N | 先跑通直接 API，或已有正常 DUI | 配置用户端直连 API；已有 DUI 继续复用原配置 |
| Y | 首次部署用户 API 加密路径层 | 配置 HTTPS、来源域名、AES key 与用户端 runtime-config.js |
| 菜单 3 | 独立新增或明确重配 DUI | 根安装器调用 install，会写配置并生成/写入 AES key |
| 已有 DUI 升级 | 使用下面的 upgrade | 保留配置；核对 /healthz 和用户端请求 |

~~~bash
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/gateway/install.sh -o /root/dui-install.sh
bash /root/dui-install.sh upgrade
~~~

DUI 后端地址默认 http://127.0.0.1:7001，指的是**网关所在主机**；分机部署应填写网关能访问的面板 HTTPS 地址。默认 3939 监听全部 IPv4 接口，需限制公网直连。--gateway-port 是根安装器参数，gateway/install.sh 自身使用 --port。

重新运行 install 会覆盖 gateway.env；没有显式指定 AES key 时会生成新值。普通程序升级不要选重装。同步前端 key、来源限制、订阅和支付回调配置后才算可用。


### 只有 DUI 时，从这里开始

1. 在**网关主机**完成第 0 节下载。确认已有面板，网关到面板的地址可达。
2. 首次安装执行下列命令，按提示填写后端；同机默认 http://127.0.0.1:7001，分机填实际面板 HTTPS 地址。

~~~bash
bash /root/dboard-install.sh --mode gateway
~~~

指定后端与端口的等价方式（示例域名需替换）：

~~~bash
bash /root/dboard-install.sh --mode gateway --gateway-backend https://panel.example.com --gateway-port 3939
~~~

3. 保存生成的 AES key，不把安装输出原样发到公开群。配置位于 /etc/DUI-Gateway/gateway.env，服务名 DUI-Gateway。
4. 检查服务和本机接口：

~~~bash
systemctl is-active DUI-Gateway
curl -fsS http://127.0.0.1:3939/healthz
~~~

5. 用自己的网关域名配置 HTTPS，反代到网关主机的 3939；限制该上游端口直接暴露。1Panel 容器反代按实际网络填写地址。
6. 在用户端 runtime-config.js 填网关域名、同一 AES key、/dui/gw；设置允许的网页来源，必要时配置订阅和支付通知直通路径。
7. 浏览器核对登录、商店报价、下单与所用的支付回调/订阅；/healthz 成功只代表网关进程可响应。
8. 以后只升级程序时，使用本节上方的 upgrade 命令；不要重新运行 install 改掉密钥。

## 6. 根安装器结束后的剩余步骤

依次完成[完整安装指南](installation.md)对应章节；每一行通过后再继续下一行。

| 顺序 | 在哪里做 | 通过标志 | 没通过时看什么 |
| --- | --- | --- | --- |
| 1 面板 HTTPS | 反代 / 1Panel | 域名登录后台，/ws 可转发 | 上游可达性、证书、实际反代容器网络 |
| 2 用户端 | 构建机 + 静态站点 | 登录、商店、报价和订单正常 | runtime-config.js、CORS、DUI 配置和缓存 |
| 3 构建服务端 | Linux 构建机 | Dashboard/Connector 哈希与目标架构正确 | Go 版本、CGO、依赖下载 |
| 4 探针初始化 | 探针主机 + SSH 隧道 | 初始密码已修改 | 回环监听、真实 IP 头、数据库目录权限 |
| 5 桥接/入口限制 | 探针配置 | 未授权 /dashboard/ 返回 404 | 配套 key、bridge.env、新版 Dashboard |
| 6 探针公网入口 | 反代 / DNS / Cloudflare | 首页、gRPC、WebSocket 都可用 | 443、HTTP/2、gRPC 开关、无首页错误跳转 |
| 7 Agent 文件 | 探针 artifacts 目录 | 版本、文件名、SHA256SUMS 相符 | 下载失败、目录权限、版本不匹配 |
| 8 Connector | 面板所在环境 | 后台检查连接通过 | 配置密钥、真实回环来源、服务日志 |
| 9 第一台 Agent | 目标节点机器 | 监控及节点通道都在线 | 命令是否过期、下载、注册、gRPC/WS |
| 10 业务验证 | 后台 + 测试用户 | 实际订阅连接、流量、报价和订单正常 | 权限组、端口、节点绑定、支付/队列 |

Agent 注册命令约 15 分钟有效；过期重新生成。出现旧 DBoard-node 提示时，只有明确迁移对应单实例才加 --takeover；原厂哪吒与整合 Agent 使用独立路径。不要在多台机器粘贴同一个身份命令。

## 7. 失败时怎么继续

- 先记录失败阶段和最后的错误；日志里有密码/Token 时先脱敏。
- 根安装器没有完整的事务回滚：中途失败可能已停旧服务、切换 current 或写入配置。先确认当前服务与链接，再依据备份恢复；不要把重跑等同于自动回滚。
- 看见数据库清空询问、意外新装、错误数据目录时，退出并恢复正确配置。
- 面板本机 HTTP 失败先查 Octane/Redis，域名 502 再查反代，机器不在线再查探针/Connector/Agent；不要跨过未通过的层继续安装。
- Docker 日志中的 xboard:update failed 必须处理，即使容器是 Up 或 HTTP 200。
- 已显示“生成成功”但手机页面报错时，先搜索用户邮箱确认是否已经创建，避免重复添加。刷新新版后台资源后再检查。
- Agent 接管失败会尝试恢复原服务；仍需在目标机器确认业务恢复。[完整排错与恢复](operations.md)记录了检查顺序。

## 8. 安装记录

记录：安装时间、机器角色、数据目录、部署模式、源码提交/镜像 revision、Agent 版本、域名、证书续期方式、备份位置、每层验收结果与待完成项。密码、APP_KEY、AES key、控制密钥单独保管，不写入公开安装记录。


## 9. 菜单 4：仅查看状态

下载脚本后，在现有面板主机执行：

~~~bash
bash /root/dboard-install.sh --mode status
~~~

自定义数据目录需传同一条 --data-dir。输出依次列出已安装标记、native current/commit、服务启用和运行状态、Docker 容器、DUI 服务。未使用的部署模式显示 inactive/not configured 可以是正常情况。

继续核对你实际使用的模式。探针用 systemctl status nezha-dashboard probe-connector，节点在对应机器用 systemctl status nezha-integrated-agent；面板菜单 4 不查询远程机器是否正常代理。

## 10. 升级与切换：第一步就保留原配置

1. 在原主机记录部署模式、真实数据目录、current 指向/镜像版本。
2. 按[运维指南](operations.md)备份数据与密钥；确认备份可读取，不把下载新代码当备份。
3. 下载最新根安装器，先使用 --mode status 核对原目录是否识别为 installed。
4. 原独立版执行 --mode native；原默认 Docker 执行 --mode docker；使用 --no-gateway 避免重复重配已有 DUI。
5. 自定义 Docker 按[Docker 手工更新](docker-installation.md)保留 Compose；DUI 单独用 upgrade。
6. 根安装器会调整 Redis 地址为该模式的默认值。外部 Redis 部署不要直接套用，应按自己的服务配置手工更新。
7. 服务启动后核对迁移、管理页面、报价/订单、监控、节点通道和代理业务。
8. 只有主动跨模式迁移时才选择另一种模式；看到停止原服务的确认，核对备份与维护窗口再输入 Y。

没有成功识别 installed、出现清空数据库询问或运行时报错时，不继续向后点击。根安装器不替你恢复失败前所有文件与服务。

