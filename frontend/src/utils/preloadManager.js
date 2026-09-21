

const preloadStatus = {
  components: {},

  resources: {},

  startTime: null
};


const markComponentLoaded = (componentName) => {
  if (!componentName) return;
  preloadStatus.components[componentName] = true;
};


const isComponentLoaded = (componentName) => {
  return !!preloadStatus.components[componentName];
};


const markResourceLoaded = (resourceUrl) => {
  if (!resourceUrl) return;
  preloadStatus.resources[resourceUrl] = true;
};


const isResourceLoaded = (resourceUrl) => {
  return !!preloadStatus.resources[resourceUrl];
};


const startPreloadTimer = () => {
  preloadStatus.startTime = Date.now();
};


const getPreloadTime = () => {
  if (!preloadStatus.startTime) return 0;
  return Date.now() - preloadStatus.startTime;
};


const resetPreloadStatus = () => {
  preloadStatus.components = {};
  preloadStatus.resources = {};
  preloadStatus.startTime = null;
};


const getPreloadStats = () => {
  return {
    componentsCount: Object.keys(preloadStatus.components).length,
    resourcesCount: Object.keys(preloadStatus.resources).length,
    time: getPreloadTime()
  };
};


const createPreloadLink = (href, as, type) => {
  if (!href || isResourceLoaded(href)) return null;

  const link = document.createElement('link');
  link.rel = 'preload';
  link.href = href;
  link.as = as || 'script';
  if (type) link.type = type;

  markResourceLoaded(href);

  return link;
};

export default {
  markComponentLoaded,
  isComponentLoaded,
  markResourceLoaded,
  isResourceLoaded,
  startPreloadTimer,
  getPreloadTime,
  resetPreloadStatus,
  getPreloadStats,
  createPreloadLink
};
