# 订阅防共享（SubscriptionGuard）

用于记录订阅拉取来源并检测多 IP 共享。

## 当前规则

- `max_unique_ips` 表示允许的最大独立 IP/网段数量，只有超过该数量才触发处罚。
  - 例如设置为 `10`：第 1～10 个允许，第 11 个开始触发。
- `enable_subnet_grouping=true` 时：
  - IPv4 按 `/24` 合并。
  - IPv6 按 `/64` 合并。
- 真实 IP 优先使用 Laravel / OpenResty 已校验后的 `request()->ip()`。
- 只有直接连接来源命中 `trusted_proxy_ips` 时，才读取 CDN / 代理转发头。
- 新订阅日志不保存明文订阅 Token，只保存 SHA-256 十六进制摘要。
- 历史日志不会被自动删除或重写。

## 推荐可信代理

如果 DBoard 只通过本机 OpenResty/Nginx 反代：

```text
127.0.0.1
::1
```

不要使用任意来源通配配置。

## 数据库

`v2_subscribe_log.token` 使用 64 字符字段保存 SHA-256 摘要。插件升级迁移会将旧的 32 字符字段扩展为 64 字符。

## 两个守卫的执行关系

1. SubscribeGuard（订阅守卫）先检查订阅客户端 UA。
2. SubscriptionGuard（订阅防共享）再统计订阅拉取 IP 并执行共享检测。

`allow_unknown_user_agent` 属于 SubscribeGuard 的策略，两插件互不替代。
