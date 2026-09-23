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

export function getSubscriptionCombinations() {
    return request({url: '/user/subscriptions/combinations', method: 'get'});
}

export function saveSubscriptionCombination(data) {
    return request({url: '/user/subscriptions/combinations/save', method: 'post', data});
}

export function deleteSubscriptionCombination(id) {
    return request({url: '/user/subscriptions/combinations/delete', method: 'post', data: {id}});
}
