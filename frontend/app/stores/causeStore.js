// stores/cause.js
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { causeService } from '@/services/causeService'
import { useToastify } from '@/composables/useToastify'

export const useCauseStore = defineStore('cause', () => {
    const { notifyError } = useToastify()

    const causes = ref([])
    const searchResults = ref([])
    const cause = ref(null)
    const isLoadingCauses = ref(false)
    const isLoadingCause = ref(false)
    const isSearchingCauses = ref(false)

    async function getCauses() {
        isLoadingCauses.value = true
        try {
            const response = await causeService.getCauses()
            causes.value = response.data
        } catch (error) {
            const message =
                error?.errors?.length > 0
                    ? error.errors.join(', ')
                    : error?.message || 'Failed to fetch causes'
            notifyError(message)
            console.error('getCauses error:', message)
            throw error
        } finally {
            isLoadingCauses.value = false
        }
    }

    async function searchCauses(params) {
        isSearchingCauses.value = true
        try {
            const response = await causeService.searchCauses(params)
            searchResults.value = response.data
        } catch (error) {
            const message =
                error?.errors?.length > 0
                    ? error.errors.join(', ')
                    : error?.message || 'Failed to search causes'
            notifyError(message)
            throw error
        } finally {
            isSearchingCauses.value = false
        }
    }

    async function getCause(id) {
        isLoadingCause.value = true
        try {
            const response = await causeService.getCause(id)
            cause.value = response.data
        } catch (error) {
            const message =
                error?.errors?.length > 0
                    ? error.errors.join(', ')
                    : error?.message || 'Failed to fetch cause'
            notifyError(message)
            console.error('getCause error:', message)
            throw error
        } finally {
            isLoadingCause.value = false
        }
    }

    function clearSearchResults() {
        searchResults.value = []
    }

    return {
        causes,
        searchResults,
        cause,
        isLoadingCauses,
        isLoadingCause,
        isSearchingCauses,
        getCauses,
        searchCauses,
        getCause,
        clearSearchResults,
    }
})
