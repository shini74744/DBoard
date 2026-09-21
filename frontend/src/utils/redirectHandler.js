
export function handleRedirectPath(redirectPath) {
  let targetPath = '/dashboard';

  if (redirectPath) {
    if (redirectPath === '/plan' || redirectPath === '/shop' || redirectPath === 'plan' || redirectPath === 'shop') {
      targetPath = '/shop';
    } else if (redirectPath === '/knowledge' || redirectPath === '/docs' || redirectPath === 'knowledge' || redirectPath === 'docs') {
      targetPath = '/docs';
    } else if (redirectPath === '/profile' || redirectPath === 'profile') {
      targetPath = '/profile';
    } else if (redirectPath === '/order' || redirectPath === '/orders' || redirectPath === 'order' || redirectPath === 'orders') {
      targetPath = '/orders';
    }
    else if (redirectPath.startsWith('/')) {
      targetPath = redirectPath;
    }
    else {
      targetPath = `/${redirectPath}`;
    }
  }

  return targetPath;
}


export function getRedirectFromQuery(route) {
  const redirect = route.query.redirect;
  return handleRedirectPath(redirect);
}


export function performRedirect(router, route) {
  const targetPath = getRedirectFromQuery(route);
  router.replace(targetPath);
}
