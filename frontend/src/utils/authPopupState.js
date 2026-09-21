

let popupShownInSession = false;


export function shouldShowAuthPopup(config) {
  if (!config || !config.enabled) {
    return false;
  }

  if (popupShownInSession) {
    return false;
  }

  if (config.cooldownHours > 0) {
    const closeTime = localStorage.getItem('auth_popup_close_time');
    if (closeTime) {
      const now = new Date().getTime();
      const elapsed = now - parseInt(closeTime);
      const cooldownMs = config.cooldownHours * 60 * 60 * 1000;

      if (elapsed < cooldownMs) {
        return false;
      }
    }
  }

  popupShownInSession = true;
  return true;
}


export function resetPopupSessionState() {
  popupShownInSession = false;
}
