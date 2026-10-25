import api from '../config/apiConfig'

/**
 * Donor checkout service.
 *
 * Every call is routed through the generic, method-parameterised endpoints
 * (`/payment/{method}/create-order` + `/payment/{method}/capture-order`), where
 * `{method}` is a `payment_methods.code` from GET /v1/payment-methods. Enabling
 * a new method in the registry therefore needs no change here — the gateway
 * difference stays on the server.
 */

const methodPath = (method) => `/payment/${encodeURIComponent(String(method || '').toLowerCase())}`

const createOrder = (method, params) => {
    return api.post(`${methodPath(method)}/create-order`, params)
}

const captureOrder = (method, params) => {
    const reference = params?.reference || params?.order_id || params?.orderID
    return api.post(`${methodPath(method)}/capture-order`, { order_id: reference })
}

export const donateService = {
    methodPath,
    createOrder,
    captureOrder,

    // Back-compat shims for callers that predate the generic endpoints.
    createCheckoutSession: (params) => createOrder('stripe', params),
    createPaypalOrder: (params) => createOrder('paypal', params),
    capturePaypalOrder: (params) => captureOrder('paypal', params),
}
