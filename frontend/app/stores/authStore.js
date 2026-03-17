import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useAuthStore = defineStore(
    'auth',
    () => {
        const user = ref(null)
        const roles = ref(null)
        const isAuthenticated = ref(false)

        function login(userData) {
            user.value = userData
            roles.value = userData.roles
            isAuthenticated.value = true
        }

        function logout() {
            user.value = null
            roles.value = null
            isAuthenticated.value = false
        }

        return {
            user,
            roles,
            isAuthenticated,
            login,
            logout,
        }
    },
    {
        persist: true,
    }
)
