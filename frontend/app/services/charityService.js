import api from '../config/apiConfig'

const searchCharities = () => {
    return api.get(`/paypal/charities`)
}

export const charityService = {
    searchCharities,
}
