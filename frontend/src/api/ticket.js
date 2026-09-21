
import request from './request';


export function fetchTicketList() {
  return request({
    url: '/user/ticket/fetch',
    method: 'get'
  });
}


export function createTicket(data) {
  return request({
    url: '/user/ticket/save',
    method: 'post',
    data
  });
}


export function getTicketDetail(id) {
  return request({
    url: `/user/ticket/fetch?id=${id}`,
    method: 'get'
  });
}


export function replyTicket(id, message) {
  return request({
    url: '/user/ticket/reply',
    method: 'post',
    data: {
      id,
      message
    }
  });
}


export function closeTicket(id) {
  return request({
    url: '/user/ticket/close',
    method: 'post',
    data: {
      id
    }
  });
}
