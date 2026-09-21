import request from './request';


export function fetchPlans() {
  return request({
    url: '/user/plan/fetch',
    method: 'get'
  });
}


export function getCommConfig() {
  return request({
    url: '/user/comm/config',
    method: 'get'
  });
}


export function fetchPlanById(id) {
  return request({
    url: `/user/plan/fetch?id=${id}`,
    method: 'get'
  });
}


export function verifyCoupon(code, planId) {
  return request({
    url: '/user/coupon/check',
    method: 'post',
    data: {
      code: code,
      plan_id: planId
    }
  });
}


export function submitOrder(data) {
  return request({
    url: '/user/order/save',
    method: 'post',
    data
  });
}


export function getOrderDetail(tradeNo) {
  return request({
    url: `/user/order/detail?trade_no=${tradeNo}`,
    method: 'get'
  });
}


export function getPaymentMethods() {
  return request({
    url: '/user/order/getPaymentMethod',
    method: 'get'
  });
}


export function checkOrderStatus(tradeNo) {
  return request({
    url: `/user/order/check?trade_no=${tradeNo}`,
    method: 'get'
  });
}


export function cancelOrder(tradeNo) {
  return request({
    url: '/user/order/cancel',
    method: 'post',
    data: {
      trade_no: tradeNo
    }
  });
}


export function checkoutOrder(tradeNo, methodId) {
  return request({
    url: '/user/order/checkout',
    method: 'post',
    data: {
      trade_no: tradeNo,
      method: methodId
    }
  });
}
