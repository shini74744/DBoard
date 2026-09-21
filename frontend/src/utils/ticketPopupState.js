

let popupShownInSession = false;


export function shouldShowTicketPopup(config) {
  if (!config || !config.enabled) return false;

  if (popupShownInSession) return false;

  if (config.cooldownHours > 0) {
    const closeTime = localStorage.getItem('ticket_popup_close_time');
    if (closeTime) {
      const elapsed = Date.now() - parseInt(closeTime);
      if (elapsed < config.cooldownHours * 60 * 60 * 1000) {
        return false;
      }
    }
  }
  popupShownInSession = true;
  return true;
}

export function resetTicketPopupSession() {
  popupShownInSession = false;
}
