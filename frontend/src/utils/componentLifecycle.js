


export function cleanupResources(timers = {}, listeners = {}) {
  Object.keys(timers).forEach(key => {
    if (timers[key]) {
      if (timers[key]._repeat) {
        clearInterval(timers[key]);
      } else {
        clearTimeout(timers[key]);
      }
      timers[key] = null;
    }
  });

  Object.keys(listeners).forEach(key => {
    const listener = listeners[key];
    if (listener && listener.element && listener.event && listener.callback) {
      listener.element.removeEventListener(listener.event, listener.callback, listener.options);
      listeners[key] = null;
    }
  });
}


export function createDebouncedUpdate(updateFn, delay = 300) {
  let timer = null;

  return function(...args) {
    const context = this;

    if (timer) {
      clearTimeout(timer);
    }

    timer = setTimeout(() => {
      updateFn.apply(context, args);
      timer = null;
    }, delay);

    return timer;
  };
}


export function createThrottledUpdate(updateFn, delay = 300) {
  let lastExecTime = 0;
  let timer = null;

  return function(...args) {
    const context = this;
    const now = Date.now();

    if (timer) {
      clearTimeout(timer);
      timer = null;
    }

    const elapsed = now - lastExecTime;

    if (elapsed >= delay) {
      lastExecTime = now;
      updateFn.apply(context, args);
    } else {
      timer = setTimeout(() => {
        lastExecTime = Date.now();
        updateFn.apply(context, args);
        timer = null;
      }, delay - elapsed);
    }
  };
}


export function registerEventListener(listeners, name, element, event, callback, options = false) {
  if (listeners[name]) {
    const existing = listeners[name];
    existing.element.removeEventListener(existing.event, existing.callback, existing.options);
  }

  element.addEventListener(event, callback, options);

  listeners[name] = {
    element,
    event,
    callback,
    options
  };
}


export function createTimer(timers, name, callback, delay, isInterval = false) {
  if (timers[name]) {
    if (timers[name]._repeat) {
      clearInterval(timers[name]);
    } else {
      clearTimeout(timers[name]);
    }
    timers[name] = null;
  }

  const timer = isInterval
    ? setInterval(callback, delay)
    : setTimeout(callback, delay);

  timers[name] = timer;

  return timer;
}

export default {
  cleanupResources,
  createDebouncedUpdate,
  createThrottledUpdate,
  registerEventListener,
  createTimer
};
