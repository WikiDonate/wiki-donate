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
const getMyArticles = (page = 1) => {
    return api.get(`/articles/my?page=${page}&per_page=10`)
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
    getMyArticles,
}
