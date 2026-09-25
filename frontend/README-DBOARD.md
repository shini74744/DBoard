# DBoard 用户端

本目录是 DBoard 配套的 Vue 用户网站，包含商店、多套餐卡片、购买确认、订单与订阅。源码从 [PangHu-Code/EZ-Theme](https://github.com/PangHu-Code/EZ-Theme) 的 6e24d2f 导入并持续维护；保留上游署名和许可。

## 使用与部署文档

- [用户端完整流程](../docs/user-guide.md)
- [后台报价、订单与开通流程](../docs/admin-workflows.md)
- [用户端与 API 网关逐步安装](../docs/installation.md)
- [更新与故障排查](../docs/operations.md)

## 构建

在构建机执行：

~~~bash
cd frontend
npm ci
cp public/runtime-config.example.js public/runtime-config.js
~~~

仅首次配置时复制示例；已有运行配置不覆盖。编辑 runtime-config.js，选择直接 API 或 DUI 中间层，填写自己的域名和配置，然后：

~~~bash
npm run build
~~~

将 dist/ 发布到静态站点，配置 history 路由回退。后续更新保留生产 runtime-config.js 和站点图片，运行配置不进入 Git。

## 发布后验收

- 登录、用户须知、商店套餐与有效周期正确。
- 新开新增独立套餐，续费选择明确的目标套餐。
- 原卡片续费采用专属周期价格；商店新开/续费采用商店标价。
- 下单报价失败时提示错误，不能误按 0 元提交。
- 订单状态、金额和套餐卡片一致。
- 多套餐导入/合并订阅正常，代理客户端能够刷新订阅并连接。
