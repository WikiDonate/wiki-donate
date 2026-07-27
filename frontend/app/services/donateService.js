import api from '../config/apiConfig'

const saveDonate = (params) => {
    return api.post('/donate', params)
}
const donateNow = (params) => {
    return api.post('/donate-now', params)
}
const recordPaymentAndDistribute = (params) => {
    return api.post('/record-payment', params)
}
const createCheckoutSession = (params) => {
    return api.post('/stripe/checkout', params)
}

export const donateService = {
    saveDonate,
    donateNow,
    recordPaymentAndDistribute,
    createCheckoutSession,
}
