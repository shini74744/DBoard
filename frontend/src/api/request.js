
import axios from 'axios';
import { API_BASE_URL, getApiBaseUrl, isXiaoV2board, isXboard, CUSTOM_HEADERS_CONFIG } from '@/utils/baseConfig';
import { mapApiPath } from './utils/pathMapper';
import { getAvailableApiUrl } from '@/utils/apiAvailabilityChecker';
import { getEncrypUrl, randomIv } from "@/api/utils/encryption";

const isEncrypted = window.EZ_CONFIG &&
  window.EZ_CONFIG.API_MIDDLEWARE_ENABLED &&
  window.EZ_CONFIG.API_MIDDLEWARE_KEY &&
  window.EZ_CONFIG.API_MIDDLEWARE_KEY !== '';

const request = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
    // 只有在加密模式下才添加 X-IV 头
    ...(isEncrypted && { 'X-IV': randomIv() }),
  }
});

request.interceptors.request.use(
  config => {
      config.baseURL = getApiBaseUrl();

    if (window.EZ_CONFIG && window.EZ_CONFIG.API_MIDDLEWARE_ENABLED) {
      const originalUrl = config.url;

      const path = originalUrl.startsWith("http") ? mapApiPath(config.url) : `${window.EZ_CONFIG.API_MIDDLEWARE_PATH}/${btoa(getEncrypUrl(config.url))}`

      config.url = isEncrypted ? path : mapApiPath(config.url);

      if (process.env.NODE_ENV === 'development') {
        console.log(`API路径映射: ${originalUrl} -> ${config.url}`);
      }
    }
    else if (window.EZ_CONFIG && window.EZ_CONFIG.API_BASE_URLS &&
             Array.isArray(window.EZ_CONFIG.API_BASE_URLS) &&
             window.EZ_CONFIG.API_BASE_URLS.length > 1) {
      const availableApiUrl = getAvailableApiUrl();
      if (availableApiUrl) {
        config.baseURL = availableApiUrl;
      }
    }

    if ((isXiaoV2board() || isXboard()) && config.method === 'post' && config.data) {
      const formData = new URLSearchParams();
      for (const key in config.data) {
        if (Object.prototype.hasOwnProperty.call(config.data, key)) {
          formData.append(key, config.data[key]);
        }
      }

      config.data = formData;
      config.headers['Content-Type'] = 'application/x-www-form-urlencoded';
    }

    let authData = localStorage.getItem('auth_data');

    if (!authData) {
      try {
        const { getCookie } = require('./auth');
        authData = getCookie('auth_data');
      } catch (err) {
        const cookieAuthData = document.cookie
          .split('; ')
          .find(row => row.startsWith('auth_data='));

        if (cookieAuthData) {
          try {
            const encodedValue = cookieAuthData.split('=')[1];
            const decodedValue = decodeURIComponent(encodedValue);
            const parsedValue = JSON.parse(decodedValue);

            const { SITE_CONFIG } = require('../utils/baseConfig');
            if (parsedValue && parsedValue.site === SITE_CONFIG.siteName) {
              authData = parsedValue.value;
            }
          } catch (e) {
            authData = cookieAuthData.split('=')[1];
          }
        }
      }
    }

    if (!authData && window.authDataInStorage) {
      authData = window.authDataInStorage;
    }

    if (!authData) {
      const backupData = localStorage.getItem('cookie_auth_data');
      if (backupData) {
        try {
          const parsedValue = JSON.parse(backupData);

          const { SITE_CONFIG } = require('../utils/baseConfig');
          if (parsedValue && parsedValue.site === SITE_CONFIG.siteName) {
            authData = parsedValue.value;
          } else {
            authData = backupData;
          }
        } catch (e) {
          authData = backupData;
        }
      }
    }

    if (authData) {
      config.headers['Authorization'] = authData;
    }

    try {
      if (CUSTOM_HEADERS_CONFIG && CUSTOM_HEADERS_CONFIG.enabled && CUSTOM_HEADERS_CONFIG.headers) {
        const customHeaders = CUSTOM_HEADERS_CONFIG.headers;
        for (const headerName in customHeaders) {
          if (Object.prototype.hasOwnProperty.call(customHeaders, headerName)) {
            const headerValue = customHeaders[headerName];
            config.headers[headerName] = headerValue;
          }
        }
      }
    } catch (error) {
      console.error('应用自定义标头失败:', error);
    }

    return config;
  },
  error => {
    console.error('请求拦截器错误:', error);
    return Promise.reject(new Error('请求配置错误'));
  }
);

request.interceptors.response.use(
  response => {
    try {
      const res = response.data;

      if (res && res.message === '未登录或登陆已过期') {
        console.log('检测到登录已过期，执行登出操作');
        const { forceLogout } = require('./auth');
        forceLogout();
        window.location.href = '/#/login';
        return Promise.reject(new Error(res.message));
      }

      return res;
    } catch (err) {
      console.error('响应数据处理错误:', err);
      return Promise.reject(new Error('响应数据处理错误'));
    }
  },
  error => {
    console.error('请求错误:', error);

    if (error.response && error.response.data && error.response.data.message) {
      error.response.message = error.response.data.message;
    } else if (error.response) {
      const statusCode = error.response.status;
      switch (statusCode) {
        case 400: error.response.message = '请求参数错误'; break;
        case 401: error.response.message = '未授权，请重新登录'; break;
        case 403: error.response.message = '拒绝访问'; break;
        case 404: error.response.message = '请求的资源不存在'; break;
        default: error.response.message = `请求失败 (${statusCode})`;
      }
    } else if (error.message) {
      if (error.message.includes('timeout')) {
        error.message = '请求超时';
      } else if (error.message.includes('Network Error')) {
        error.message = '网络错误，请检查您的网络连接';
      }
    }

    return Promise.reject(error);
  }
);

export default request;
