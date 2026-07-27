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
const getCheckoutSession = (sessionId) => {
    return api.get(`/stripe/checkout/${sessionId}`)
}

export const donateService = {
    saveDonate,
    donateNow,
    recordPaymentAndDistribute,
    getCheckoutSession,
}
