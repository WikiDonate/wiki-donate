import { useRuntimeConfig } from '#app'
import axios from 'axios'
const config = useRuntimeConfig()

const instance = axios.create({
    baseURL: config.public.apiUrl,
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
        if (res.status === 401) {
            localStorage.clear()
            window.location.href = '/login'
        }
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
