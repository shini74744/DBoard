

const pathMappings = {
  '/guest/comm/config': '/g/conf',
  '/user/comm/config': '/c/conf',

  '/passport/auth/login': '/auth/login',
  '/passport/auth/register': '/auth/reg',
  '/passport/auth/forget': '/auth/forget',
  '/passport/auth/token2Login': '/auth/token2Login',
  '/passport/comm/sendEmailVerify': '/mail/verify',
  '/user/checkLogin': '/auth/check',

  '/user/info': '/u/info',
  '/user/changePassword': '/u/pwd',
  '/user/resetSecurity': '/u/reset',
  '/user/update': '/u/update',
  '/user/redeemgiftcard': '/u/gift',
  '/user/getActiveSession': '/u/session',

  '/user/getSubscribe': '/sub/get',
  '/user/getStat': '/stat/get',
  '/user/stat/getTrafficLog': '/traffic/log',

  '/user/plan/fetch': '/plan/list',
  '/user/coupon/check': '/coup/check',
  '/user/order/save': '/order/new',
  '/user/order/fetch': '/order/list',
  '/user/order/detail': '/order/detail',
  '/user/order/cancel': '/order/cancel',
  '/user/order/checkout': '/order/pay',
  '/user/order/check': '/order/check',
  '/user/order/getPaymentMethod': '/pay/methods',

  '/user/server/fetch': '/node/list',

  '/user/ticket/fetch': '/ticket/list',
  '/user/ticket/save': '/ticket/new',
  '/user/ticket/reply': '/ticket/reply',
  '/user/ticket/close': '/ticket/close',
  '/user/ticket/withdraw': '/withdraw',

  '/user/invite/fetch': '/inv/info',
  '/user/invite/save': '/inv/new',
  '/user/invite/details': '/inv/detail',
  '/user/transfer': '/comm/transfer',

  '/user/notice/fetch': '/notice/list',

  '/user/knowledge/fetch': '/knowledge/list'
};


export function mapApiPath(originalPath) {
  try {
    if (!window.EZ_CONFIG || !window.EZ_CONFIG.API_MIDDLEWARE_ENABLED) {
      return originalPath;
    }

    const [path, query] = originalPath.split('?');

    if (pathMappings[path]) {
      return query ? `${pathMappings[path]}?${query}` : pathMappings[path];
    }

    let matchedPrefix = '';
    let mappedPath = '';

    Object.keys(pathMappings).forEach(prefix => {
      if (path.startsWith(prefix) && prefix.length > matchedPrefix.length) {
        matchedPrefix = prefix;
        mappedPath = pathMappings[prefix];
      }
    });

    if (matchedPrefix) {
      const remainingPath = path.slice(matchedPrefix.length);
      const newPath = mappedPath + remainingPath;
      return query ? `${newPath}?${query}` : newPath;
    }

    return originalPath;
  } catch (error) {
    console.error('路径映射错误:', error);
    return originalPath;
  }
}


export function parseQueryParams(url) {
  try {
    const queryString = url.split('?')[1];
    if (!queryString) return {};

    const params = {};
    queryString.split('&').forEach(param => {
      const [key, value] = param.split('=');
      params[key] = decodeURIComponent(value || '');
    });

    return params;
  } catch (error) {
    console.error('查询参数解析错误:', error);
    return {};
  }
}

export default {
  mapApiPath,
  parseQueryParams
};
