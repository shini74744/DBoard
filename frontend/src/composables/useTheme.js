
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { THEME_CONFIG } from '@/utils/baseConfig';
import { BRAND_PRESETS } from './brandPresets';
import { readableText } from '@/utils/colorContrast';
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

    const background = themeVars.backgroundColor;
    const cardBackground = themeVars.cardBackground || background;
    const bodyText = readableText(themeVars.textColor, background, selectedTheme === 'dark' ? '#171a1d' : '#ffffff');
    const cardText = readableText(themeVars.textColor, cardBackground, background);
    const variables = {
      '--theme-color': themeVars.primaryColor,
      '--theme-color-rgb': themeVars.primaryColorRgb,
      '--theme-hover-color': themeVars.primaryColorHover,
      '--primary-color-hover': themeVars.primaryColorHover,
      '--on-theme-color': readableText('#ffffff', themeVars.primaryColor, background),
      '--background-color': background,
      '--card-background': cardBackground,
      '--card-bg-color': cardBackground,
      '--text-color': bodyText,
      '--secondary-text-color': readableText(themeVars.secondaryTextColor, background),
      '--card-text-color': cardText,
      '--card-secondary-text-color': readableText(themeVars.secondaryTextColor, cardBackground, background),
      '--border-color': themeVars.borderColor,
      '--shadow-color': themeVars.shadowColor,
    };
    Object.entries(variables).forEach(([name, value]) => {
      root.style.setProperty(name, value);
      document.body.style.setProperty(name, value);
    });
    root.dataset.brandTheme = activeBrandTheme;

    document.querySelectorAll('.auth-card').forEach(card => {
      const useLegacyDarkSurface = selectedTheme === 'dark' && activeBrandTheme !== 'DBoard-Glass';
      card.style.backgroundColor = useLegacyDarkSurface ? '#1e1e1e' : '';
      card.style.boxShadow = useLegacyDarkSurface ? '0 0 20px rgba(0, 0, 0, 0.3)' : '';
    });
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
