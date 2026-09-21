

const DEFAULT_CACHED_ROUTES = [
  'Dashboard'
];

let cachedRoutes = new Set(DEFAULT_CACHED_ROUTES);

let routeAccessTimes = new Map();

const MAX_CACHE_COUNT = 5;


export function getCachedRoutes() {
  return Array.from(cachedRoutes);
}


export function addRouteToCache(routeName) {
  if (!routeName) return;

  routeAccessTimes.set(routeName, Date.now());

  cachedRoutes.add(routeName);

  if (cachedRoutes.size > MAX_CACHE_COUNT) {
    let oldestRoute = null;
    let oldestTime = Infinity;

    for (const [route, time] of routeAccessTimes.entries()) {
      if (DEFAULT_CACHED_ROUTES.includes(route)) continue;

      if (time < oldestTime) {
        oldestTime = time;
        oldestRoute = route;
      }
    }

    if (oldestRoute) {
      cachedRoutes.delete(oldestRoute);
      routeAccessTimes.delete(oldestRoute);
    }
  }
}


export function removeRouteFromCache(routeName) {
  if (!routeName) return;

  cachedRoutes.delete(routeName);
  routeAccessTimes.delete(routeName);
}


export function clearCache() {
  cachedRoutes = new Set(DEFAULT_CACHED_ROUTES);

  routeAccessTimes = new Map();
  DEFAULT_CACHED_ROUTES.forEach(route => {
    routeAccessTimes.set(route, Date.now());
  });
}


export function resetCache() {
  clearCache();
}


export function setCachedRoutes(routes) {
  if (Array.isArray(routes)) {
    cachedRoutes = new Set(routes);

    routeAccessTimes = new Map();
    routes.forEach(route => {
      routeAccessTimes.set(route, Date.now());
    });
  }
}


export function isRouteCached(routeName) {
  return cachedRoutes.has(routeName);
}


export function touchRoute(routeName) {
  if (cachedRoutes.has(routeName)) {
    routeAccessTimes.set(routeName, Date.now());
  }
}

export default {
  getCachedRoutes,
  addRouteToCache,
  removeRouteFromCache,
  clearCache,
  resetCache,
  setCachedRoutes,
  isRouteCached,
  touchRoute
};
