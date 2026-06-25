import api from '../config/apiConfig'

const register = (params) => {
    return api.post('/user', params)
}

const forgotPassword = (params) => {
    return api.post('/forgotPassword', params)
}

const changePassword = (params) => {
    return api.post('/changePassword', params)
}

const updateNotifications = (params) => {
    return api.post('/user/notifications', params)
}

const getNotifications = () => {
    return api.get('/user/notifications')
}

const getUserPage = (slug) => {
    return api.get(`/articles/${slug}`)
}
const getUserDetails = () => {
    return api.get('/user/get')
}

const updateUserDetails = (params) => {
    return api.put('/user/update', params)
}

const verifyEmail = (id, hash) => {
    return api.get(`/email/verify/${id}/${hash}`)
}

const resendVerificationEmail = (params) => {
    return api.post('/email/resend-by-email', params)
}

export const userService = {
    register,
    forgotPassword,
    changePassword,
    updateNotifications,
    getNotifications,
    getUserPage,
    getUserDetails,
    updateUserDetails,
    verifyEmail,
    resendVerificationEmail,
}
