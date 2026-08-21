import axios from 'axios'

const instance = axios.create({
    baseURL: import.meta.env.VITE_API_URL || '/api/v1',
    headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
    },
})

instance.interceptors.request.use(
    (config) => {
        const accessToken = localStorage.getItem('token')
        if (accessToken) {
            config.headers.Authorization = `Bearer ${accessToken}`
        }
        return config
    },
    () => {
        return Promise.reject({
            status: false,
            message: 'Server Error',
            errors: ['Server not responding'],
        })
    }
)

instance.interceptors.response.use(
    (res) => {
        return res.data
    },
    (error) => {
        if (error.response) {
            if (error.response.status === 401) {
                localStorage.clear()
                window.location.href = '/login'
            }
            return Promise.reject(error.response.data)
        }

        return Promise.reject({
            status: false,
            message: 'Server Error',
            errors: ['Server not responding'],
        })
    }
)

export default instance
