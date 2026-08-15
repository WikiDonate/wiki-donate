import api from '../config/apiConfig'

const getDashboard = () => api.get('/admin/dashboard')

const getArticles = (params) => api.get('/admin/articles', { params })

const getArticle = (slug) => api.get(`/admin/articles/${slug}`)

const deleteArticle = (slug) => api.delete(`/admin/articles/${slug}`)

const getPageContent = (page) => api.get(`/admin/page-contents/${page}`)

const updatePageContent = (page, content) =>
    api.put(`/admin/page-contents/${page}`, { content })

const getDonations = (params) => api.get('/admin/donations', { params })

export const adminService = {
    getDashboard,
    getDonations,
    getArticles,
    getArticle,
    deleteArticle,
    getPageContent,
    updatePageContent,
}
