
import request from './request';


export function getUserConfig() {
  return request({
    url: '/user/comm/config',
    method: 'get'
  });
}


export function createOrderDeposit(amount) {
  return request({
    url: '/user/order/save',
    method: 'post',
    data: {
      period: 'deposit',
      deposit_amount: amount,
      plan_id: 0
    }
  });
}
