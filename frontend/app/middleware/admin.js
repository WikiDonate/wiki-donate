import { useAuthStore } from '~/stores/authStore'

export default defineNuxtRouteMiddleware(() => {
    const authStore = useAuthStore()

    if (!authStore.isAuthenticated) {
        return navigateTo('/login')
    }

    if (!authStore.roles?.includes('Admin')) {
        return navigateTo('/main')
    }
})
