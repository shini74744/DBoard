
import { createStore } from 'vuex';
import { THEME_CONFIG } from '@/utils/baseConfig';
import { forceLogout } from '@/api/auth';

export default createStore({
  state: {
    user: null,
    token: localStorage.getItem('token') || '',

    theme: localStorage.getItem('theme') || THEME_CONFIG.defaultTheme,

    loading: false,
    error: null
  },

  getters: {
    isLoggedIn: state => !!state.token,

    userInfo: state => state.user,

    currentTheme: state => state.theme,

    isDarkTheme: state => state.theme === 'dark'
  },

  mutations: {
    SET_USER(state, user) {
      state.user = user;
    },

    SET_TOKEN(state, token) {
      state.token = token;
      localStorage.setItem('token', token);
    },

    CLEAR_USER(state) {
      state.user = null;
      state.token = '';
      localStorage.removeItem('token');
      localStorage.removeItem('userInfo');
    },

    SET_THEME(state, theme) {
      state.theme = theme;
      localStorage.setItem('theme', theme);
    },

    SET_LOADING(state, status) {
      state.loading = status;
    },

    SET_ERROR(state, error) {
      state.error = error;
    }
  },

  actions: {
    login({ commit }, token) {
      commit('SET_TOKEN', token);
    },

    logout({ commit }) {
      commit('CLEAR_USER');
      try {
        if (typeof forceLogout === 'function') {
          forceLogout();
        }
      } catch (error) {
        console.error('在Store中调用forceLogout失败:', error);
      }
    },

    setUser({ commit }, user) {
      commit('SET_USER', user);
      localStorage.setItem('userInfo', JSON.stringify(user));
    },

    toggleTheme({ commit, state }) {
      const newTheme = state.theme === 'light' ? 'dark' : 'light';
      commit('SET_THEME', newTheme);
    },

    initUserInfo({ commit }) {
      const userInfo = localStorage.getItem('userInfo');
      if (userInfo) {
        try {
          commit('SET_USER', JSON.parse(userInfo));
        } catch (err) {
          console.error('解析用户信息失败:', err);
          localStorage.removeItem('userInfo');
        }
      }
    }
  },

  modules: {
  }
});
