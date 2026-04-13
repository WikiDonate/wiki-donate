import { ref } from 'vue'
import { charityService } from '~/services/charityService'

export function usePayPalCharities() {
    const charities = ref([])
    const isLoading = ref(false)
    const error = ref(null)

    const fetchCharities = async (keyword = '') => {
        isLoading.value = true
        error.value = null

        try {
            const response = await charityService.searchCharities(keyword)

            if (response.success && response.data) {
                charities.value = response.data.map((charity) => ({
                    id: charity.id,
                    name: charity.name,
                    ein: charity.ein,
                    category: charity.category,
                    label: charity.name,
                    value: charity.id,
                }))
            } else {
                charities.value = []
            }
        } catch (e) {
            error.value = e.message || 'Failed to fetch charities'
            charities.value = []
        } finally {
            isLoading.value = false
        }
    }

    const searchCharities = async (query) => {
        if (!query || query.trim() === '') {
            charities.value = []
            return
        }
        await fetchCharities(query.trim())
    }

    return {
        charities,
        isLoading,
        error,
        fetchCharities,
        searchCharities,
    }
}
