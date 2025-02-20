import { useAuthStore } from '~/stores/authStore'

export default defineNuxtRouteMiddleware(() => {
    const authStore = useAuthStore()

    // If the user is not authenticated, redirect to the login page
    if (!authStore.isAuthenticated) {
        return navigateTo('/login')
    }
})
