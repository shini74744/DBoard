# DUI-Gateway

DUI-Gateway 是 DBoard 的独立 API 中间层，参考 JC 现有中间件的使用方式实现。

完整系统的域名规划、用户端配置和探针区别见[安装指南](../docs/installation.md)与[后台流程](../docs/admin-workflows.md)。

当前程序默认监听 **0.0.0.0:3939**，并非仅回环。部署时限制该上游端口的公网访问，通过 HTTPS 反向代理提供服务。

## 架构

```text
前端 / 用户
    ↓ HTTPS
网关域名
    ↓ Nginx
DUI-Gateway :3939
    ↓ 解密 API 路径 / 转发
真实 DBoard 后端
```

真实后端地址只保存在 Gateway 服务器的 `/etc/DUI-Gateway/gateway.env` 中。

## 与 JC 中间件兼容的加密格式

普通 API 请求不会直接暴露真实路径。浏览器端执行：

```text
原始 API 路径
→ 可选短路径别名
→ AES-CBC + PKCS7
→ Base64
→ 再 Base64 一次
→ /dui/gw/<密文>
```

同时发送：

```http
X-IV: 16-character-IV
```

AES key 支持 16 / 24 / 32 UTF-8 字节。

> 这层 AES 的主要作用是隐藏 API 路径和降低直接探测价值。AES key 必须存在于前端，因此不能把它当作不可泄露的认证密钥。传输机密性仍然依赖 HTTPS。

## 特殊直通路径

订阅客户端和支付平台不会执行浏览器 AES，因此以下请求可以直通：

- `SUBSCRIPTION_PREFIX`，默认 `/s`
- `ALLOWED_PAYMENT_NOTIFY_PATHS`，默认 `/api/v1/guest/payment/notify`

如需兼容只允许加密 API 的旧中间件，可设置：

```env
SUBSCRIPTION_PREFIX=off
BACKEND_SUBSCRIPTION_PREFIX=off
ALLOWED_PAYMENT_NOTIFY_PATHS=
```

此时其它未加密路径统一返回 `{"error":"路径未找到"}` 和 HTTP 404。

## 安装

```bash
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/gateway/install.sh | \
  sudo bash -s -- install \
  --backend 'https://backend.example.com'
```

安装器会自动生成 16 字符 AES key，并在安装完成后显示一次。

指定 key：

```bash
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/gateway/install.sh | \
  sudo bash -s -- install \
  --backend 'https://backend.example.com' \
  --aes-key '0123456789abcdef'
```

## 配置

```env
PORT=3939
BACKEND_API_URL=https://backend.example.com
PATH_PREFIX=/dui/gw
API_PREFIX=/api/v1
SUBSCRIPTION_PREFIX=/s
BACKEND_SUBSCRIPTION_PREFIX=/s
CORS_ORIGIN=*
ALLOWED_ORIGINS=*
REQUEST_TIMEOUT=30000
ENABLE_LOGGING=false
DEBUG_MODE=false
ALLOWED_PAYMENT_NOTIFY_PATHS=/api/v1/guest/payment/notify
AES_KEY=0123456789abcdef
```

修改后执行：

```bash
systemctl restart DUI-Gateway
```

## Nginx

网关域名只需要反代到本机 3939：

```nginx
location / {
    proxy_pass http://127.0.0.1:3939;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

TLS 由 Nginx / 宝塔负责。

## 前端接入

浏览器 helper 位于 `gateway/client/dui-gateway.js`。

Axios 示例：

```js
import { createAxiosGatewayInterceptor } from './dui-gateway.js';

axios.interceptors.request.use(
  createAxiosGatewayInterceptor({
    gatewayURL: 'https://gateway.example.com',
    key: '0123456789abcdef',
    pathPrefix: '/dui/gw',
  })
);
```

如果前端本身已经支持 JC/EZ 这种 Middleware 配置，可以直接使用：

```js
API_MIDDLEWARE_ENABLED: true,
API_MIDDLEWARE_URL: 'https://gateway.example.com',
API_MIDDLEWARE_KEY: '0123456789abcdef',
API_MIDDLEWARE_PATH: '/dui/gw',
```

DUI-Gateway 的加密 URL 格式与这一模式兼容。

## DBoard 后台需要配合的两项

如果要完全隐藏后端域名：

1. **订阅域名**设置成 Gateway 域名，并保证后台的 `subscribe_path` 与 Gateway 的 `SUBSCRIPTION_PREFIX` 一致。
2. **支付通知域名**设置成 Gateway 域名；Gateway 会将允许的 notify 路径直通真实后端。

真实 DBoard 后端域名可以保持不变。

## 换 Gateway 域名

多个域名都反代同一个 DUI-Gateway 即可，真实后端无需修改。前端只需要切换 API Middleware URL；后续可增加多入口自动探测实现无感切换。

## 健康检查

`GET /healthz` 返回 DUI-Gateway 版本和运行状态。
