import disableDevtool from "disable-devtool";

const isProd = process.env.NODE_ENV === "production";
const enableConfigJS = process.env.VUE_APP_CONFIGJS == "true";
const enableAntiDebugging = process.env.VUE_APP_DEBUGGING == "true";

(async () => {
  try {
    if (typeof window !== 'undefined' && !window.EZ_CONFIG) {
      const res = await import('./config/index.js');
      window.EZ_CONFIG = res.config || res.default || res;
    }

    // 反调试逻辑
    if (isProd && enableAntiDebugging) {
      disableDevtool()
    }

    // ⚠️ 确保在 config 加载后再初始化应用
    await import('./appInit.js');
  } catch (error) {
    console.error(error);
  }
})();
