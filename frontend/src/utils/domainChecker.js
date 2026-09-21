import { AUTHORIZED_DOMAINS, SECURITY_CONFIG } from './baseConfig';


export const isDomainAuthorized = () => {
  if (!SECURITY_CONFIG.enableFrontendDomainCheck) {
    return true;
  }

  const currentDomain = window.location.hostname;
  return AUTHORIZED_DOMAINS.includes(currentDomain);
};


export const handleUnauthorizedDomain = () => {
  if (!SECURITY_CONFIG.enableFrontendDomainCheck) {
    return true;
  }

  if (!isDomainAuthorized()) {
    console.clear();


    const blockUI = () => {
      const blocker = document.createElement('div');
      blocker.style.position = 'fixed';
      blocker.style.top = '0';
      blocker.style.left = '0';
      blocker.style.width = '100%';
      blocker.style.height = '100%';
      blocker.style.backgroundColor = '#ffffff';
      blocker.style.zIndex = '999999';
      document.body.appendChild(blocker);

      document.body.style.overflow = 'hidden';


      const disableEvents = (e) => {
        e.stopPropagation();
        e.preventDefault();
        return false;
      };

      ['click', 'contextmenu', 'mousedown', 'mouseup', 'touchstart',
       'touchend', 'keydown', 'keyup', 'keypress', 'scroll', 'wheel'].forEach(eventType => {
        document.addEventListener(eventType, disableEvents, { capture: true });
      });


      const startLoop = () => {
      };

      setTimeout(startLoop, 500);
    };

    blockUI();

    return false;
  }

  return true;
};
