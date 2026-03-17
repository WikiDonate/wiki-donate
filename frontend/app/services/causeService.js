import api from '../config/apiConfig'

const getCauses = () => {
    return api.get('/causes')
}

const searchCauses = (params) => {
    return api.get(`/causes/search?title=${params.title}`)
}

const getCause = (id) => {
    return api.get(`/causes/${id}`)
}

export const causeService = {
    getCauses,
    searchCauses,
    getCause,
}
