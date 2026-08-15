import api from '../config/apiConfig'

const saveCard = (params) => {
    return api.post('/stripe/card', params)
}

const getCard = () => {
    return api.get('/stripe/card')
}

export const paymentMethodService = {
    saveCard,
    getCard,
}
