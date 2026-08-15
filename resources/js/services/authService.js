import api from '../config/apiConfig'

const login = ({ username, password }) => {
    return api.post('/login', {
        username,
        password,
    })
}

const logout = () => {
    return api.post('/logout')
}

export const authService = {
    login,
    logout,
}
