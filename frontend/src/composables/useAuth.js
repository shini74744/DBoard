
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { login, register, resetPassword, getUserInfo, logout } from '@/api/auth';

export function useAuth() {
  const router = useRouter();
  const user = ref(null);
  const token = ref(localStorage.getItem('token') || '');
  const loading = ref(false);
  const error = ref('');

  const isLoggedIn = computed(() => !!token.value);

  const loginUser = async (credentials) => {
    loading.value = true;
    error.value = '';

    try {
      const response = await login(credentials);

      if (!response) {
        throw new Error('登录响应无效，请稍后再试');
      }

      if (!response.token) {
        throw new Error('登录响应中缺少令牌，请稍后再试');
      }

      token.value = response.token;

      if (response.auth_data) {
        localStorage.setItem('auth_data', response.auth_data);
      }

      await fetchUserInfo();

      return { success: true };
    } catch (err) {
      error.value = err.message || '登录失败，请检查您的凭据';
      return {
        success: false,
        error: error.value
      };
    } finally {
      loading.value = false;
    }
  };

  const registerUser = async (userData) => {
    loading.value = true;
    error.value = '';

    try {
      const response = await register(userData);

      if (!response || !response.success) {
        throw new Error(response?.message || '注册失败，请稍后再试');
      }

      return {
        success: true,
        message: '注册成功，请登录'
      };
    } catch (err) {
      error.value = err.message || '注册失败，请稍后再试';
      return {
        success: false,
        error: error.value
      };
    } finally {
      loading.value = false;
    }
  };

  const resetUserPassword = async (email) => {
    loading.value = true;
    error.value = '';

    try {
      const response = await resetPassword({ email });

      if (!response || !response.success) {
        throw new Error(response?.message || '重置密码请求失败，请稍后再试');
      }

      return {
        success: true,
        message: '重置密码邮件已发送，请查收'
      };
    } catch (err) {
      error.value = err.message || '重置密码失败，请稍后再试';
      return {
        success: false,
        error: error.value
      };
    } finally {
      loading.value = false;
    }
  };

  const fetchUserInfo = async () => {
    if (!token.value) return;

    loading.value = true;

    try {
      const userInfo = await getUserInfo();
      user.value = userInfo;
      localStorage.setItem('userInfo', JSON.stringify(userInfo));
    } catch (err) {
    } finally {
      loading.value = false;
    }
  };

  const logoutUser = async () => {
    loading.value = true;
    error.value = '';

    try {
      const response = await logout();

      if (response && response.success) {
        token.value = '';
        user.value = null;

        if (response.redirectToLogin && response.redirectUrl) {
          router.push(response.redirectUrl);
        } else {
          router.push('/login?logout=true');
        }

        return {
          success: true,
          message: '已成功退出登录'
        };
      } else {
        throw new Error(response?.error || '退出登录失败');
      }
    } catch (err) {
      error.value = err.message || '退出登录过程发生错误';
      return {
        success: false,
        error: error.value
      };
    } finally {
      loading.value = false;
    }
  };

  const initUserInfo = () => {
    const userInfo = localStorage.getItem('userInfo');
    if (userInfo) {
      try {
        user.value = JSON.parse(userInfo);
      } catch (err) {
        localStorage.removeItem('userInfo');
      }
    }
  };

  initUserInfo();

  return {
    user,
    token,
    loading,
    error,
    isLoggedIn,
    loginUser,
    registerUser,
    resetUserPassword,
    fetchUserInfo,
    logoutUser
  };
}
