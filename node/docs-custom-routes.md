# Custom Routes

## Quick Example

```json
{
  "custom_route_rules": [
    {
      "name": "user-11-example-via-b",
      "match": {"user_ids": [11], "domain_suffixes": ["example.com"]},
      "action": {"type": "route", "target": "exit-b"}
    },
    {
      "name": "default-via-a",
      "action": {"type": "route", "target": "exit-a"}
    },
    {
      "name": "direct-example",
      "match": {"domain_suffixes": ["example.com"]},
      "action": {"type": "direct"}
    },
    {
      "name": "block-ads",
      "match": {"domains": ["ads.example.com"]},
      "action": {"type": "block"}
    },
    {
      "name": "route-warp",
      "match": {"ip_cidrs": ["1.1.1.0/24"], "ports": ["80", "443"]},
      "action": {"type": "route", "target": "warp-out"}
    }
  ]
}
```

## Match Conditions

| Condition | Description | Example |
|-----------|-------------|---------|
| `user_ids` | Authenticated website user IDs; AND with domain and other conditions | `[11]` |
| `domains` | Exact domain match | `["api.example.com"]` |
| `domain_suffixes` | Suffix match | `["example.com"]` |
| `ip_cidrs` | IP CIDR ranges | `["10.0.0.0/8"]` |
| `ports` | Port (single or range) | `["443", "8000-9000"]` |
| `networks` | Protocol | `["tcp"]` or `["udp"]` |
| `source_cidrs` | Source IP CIDR | `["192.168.1.0/24"]` |
| `source_ports` | Source port | `["1024-65535"]` |

## Action Types

| Action | Description |
|--------|-------------|
| `{"type": "direct"}` | Direct connection, bypass proxy |
| `{"type": "block"}` | Block connection |
| `{"type": "route", "target": "tag"}` | Route to specified outbound (by tag) |

## Application Order

1. Structured `custom_route_rules` in listed order (first match wins). Put user and domain rules before a general exit rule.
2. Raw `custom_routes`
3. Built-in blocklist rules
4. Panel routes

## Kernel Compatibility

| Feature | Xray | Sing-box |
|---------|------|----------|
| All match conditions, including `user_ids` | ✅ | ✅ |
| direct / block / route | ✅ | ✅ |

## Best Practices

- **Prefer** `custom_route_rules`: cross-kernel compatible, panel-managed
- **Use** `custom_routes` only: when native features are needed (e.g., load balancing)

User scoped rules require a node binary with user route support. The panel rejects saving such rules until the node reports support and omits them from configs sent to older nodes.
