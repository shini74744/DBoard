// DUI-Gateway browser client.
// Compatible with the JC middleware path format:
// AES-CBC + PKCS7 -> Base64 -> Base64 again, with a 16-character X-IV header.

export const DEFAULT_ALIASES = {
  "/guest/comm/config": "/g/conf",
  "/user/comm/config": "/c/conf",
  "/passport/auth/login": "/auth/login",
  "/passport/auth/register": "/auth/reg",
  "/passport/auth/forget": "/auth/forget",
  "/passport/auth/token2Login": "/auth/token2Login",
  "/passport/comm/sendEmailVerify": "/mail/verify",
  "/user/checkLogin": "/auth/check",
  "/user/info": "/u/info",
  "/user/changePassword": "/u/pwd",
  "/user/resetSecurity": "/u/reset",
  "/user/update": "/u/update",
  "/user/redeemgiftcard": "/u/gift",
  "/user/gift-card/redeem": "/u/gift2",
  "/user/getActiveSession": "/u/session",
  "/user/getSubscribe": "/sub/get",
  "/user/getStat": "/stat/get",
  "/user/stat/getTrafficLog": "/traffic/log",
  "/user/plan/fetch": "/plan/list",
  "/user/coupon/check": "/coup/check",
  "/user/order/save": "/order/new",
  "/user/order/fetch": "/order/list",
  "/user/order/detail": "/order/detail",
  "/user/order/cancel": "/order/cancel",
  "/user/order/checkout": "/order/pay",
  "/user/order/check": "/order/check",
  "/user/order/getPaymentMethod": "/pay/methods",
  "/user/server/fetch": "/node/list",
  "/user/ticket/fetch": "/ticket/list",
  "/user/ticket/save": "/ticket/new",
  "/user/ticket/reply": "/ticket/reply",
  "/user/ticket/close": "/ticket/close",
  "/user/ticket/withdraw": "/withdraw",
  "/user/invite/fetch": "/inv/info",
  "/user/invite/save": "/inv/new",
  "/user/invite/details": "/inv/detail",
  "/user/transfer": "/comm/transfer",
  "/user/notice/fetch": "/notice/list",
  "/user/knowledge/fetch": "/knowledge/list",
};

function getCrypto() {
  if (globalThis.crypto?.subtle) return globalThis.crypto;
  throw new Error("Web Crypto API is required");
}

function textBytes(value) {
  return new TextEncoder().encode(value);
}

function bytesToBase64(bytes) {
  if (typeof btoa === "function") {
    let binary = "";
    for (const b of bytes) binary += String.fromCharCode(b);
    return btoa(binary);
  }
  if (typeof Buffer !== "undefined") {
    return Buffer.from(bytes).toString("base64");
  }
  throw new Error("No Base64 encoder available");
}

function asciiToBase64(value) {
  if (typeof btoa === "function") return btoa(value);
  if (typeof Buffer !== "undefined") return Buffer.from(value, "ascii").toString("base64");
  throw new Error("No Base64 encoder available");
}

export function rewriteAlias(rawURL, aliases = DEFAULT_ALIASES) {
  const q = rawURL.indexOf("?");
  const path = q >= 0 ? rawURL.slice(0, q) : rawURL;
  const query = q >= 0 ? rawURL.slice(q) : "";

  if (aliases[path]) return aliases[path] + query;

  let best = "";
  for (const full of Object.keys(aliases)) {
    if (path.startsWith(full + "/") && full.length > best.length) best = full;
  }
  if (!best) return rawURL;
  return aliases[best] + path.slice(best.length) + query;
}

export function createIV(storage = globalThis.localStorage) {
  const existing = storage?.getItem?.("dui_gateway_iv");
  if (existing && existing.length === 16) return existing;

  const bytes = new Uint8Array(8);
  getCrypto().getRandomValues(bytes);
  const iv = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
  storage?.setItem?.("dui_gateway_iv", iv);
  return iv;
}

export async function encryptGatewayPath(rawURL, key, iv = createIV()) {
  const keyBytes = textBytes(key);
  if (![16, 24, 32].includes(keyBytes.length)) {
    throw new Error("DUI-Gateway AES key must be 16, 24, or 32 bytes");
  }
  const ivBytes = textBytes(iv);
  if (ivBytes.length !== 16) {
    throw new Error("DUI-Gateway IV must be 16 characters");
  }

  const cryptoKey = await getCrypto().subtle.importKey(
    "raw",
    keyBytes,
    { name: "AES-CBC" },
    false,
    ["encrypt"],
  );

  const aliased = rewriteAlias(rawURL);
  const encrypted = await getCrypto().subtle.encrypt(
    { name: "AES-CBC", iv: ivBytes },
    cryptoKey,
    textBytes(aliased),
  );
  const innerBase64 = bytesToBase64(new Uint8Array(encrypted));
  return asciiToBase64(innerBase64);
}

export async function buildGatewayRequest(rawURL, options) {
  const {
    gatewayURL,
    key,
    pathPrefix = "/dui/gw",
    iv = createIV(),
  } = options;

  if (!gatewayURL) throw new Error("gatewayURL is required");
  if (!key) throw new Error("key is required");

  const token = await encryptGatewayPath(rawURL, key, iv);
  const base = gatewayURL.replace(/\/$/, "");
  const prefix = pathPrefix.startsWith("/") ? pathPrefix : "/" + pathPrefix;

  return {
    url: base + prefix.replace(/\/$/, "") + "/" + token,
    headers: { "X-IV": iv },
  };
}

// Axios usage:
// axios.interceptors.request.use(createAxiosGatewayInterceptor({
//   gatewayURL: "https://gateway.example.com",
//   key: "0123456789abcdef",
// }));
export function createAxiosGatewayInterceptor(options) {
  return async function duiGatewayInterceptor(request) {
    if (!request?.url || /^https?:\/\//i.test(request.url)) return request;

    const built = await buildGatewayRequest(request.url, options);
    request.baseURL = "";
    request.url = built.url;
    request.headers = request.headers || {};
    request.headers["X-IV"] = built.headers["X-IV"];
    return request;
  };
}
