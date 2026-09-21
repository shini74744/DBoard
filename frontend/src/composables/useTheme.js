
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { THEME_CONFIG } from '@/utils/baseConfig';
import { BRAND_PRESETS } from './brandPresets';
let activeBrandTheme = 'Xboard';
export function useTheme() {
  const theme = ref(THEME_CONFIG.defaultTheme);

  const toggleTheme = () => {
    theme.value = theme.value === 'light' ? 'dark' : 'light';
    localStorage.setItem('theme', theme.value);
    applyTheme(theme.value);
  };

  const applyTheme = (selectedTheme) => {
    const root = document.documentElement;
    const themeVars = { ...THEME_CONFIG[selectedTheme], ...(BRAND_PRESETS[activeBrandTheme]?.[selectedTheme] || {}) };

    if (selectedTheme === 'dark') {
      document.body.classList.add('dark-theme');
    } else {
      document.body.classList.remove('dark-theme');
    }

    document.body.offsetHeight;

    root.style.setProperty('--theme-color', themeVars.primaryColor);
    root.style.setProperty('--theme-color-rgb', themeVars.primaryColorRgb);

    root.style.setProperty('--theme-hover-color', themeVars.primaryColorHover);
    root.style.setProperty('--primary-color-hover', themeVars.primaryColorHover);

    root.style.setProperty('--background-color', themeVars.backgroundColor);
    root.style.setProperty('--card-background', themeVars.cardBackground);
    root.style.setProperty('--text-color', themeVars.textColor);
    root.style.setProperty('--secondary-text-color', themeVars.secondaryTextColor);
    root.style.setProperty('--border-color', themeVars.borderColor);
    root.style.setProperty('--shadow-color', themeVars.shadowColor);
    root.dataset.brandTheme = activeBrandTheme;

    if (selectedTheme === 'dark') {
      document.querySelectorAll('.auth-card').forEach(card => {
        card.style.backgroundColor = '#1e1e1e';
        card.style.boxShadow = '0 0 20px rgba(0, 0, 0, 0.3)';
      });
    } else {
      document.querySelectorAll('.auth-card').forEach(card => {
        card.style.backgroundColor = '';
        card.style.boxShadow = '';
      });
    }
  };

  const applyBrandTheme = (name) => {
    activeBrandTheme = BRAND_PRESETS[name] ? name : 'Xboard';
    applyTheme(document.body.classList.contains('dark-theme') ? 'dark' : 'light');
  };
  const initTheme = () => {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
      theme.value = savedTheme;
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      theme.value = 'dark';
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => {
        applyTheme(theme.value);
      });
    } else {
      applyTheme(theme.value);
    }
  };

  watch(theme, (newTheme) => {
    applyTheme(newTheme);
  });

  const handleSystemThemeChange = (e) => {
    if (!localStorage.getItem('theme')) {
      theme.value = e.matches ? 'dark' : 'light';
    }
  };

  onMounted(() => {
    initTheme();

    if (window.matchMedia) {
      const colorSchemeQuery = window.matchMedia('(prefers-color-scheme: dark)');
      colorSchemeQuery.addEventListener('change', handleSystemThemeChange);
    }
  });

  onUnmounted(() => {
    if (window.matchMedia) {
      const colorSchemeQuery = window.matchMedia('(prefers-color-scheme: dark)');
      colorSchemeQuery.removeEventListener('change', handleSystemThemeChange);
    }
  });

  return {
    theme,
    toggleTheme,
    applyTheme,
    applyBrandTheme
  };
}
