import request from './request';


export function fetchServerNodes() {
  return request({
    url: '/user/server/fetch',
    method: 'get'
  }).then(response => {
    if (typeof response === 'object') {
      return response;
    }

    throw new Error('Invalid response format');
  }).catch(error => {
    console.error('Error fetching server nodes:', error);
    throw error;
  });
}
