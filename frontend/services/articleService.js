import api from '../config/apiConfig'

const searchArticles = (query) => {
    return api.get('/search?query=' + query)
}

const saveArticle = (params) => {
    return api.post('/articles', params)
}

const getArticle = (slug) => {
    return api.get(`/articles/${slug}`)
}

const updateArticle = (params) => {
    return api.put(`/articles/update/${params.slug}`, params)
}

const getHistory = (slug) => {
    return api.get(`/articles/${slug}/history`)
}

export const articleService = {
    searchArticles,
    saveArticle,
    getArticle,
    updateArticle,
    getHistory,
}
