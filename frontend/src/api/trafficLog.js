
import request from './request';


export function getTrafficLog() {
  return request({
    url: '/user/stat/getTrafficLog',
    method: 'get'
  });
}

export function getDailyTraffic(days = 30) {
  return request({
    url: '/user/stat/getDailyTraffic',
    method: 'get',
    params: { days }
  });
}
