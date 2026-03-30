import { defineStore } from 'pinia'
import { ref } from 'vue'
import { userService } from '@/services/userService'
import { useToastify } from '@/composables/useToastify'

export const useUserStore = defineStore('user', () => {
    const { notifyError, notifySuccess } = useToastify()

    const user = ref(null)
    const isLoadingUser = ref(false)
    const isUpdatingUser = ref(false)

    async function getUserDetails() {
        isLoadingUser.value = true
        try {
            const response = await userService.getUserDetails()
            user.value = response.data
        } catch (error) {
            const message =
                error?.errors?.length > 0
                    ? error.errors.join(', ')
                    : error?.message || 'Failed to fetch user'
            notifyError(message)
            console.error('Failed to fetch user:', message)
            throw error
        } finally {
            isLoadingUser.value = false
        }
    }

    async function updateUser(params) {
        isUpdatingUser.value = true
        try {
            const response = await userService.updateUserDetails(params)
            user.value = response.data
            notifySuccess('Details updated successfully')
        } catch (error) {
            const message =
                error?.errors?.length > 0
                    ? error.errors.join(', ')
                    : error?.message || 'Failed to update user'
            notifyError(message)
            throw error
        } finally {
            isUpdatingUser.value = false
        }
    }

    return {
        user,
        isLoadingUser,
        isUpdatingUser,
        getUserDetails,
        updateUser,
    }
})
