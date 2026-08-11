import api from '../config/apiConfig'

const donateNow = (params) => {
    return api.post('/donate-now', params)
}
const createCheckoutSession = (params) => {
    return api.post('/stripe/checkout', params)
}
const createPaypalOrder = (params) => {
    return api.post('/paypal/create-order', params)
}
const capturePaypalOrder = (params) => {
    return api.post('/paypal/capture-order', params)
}

export const donateService = {
    donateNow,
    createCheckoutSession,
    createPaypalOrder,
    capturePaypalOrder,
}
