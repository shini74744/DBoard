import request from './request';


export function fetchOrderList() {
  return request({
    url: '/user/order/fetch',
    method: 'get'
  }).then(response => {
    if (typeof response === 'object') {
      return response;
    }

    throw new Error('Invalid response format');
  }).catch(error => {
    console.error('Error fetching order list:', error);
    throw error;
  });
}


export function cancelOrder(tradeNo) {
  return new Promise((resolve, reject) => {
    if (!tradeNo) {
      reject(new Error('订单号不能为空'));
      return;
    }

    request({
      url: `/user/order/cancel`,
      method: 'post',
      data: {
        trade_no: tradeNo
      }
    })
    .then(response => {
      if (response && typeof response === 'object') {
        resolve(response);
      } else {
        reject(new Error('返回数据格式不正确'));
      }
    })
    .catch(error => {
      console.error('取消订单失败:', error);

      let errorMessage = '取消订单失败';

      if (error) {
        if (typeof error.message === 'string') {
          errorMessage = error.message;
        } else if (error.response && error.response.data) {
          const responseData = error.response.data;
          if (typeof responseData === 'string') {
            errorMessage = responseData;
          } else if (responseData.message) {
            errorMessage = responseData.message;
          } else if (responseData.error) {
            errorMessage = responseData.error;
          }
        }
      }

      reject(new Error(errorMessage));
    });
  });
}
