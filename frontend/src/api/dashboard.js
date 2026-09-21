import request from './request';


export function getUserInfo() {

    return request({

        url: '/user/info',

        method: 'get'

    });

}


export function getSubscribe() {

    return request({

        url: '/user/getSubscribe',

        method: 'get'

    });

}


export function getNotices() {

    return request({

        url: '/user/notice/fetch',

        method: 'get'

    });

}


export function getUserStats() {

    return request({

        url: '/user/getStat',

        method: 'get'

    });

}


export function getUserConfig() {

    return request({

        url: '/user/comm/config',

        method: 'get'

    });

}

export function setNextPeriod() {

    return request({

        url: '/user/newPeriod',

        method: 'POST'

    });

}
