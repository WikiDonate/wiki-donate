// stores/cause.js
import { defineStore } from 'pinia'
import { causeService } from '@/services/causeService'
import { useToastify } from '@/composables/useToastify'

const { notifyError, notifySuccess } = useToastify()

export const useCauseStore = defineStore('cause', {
    state: () => ({
        causes: [],
        searchResults: [],
        cause: null,
        isLoadingCauses: false,
        isLoadingCause: false,
        isSearchingCauses: false,
    }),

    actions: {
        async getCauses() {
            this.isLoadingCauses = true
            try {
                const response = await causeService.getCauses()
                this.causes = response.data
            } catch (error) {
                const message =
                    error?.errors?.length > 0
                        ? error.errors.join(', ')
                        : error?.message || 'Failed to fetch causes'
                notifyError(message)
                console.error('getCauses error:', message)
                throw error
            } finally {
                this.isLoadingCauses = false
            }
        },

        async searchCauses(params) {
            this.isSearchingCauses = true
            try {
                const response = await causeService.searchCauses(params)
                this.searchResults = response.data
            } catch (error) {
                const message =
                    error?.errors?.length > 0
                        ? error.errors.join(', ')
                        : error?.message || 'Failed to search causes'
                notifyError(message)
                throw error
            } finally {
                this.isSearchingCauses = false
            }
        },

        async getCause(id) {
            this.isLoadingCause = true
            try {
                const response = await causeService.getCause(id)
                this.cause = response.data
            } catch (error) {
                const message =
                    error?.errors?.length > 0
                        ? error.errors.join(', ')
                        : error?.message || 'Failed to fetch cause'
                notifyError(message)
                console.error('getCause error:', message)
                throw error
            } finally {
                this.isLoadingCause = false
            }
        },

        clearSearchResults() {
            this.searchResults = []
        },
    },
})
