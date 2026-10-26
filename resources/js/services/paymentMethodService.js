import api from '../config/apiConfig'

const saveCard = (params) => {
    return api.post('/stripe/card', params)
}

const getCard = () => {
    return api.get('/stripe/card')
}

/**
 * Browser-safe gateway credential (PayPal client id / Stripe publishable key)
 * from the payment_methods registry. Never returns a secret key.
 */
const getPublicKey = (code) => {
    return api.get(`/payment-methods/${encodeURIComponent(code)}/public-key`)
}

export const paymentMethodService = {
    saveCard,
    getCard,
    getPublicKey,
}
