// Nested route dialogs are portalled beside their parent dialogs. Their scroll
// locks can cancel wheel/touch events even when the inner area is scrollable.
(() => {
  const scope = target => target instanceof Element ? target.closest('[data-dboard-route-dialog],[data-dboard-route-user-list]') : null;
  function scroll(target, dy) {
    const root = scope(target);
    if (!root) return false;
    const candidates = [];
    for (let node = target; node instanceof Element; node = node.parentElement) {
      if (node.scrollHeight > node.clientHeight + 1 && /(auto|scroll)/.test(getComputedStyle(node).overflowY)) candidates.push(node);
      if (node === root) break;
    }
    if (root.matches('[data-dboard-route-user-list]')) {
      const dialogs = [...document.querySelectorAll('[data-dboard-route-scroll]')].filter(node => node.getClientRects().length);
      if (dialogs.length) candidates.push(dialogs[dialogs.length - 1]);
    }
    for (const node of candidates) {
      const before = node.scrollTop;
      node.scrollTop += dy;
      if (Math.abs(node.scrollTop - before) > 0.1) return true;
    }
    return true; // Do not pass movement at a boundary to the page behind the dialog.
  }
  window.addEventListener('wheel', event => {
    if (event.ctrlKey || !scope(event.target) || Math.abs(event.deltaX) > Math.abs(event.deltaY)) return;
    const scale = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? innerHeight : 1;
    if (scroll(event.target, event.deltaY * scale)) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, { capture: true, passive: false });
  let gesture = null;
  window.addEventListener('touchstart', event => {
    gesture = event.touches.length === 1 && scope(event.target)
      ? { target: event.target, x: event.touches[0].clientX, y: event.touches[0].clientY, moved: false } : null;
  }, { capture: true, passive: true });
  window.addEventListener('touchmove', event => {
    if (!gesture || event.touches.length !== 1 || !gesture.target.isConnected) { gesture = null; return; }
    const touch = event.touches[0], dy = gesture.y - touch.clientY, dx = gesture.x - touch.clientX;
    if (!gesture.moved && Math.abs(dy) < 5) return;
    if (!gesture.moved && Math.abs(dx) > Math.abs(dy)) { gesture = null; return; }
    gesture.moved = true;
    if (scroll(gesture.target, dy)) { event.preventDefault(); event.stopImmediatePropagation(); }
    gesture.x = touch.clientX; gesture.y = touch.clientY;
  }, { capture: true, passive: false });
  for (const type of ['touchend', 'touchcancel']) window.addEventListener(type, () => { gesture = null; }, { capture: true, passive: true });
})();
