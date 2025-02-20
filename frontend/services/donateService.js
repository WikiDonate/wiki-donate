import api from '../config/apiConfig'

const saveDonate = (params) => {
    return api.post('/donate', params)
}

export const donateService = {
    saveDonate,
}
