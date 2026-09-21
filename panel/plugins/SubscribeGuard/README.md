# SubscribeGuard 订阅守卫

用于 Xboard 的订阅链接访问守卫插件。

## 功能

- 拦截浏览器、爬虫、扫描器、脚本工具访问订阅接口。
- 默认仅允许 Clash、Clash Meta、Stash、sing-box、v2rayN、v2rayNG、NekoBox、Shadowrocket、Quantumult X、Surge、Loon、Surfboard 等常见订阅客户端。
- 非法访问直接返回 `404` 空响应，避免暴露订阅接口存在。
- 支持 CDN / 反代真实 IP 识别。
- 支持 IP 白名单，白名单 IP 不受 UA 拦截。
- 使用 Laravel `Log::warning()` 写入应用日志。
- 内置缓存去重，避免同一来源高频刷爆日志。
- 提供后台日志 API 和日志查看页面，可查看时间、IP、UA、命中原因、请求路径、请求方法。

## 安装

插件目录：

```text
plugins/SubscribeGuard
```

在 Xboard 后台插件管理中安装并启用插件，或使用已有插件管理命令安装 `subscribe_guard`。

启用后插件会监听 Xboard 内置钩子：

```php
client.subscribe.before
```

因此只拦截订阅接口，不影响普通站点页面、登录、支付回调等其他业务接口。

## 配置说明

### enabled

是否启用拦截。

### allow_unknown_user_agent

是否允许未知 UA。

建议保持默认 `false`，即未命中订阅客户端白名单时全部返回 404。

### trusted_proxies

可信 CDN / 反代来源 IP。

只有直接来源 IP 或 Laravel 识别到的来源 IP 命中该配置时，插件才会读取 `CF-Connecting-IP`、`X-Forwarded-For` 等真实 IP 请求头。

默认：

```json
["127.0.0.1", "::1"]
```

插件优先使用 Laravel / OpenResty 已经校验并还原的 `request()->ip()`。只有直接连接来源命中 `trusted_proxies` 时，才会回退读取 `CF-Connecting-IP`、`X-Forwarded-For` 等请求头。

不要将该配置设置为 `["*"]`，否则客户端可以伪造转发请求头干扰 IP 判断。

如果站点通过其它可信 Nginx、负载均衡或内网代理接入，应填写明确的代理出口 IP 或 CIDR，例如：

```json
["172.16.0.0/12", "10.0.0.0/8", "127.0.0.1"]
```

### ip_headers

真实 IP 头读取顺序。插件会将后台配置与内置默认头合并读取，即使后台旧配置缺少新增请求头，也会尝试识别。

对于 `X-Forwarded-For` / `Forwarded` 这类多 IP 头，插件会优先取第一个不属于 `trusted_proxies` 的 IP，避免把中间 CDN / 反代 IP 当成客户端 IP。

注意：`X-Real-IP`、`X-Client-IP`、`X-Cluster-Client-IP` 属于泛用反代头，很多 Nginx / CDN 会把它们写成上一层 CDN IP。插件已将这些泛用头降为低优先级，并且当泛用头的值等于直连来源 IP / Laravel Request IP 时会跳过，优先使用 Cloudflare、Azure、Fastly、Fly、Vercel 专用头或 `X-Forwarded-For` 转发链。

默认：

```json
[
  "CF-Connecting-IP",
  "True-Client-IP",
  "X-Real-IP",
  "X-Forwarded-For",
  "X-Client-IP",
  "X-Cluster-Client-IP",
  "Forwarded"
]
```

### ip_whitelist

IP 白名单，支持单 IP 和 CIDR：

```json
[
  "1.2.3.4",
  "10.0.0.0/8",
  "2001:db8::/32"
]
```

### allowed_user_agents

允许访问订阅的 UA 关键词。大小写不敏感，命中任一关键词即放行。

### blocked_user_agents

禁止访问订阅的 UA 关键词。大小写不敏感，命中后返回 404。

注意：允许列表优先级高于禁止列表。

### log_dedup_ttl

日志去重时间，单位秒。

相同 `IP + UA + 命中原因 + 请求路径` 在该时间内只写入一次 Laravel 日志和后台日志文件。

### max_log_entries

后台日志文件最多保留条数。

日志文件位置：

```text
storage/app/subscribe-guard/blocked.jsonl
```

## 后台日志接口

接口需要 Xboard 管理员 Sanctum Bearer Token。

参考 Xboard 后台插件页面的写法，接口挂在管理后台动态安全路径下：

```text
/api/v2/{secure_path}/subscribe-guard
```

其中 `{secure_path}` 为 Xboard 管理后台路径，例如后台地址是 `/admin_xxx`，则接口前缀为：

```text
/api/v2/admin_xxx/subscribe-guard
```

### 获取日志

```http
GET /api/v2/{secure_path}/subscribe-guard/logs?limit=100
Authorization: Bearer <admin-token>
Accept: application/json
```

返回字段包含：

- `time`：记录时间
- `ip`：识别后的真实客户端 IP
- `remote_ip`：Laravel 看到的直接来源 IP
- `user_id`：订阅链接归属用户 ID，未匹配到时为 `null`
- `user_email`：订阅链接归属用户邮箱，未匹配到时为 `null`
- `ua`：User-Agent
- `reason`：命中原因
- `path`：请求路径
- `method`：请求方法
- `query`：脱敏后的查询参数

### 清空日志

```http
POST /api/v2/{secure_path}/subscribe-guard/clean-logs
Authorization: Bearer <admin-token>
Accept: application/json
```

同时保留兼容接口：

```http
GET /api/v1/plugin/subscribe-guard/logs?limit=100
DELETE /api/v1/plugin/subscribe-guard/logs
```

## 后台日志页面

启用插件后，在 Xboard 管理后台登录状态下访问：

```text
/{secure_path}/subscribe-guard
```

页面会自动读取 Xboard 管理后台的 `localStorage.XBOARD_ACCESS_TOKEN`，与后台页面保持一致，无需手动填写 Token。

## 拦截结果

被拦截请求会返回：

```http
HTTP/1.1 404 Not Found
Content-Type: text/plain
```

响应体为空。

## 命中原因示例

- `empty_user_agent`：UA 为空。
- `blocked_user_agent:mozilla`：命中浏览器 UA。
- `blocked_user_agent:bot`：命中爬虫 UA。
- `unknown_user_agent`：既不是允许客户端，也未命中禁止关键词，按未知 UA 拦截。
- `ip_whitelist`：IP 白名单放行。
- `allowed_user_agent:clash`：订阅客户端 UA 放行。

## 测试示例

浏览器 UA 应返回 404：

```bash
curl -I -A "Mozilla/5.0 Chrome/120" "https://example.com/api/v1/client/subscribe?token=xxx"
```

Clash UA 应正常返回订阅内容：

```bash
curl -I -A "clash-verge/v1.7.7" "https://example.com/api/v1/client/subscribe?token=xxx"

[executed on device: HK33-2 (c75ff8bf-126b-4700-9b68-a227f325f9f3)]