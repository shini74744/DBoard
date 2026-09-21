

import { useRouter } from 'vue-router';
import { useToast } from '@/composables/useToast';
import { useI18n } from 'vue-i18n';
import NProgress from 'nprogress';
import { tokenLogin, handleLoginSuccess } from '@/api/auth';
import { handleRedirectPath } from '@/utils/redirectHandler';

const initialUrlParams = {
  verifyToken: null,
  redirectPath: null,
  originalUrl: ''
};

(function captureInitialUrlParams() {
  try {
    initialUrlParams.originalUrl = window.location.href;

    const hashPart = window.location.hash || '';

    if (hashPart.includes('?')) {
      const queryPart = hashPart.split('?')[1];
      if (queryPart) {
        const params = new URLSearchParams(queryPart);
        initialUrlParams.verifyToken = params.get('verify');
        initialUrlParams.redirectPath = params.get('redirect');
      }
    }

    if (!initialUrlParams.verifyToken) {
      const queryParams = new URLSearchParams(window.location.search);
      initialUrlParams.verifyToken = queryParams.get('verify');
      initialUrlParams.redirectPath = queryParams.get('redirect');
    }

  } catch (error) {
  }
})();


export const handleTokenLogin = async (options = {}) => {
  const router = useRouter();
  const { showToast } = useToast();
  const { t } = useI18n();

  let verifyToken = initialUrlParams.verifyToken;
  let redirectPath = initialUrlParams.redirectPath;

  if (!verifyToken) {
    let hashPart = window.location.hash;
    let hashParams = new URLSearchParams();

    if (hashPart.includes('?')) {
      hashParams = new URLSearchParams(hashPart.split('?')[1] || '');
    }

    const hashPathParts = hashPart.split('/');
    const lastPathPart = hashPathParts[hashPathParts.length - 1];
    if (lastPathPart && lastPathPart.includes('?')) {
      hashParams = new URLSearchParams(lastPathPart.split('?')[1] || '');
    }

    verifyToken = hashParams.get('verify');
    redirectPath = hashParams.get('redirect');

    if (!verifyToken) {
      const queryParams = new URLSearchParams(window.location.search);
      verifyToken = queryParams.get('verify');
      redirectPath = queryParams.get('redirect');
    }
  }


  redirectPath = redirectPath || '/dashboard';

  if (!verifyToken) {
    return { success: false, verified: false };
  }

  NProgress.start();

  try {
    const response = await tokenLogin(verifyToken, redirectPath);

    if (response.data && (response.data.token || response.data.auth_data)) {
      showToast(response.message || t('auth.verifyTokenSuccess'), 'success');

      const loginResult = handleLoginSuccess(response.data, false);

      if (!loginResult.success) {
      }

      const targetPath = handleRedirectPath(redirectPath);

      if (options.onLoginSuccess && typeof options.onLoginSuccess === 'function') {
        options.onLoginSuccess();
      }

      setTimeout(() => {
        router.push(targetPath);
      }, 500);

      return { success: true, verified: true, redirectPath: targetPath };
    } else {
      showToast(t('auth.verifyTokenFailed'), 'error');
      return { success: false, verified: true, error: t('auth.verifyTokenFailed') };
    }
  } catch (error) {
    showToast(t('auth.verifyTokenFailed'), 'error');
    return {
      success: false,
      verified: true,
      error: t('auth.verifyTokenFailed')
    };
  } finally {
    NProgress.done();
  }
};


export const hasVerifyToken = () => {
  if (initialUrlParams.verifyToken) {
    return true;
  }

  let hashPart = window.location.hash;
  let hashParams = new URLSearchParams();

  if (hashPart.includes('?')) {
    hashParams = new URLSearchParams(hashPart.split('?')[1] || '');
  }

  const hashPathParts = hashPart.split('/');
  const lastPathPart = hashPathParts[hashPathParts.length - 1];
  if (lastPathPart && lastPathPart.includes('?')) {
    hashParams = new URLSearchParams(lastPathPart.split('?')[1] || '');
  }

  const queryParams = new URLSearchParams(window.location.search);


  return hashParams.has('verify') || queryParams.has('verify') || !!initialUrlParams.verifyToken;
};
