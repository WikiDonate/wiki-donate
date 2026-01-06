import { defineStore } from 'pinia'
import { userService } from '@/services/userService'
import { useToastify } from '@/composables/useToastify'

const { notifyError, notifySuccess } = useToastify()

export const useUserStore = defineStore('user', {
    state: () => ({
        user: null,
        isLoadingUser: false,
        isUpdatingUser: false,
    }),

    actions: {
        async getUserDetails() {
            this.isLoadingUser = true
            try {
                const response = await userService.getUserDetails()
                this.user = response.data
            } catch (error) {
                const message =
                    error?.errors?.length > 0
                        ? error.errors.join(', ')
                        : error?.message || 'Failed to fetch user'
                notifyError(message)
                console.error('Failed to fetch user:', message)
                throw error
            } finally {
                this.isLoadingUser = false
            }
        },

        async updateUser(params) {
            this.isUpdatingUser = true
            try {
                const response = await userService.updateUserDetails(params)
                this.user = response.data
                notifySuccess('Details updated successfully')
            } catch (error) {
                const message =
                    error?.errors?.length > 0
                        ? error.errors.join(', ')
                        : error?.message || 'Failed to update user'
                notifyError(message)
                throw error
            } finally {
                this.isUpdatingUser = false
            }
        },
    },
})
