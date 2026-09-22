
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { THEME_CONFIG } from '@/utils/baseConfig';
import { BRAND_PRESETS } from './brandPresets';
import { readableText } from '@/utils/colorContrast';
let activeBrandTheme = 'Xboard';
let glassPointerInstalled = false;

function enableGlassPointerLight() {
  if (glassPointerInstalled) return;
  glassPointerInstalled = true;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
  const surfaceSelector = '.dashboard-card, .plan-card, .profile-card, .auth-card';
  let pending = null;
  let frame = 0;

  document.addEventListener('pointermove', (event) => {
    if (event.pointerType !== 'mouse' || !finePointer.matches || reducedMotion.matches || activeBrandTheme !== 'DBoard-Glass') return;
    const card = event.target instanceof Element ? event.target.closest(surfaceSelector) : null;
    if (!card) return;
    const bounds = card.getBoundingClientRect();
    if (!bounds.width || !bounds.height) return;
    pending = {
      card,
      x: `${Math.max(0, Math.min(100, ((event.clientX - bounds.left) / bounds.width) * 100)).toFixed(1)}%`,
      y: `${Math.max(0, Math.min(100, ((event.clientY - bounds.top) / bounds.height) * 100)).toFixed(1)}%`
    };
    if (frame) return;
    frame = requestAnimationFrame(() => {
      if (pending?.card.isConnected) {
        pending.card.style.setProperty('--glass-x', pending.x);
        pending.card.style.setProperty('--glass-y', pending.y);
      }
      pending = null;
      frame = 0;
    });
  }, { passive: true });

  document.addEventListener('pointerout', (event) => {
    const card = event.target instanceof Element ? event.target.closest(surfaceSelector) : null;
    if (!card || (event.relatedTarget instanceof Node && card.contains(event.relatedTarget))) return;
    if (pending?.card === card) pending = null;
    card.style.removeProperty('--glass-x');
    card.style.removeProperty('--glass-y');
  }, { passive: true });
}

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
      const useLegacyDarkSurface = selectedTheme === 'dark' && activeBrandTheme === 'Xboard';
      card.style.backgroundColor = useLegacyDarkSurface ? '#1e1e1e' : '';
      card.style.boxShadow = useLegacyDarkSurface ? '0 0 20px rgba(0, 0, 0, 0.3)' : '';
    });
  };

  const applyBrandTheme = (name) => {
    activeBrandTheme = BRAND_PRESETS[name] ? name : 'Xboard';
    applyTheme(document.body.classList.contains('dark-theme') ? 'dark' : 'light');
    if (activeBrandTheme === 'DBoard-Glass') enableGlassPointerLight();
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
