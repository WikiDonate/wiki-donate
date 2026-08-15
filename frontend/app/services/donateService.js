import api from '../config/apiConfig'

const createCheckoutSession = (params) => {
    return api.post('/stripe/checkout', params)
}
const createPaypalOrder = (params) => {
    return api.post('/paypal/create-order', params)
}
const capturePaypalOrder = (params) => {
    const orderId = params.order_id || params.orderID
    return api.post('/paypal/capture-order', { order_id: orderId })
}

export const donateService = {
    createCheckoutSession,
    createPaypalOrder,
    capturePaypalOrder,
}
