import api from '../config/apiConfig'

const saveTalk = (params) => {
    return api.post('/talks', params)
}

const getTalk = (slug) => {
    return api.get(`/talks/${slug}`)
}

const updateTalk = (params) => {
    return api.put(`/talks/update/${params.slug}`, params)
}

const getHistory = (slug) => {
    return api.get(`/talks/${slug}/history`)
}

export const talkService = {
    saveTalk,
    getTalk,
    updateTalk,
    getHistory,
}
