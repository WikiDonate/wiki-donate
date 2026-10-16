import api from '../config/apiConfig'

const searchCharities = (query, signal) => {
    return api.get('/charities/search', { params: { q: query }, signal })
}

export const charityService = {
    searchCharities,
}
