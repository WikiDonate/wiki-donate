import api from '../config/apiConfig'

const getDashboard = () => api.get('/admin/dashboard')

const getArticles = (params) => api.get('/admin/articles', { params })

const getArticle = (slug) => api.get(`/admin/articles/${slug}`)

const deleteArticle = (slug) => api.delete(`/admin/articles/${slug}`)

const getPageContent = (page) => api.get(`/admin/page-contents/${page}`)

const updatePageContent = (page, content) => api.put(`/admin/page-contents/${page}`, { content })

const getDonations = (params) => api.get('/admin/donations', { params })

const getPayoutAllocations = () => api.get('/admin/payouts/allocations')

const getPayoutHistory = (formulaId, organization) =>
    api.get('/admin/payouts/history', {
        params: { donation_formula_id: formulaId, organization },
    })

const createPayout = (payload) => api.post('/admin/payouts', payload)

const syncPayouts = () => api.post('/admin/payouts/sync')

const getTransactions = (params) => api.get('/admin/transactions', { params })

const getTransactionSummary = (params) => api.get('/admin/transactions/summary', { params })

// Organization registry
const getOrganizations = (params) => api.get('/admin/organizations', { params })

const getOrganization = (id) => api.get(`/admin/organizations/${id}`)

const updateOrganization = (id, payload) => api.put(`/admin/organizations/${id}`, payload)

const verifyOrganization = (id) => api.post(`/admin/organizations/${id}/verify`)

const unverifyOrganization = (id) => api.post(`/admin/organizations/${id}/unverify`)

// Structured audit log
const getTransactionLogs = (params) => api.get('/admin/transaction-logs', { params })

export const adminService = {
    getDashboard,
    getDonations,
    getTransactions,
    getTransactionSummary,
    getArticles,
    getArticle,
    deleteArticle,
    getPageContent,
    updatePageContent,
    getPayoutAllocations,
    getPayoutHistory,
    createPayout,
    syncPayouts,
    getOrganizations,
    getOrganization,
    updateOrganization,
    verifyOrganization,
    unverifyOrganization,
    getTransactionLogs,
}
