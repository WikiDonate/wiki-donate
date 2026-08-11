import api from '../config/apiConfig'

const donateNow = (params) => {
    return api.post('/donate-now', params)
}
const createCheckoutSession = (params) => {
    return api.post('/stripe/checkout', params)
}
const createPaypalOrder = (params) => {
    return api.post('/paypal/order', params)
}
const capturePaypalOrder = (params) => {
    // Backend route expects order ID as URL param: POST /paypal/order/{orderId}/capture
    const orderId = params.order_id || params.orderID
    return api.post(`/paypal/order/${orderId}/capture`, params)
}

export const donateService = {
    donateNow,
    createCheckoutSession,
    createPaypalOrder,
    capturePaypalOrder,
}
