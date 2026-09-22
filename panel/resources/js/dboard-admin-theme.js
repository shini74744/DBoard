(() => {
  const root = document.documentElement;
  const themes = new Set(['Xboard', 'DBoard-Tide', 'DBoard-Copper', 'DBoard-Iris', 'DBoard-Glass']);
  let syncRunning = false;

  async function syncTheme() {
    if (document.hidden || syncRunning) return;
    syncRunning = true;
    try {
      const response = await fetch('/api/v1/guest/comm/config', { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) return;
      const payload = await response.json();
      const theme = payload?.data?.frontend_theme;
      if (themes.has(theme)) root.dataset.dboardTheme = theme;
    } catch (_) {
      // The server-rendered theme remains available when a refresh fails.
    } finally {
      syncRunning = false;
    }
  }

  window.addEventListener('focus', syncTheme);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) syncTheme(); });
  window.setInterval(syncTheme, 20000);

  if ('PerformanceObserver' in window) {
    const observer = new PerformanceObserver((list) => {
      if (list.getEntries().some((entry) => {
        const url = new URL(entry.name);
        return url.origin === location.origin && (url.pathname.endsWith('/config/save') || url.pathname.endsWith('/theme/switch'));
      })) window.setTimeout(syncTheme, 250);
    });
    observer.observe({ type: 'resource', buffered: false });
  }

  if (!window.matchMedia) return;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
  const surfaces = '.bg-card, .dboard-admin-card, .dboard-marketing-form, .dboard-marketing-history';
  let pending = null;
  let frame = 0;

  document.addEventListener('pointermove', (event) => {
    if (event.pointerType !== 'mouse' || !finePointer.matches || reducedMotion.matches || root.dataset.dboardTheme !== 'DBoard-Glass') return;
    const card = event.target instanceof Element ? event.target.closest(surfaces) : null;
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
    const card = event.target instanceof Element ? event.target.closest(surfaces) : null;
    if (!card || (event.relatedTarget instanceof Node && card.contains(event.relatedTarget))) return;
    if (pending?.card === card) pending = null;
    card.style.removeProperty('--glass-x');
    card.style.removeProperty('--glass-y');
  }, { passive: true });
})();
