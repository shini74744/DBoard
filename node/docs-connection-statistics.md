# 节点连接统计

- Xray dispatcher 和 sing-box tracker 在连接/会话建立及关闭时记录元数据，不记录载荷或用户凭据。
- 列表为最后一次上报的当前来源 IP 去重数、TCP 连接数、UDP 会话数。
- 明细按来源 IP、TCP 目标、UDP 目标分别汇总最近 24 小时的建立次数、当前数量及累计时长。并发时长分别累加，UDP 时长是会话时长。
- 历史按一分钟桶滚动，最旧一分钟的时长按窗口比例裁剪；窗口边缘的次数有一分钟精度。
- 历史保存在每个节点 ConfigDir 下 connection-history-ID.json（0600），重启只恢复历史，不恢复活动连接。最后一次保存后的崩溃间隔无法补录。
- 历史聚合最多 100000 项，每类上报最多 300 行，优先活动连接和高频目标。截取会明确标记，列表的当前总数仍完整。
- 面板缓存保留 25 小时，上报超过 3 个上报周期（至少 180 秒）后标记过期，当前计数显示“—”。
- 未升级或尚未上报的节点显示“— / 待升级”，不伪装成 0。
- 运营商 / ASN 来自 JC 的离线 IPtoASN 数据库，不发送用户 IP 到第三方。网络归属名称不一定等同于最终接入服务商品牌，无法识别时显示“未知”。
- 明细接口仅允许管理员访问，节点列表只提供计数摘要。

## 本地运营商库

使用公开的 IPtoASN 数据 https://iptoasn.com/ ：

1. 下载 data/ip2asn-v4-u32.tsv.gz 和 data/ip2asn-v6.tsv.gz。
2. 执行 panel/scripts/build-ip-asn.py，将两个压缩文件及输出 SQLite 路径作为参数。
3. 将生成的 ip-asn.sqlite 放入面板 storage/app/，使 www-data 可读。更新使用原子替换；查询缓存会随文件更新时间切换。

所有前端资源变动通过 panel/resources/js/patch-dboard-node-connections.py 重放到预编译管理端 bundle。
