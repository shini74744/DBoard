
import { checkLoginStatus } from '@/api/auth';

let lastLoginState = null;


export const checkAuthAndReloadMessages = () => {
  const isLoggedIn = checkLoginStatus();

  if (lastLoginState === null || lastLoginState !== isLoggedIn) {
    lastLoginState = isLoggedIn;

    try {
      setTimeout(async () => {
        try {
          const { reloadMessages } = await import('@/i18n');
          await reloadMessages();
        } catch (asyncError) {
        }
      }, 10);
    } catch (error) {
    }
  }

  return isLoggedIn;
};


export const isAuthPage = (path) => {
  return /\/(login|register|forgot-password)/.test(path);
};


export const setupLoginStateWatcher = () => {
  let lastLoginState = null;

  setInterval(() => {
    const isLoggedIn = checkLoginStatus();

    if (lastLoginState !== null && lastLoginState !== isLoggedIn) {
      Promise.resolve().then(function() { return import('@/i18n'); })
        .then(({ reloadMessages }) => {
          reloadMessages().catch(() => {
          });
        }).catch(() => {
        });
    }

    lastLoginState = isLoggedIn;
  }, 60000);
};
