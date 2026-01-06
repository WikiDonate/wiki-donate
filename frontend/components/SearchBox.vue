<!-- eslint-disable vue/html-self-closing -->
<template>
    <div ref="searchContainer" class="relative w-full">
        <form
            class="flex items-stretch rounded-md bg-white border border-gray-200"
            @submit.prevent="handleSearch"
        >
            <input
                v-model="searchQuery"
                type="text"
                :placeholder="placeholderText"
                class="flex-grow border-0 p-2 pl-5 text-gray-700 focus:outline-none w-full rounded-l-md"
                @input="onInput"
            />
            <button
                type="submit"
                class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-5 hover:from-indigo-500 hover:to-purple-500 transition-all duration-300 flex items-center justify-center rounded-r-md"
            >
                <font-awesome-icon
                    :icon="['fas', 'magnifying-glass']"
                    class="h-4 w-4 text-white"
                />
            </button>
        </form>

        <!-- Suggestions Dropdown -->
        <div
            v-if="showSuggestions"
            id="suggestions-dropdown"
            class="absolute top-full mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg z-50 overflow-hidden animate-fadeIn"
        >
            <ul>
                <!-- Loading State -->
                <template v-if="isSearching">
                    <li class="px-5 py-2 text-gray-500 italic">
                        Searching
                        {{ isOnDonatePage ? 'causes' : 'wikidonate' }}...
                    </li>
                </template>

                <!-- Donate Page - Causes Results -->
                <template v-else-if="isOnDonatePage">
                    <template v-if="searchResults.length > 0">
                        <li
                            v-for="result in searchResults"
                            :key="result.id"
                            class="cursor-pointer px-5 py-2 hover:bg-indigo-50 transition-colors duration-200 border-b border-gray-100 last:border-b-0"
                            @click="selectCause(result)"
                        >
                            <div class="flex items-center">
                                <font-awesome-icon
                                    :icon="['fas', 'magnifying-glass']"
                                    class="h-4 w-4 text-indigo-500 mr-3"
                                />
                                <span class="text-gray-700">{{
                                    result.title
                                }}</span>
                            </div>
                        </li>
                    </template>
                    <template v-else-if="searchQuery.length > 0">
                        <li class="px-5 py-2 text-gray-500 italic">
                            No causes found
                        </li>
                    </template>
                </template>

                <template v-else>
                    <template v-if="suggestions.length > 0">
                        <li
                            v-for="suggestion in suggestions"
                            :key="suggestion.slug"
                            class="cursor-pointer px-5 py-2 hover:bg-indigo-50 transition-colors duration-200 border-b border-gray-100 last:border-b-0"
                            @click="selectSuggestion(suggestion)"
                        >
                            <div class="flex items-center">
                                <font-awesome-icon
                                    :icon="['fas', 'magnifying-glass']"
                                    class="h-4 w-4 text-indigo-500 mr-3"
                                />
                                <span class="text-gray-700">{{
                                    suggestion.title
                                }}</span>
                            </div>
                        </li>
                    </template>
                    <template v-else-if="searchQuery.length > 0">
                        <li class="px-5 py-2 text-gray-500 italic">
                            No search results found
                        </li>
                    </template>
                </template>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { articleService } from '~/services/articleService'
import { useCauseStore } from '~/stores/causeStore'

const router = useRouter()
const route = useRoute()
const causeStore = useCauseStore()

// Reactive state
const searchQuery = ref('')
const suggestions = ref([])
const searchContainer = ref(null)
const isLoadingArticles = ref(false)
const timeoutId = ref(null)

// Computed properties
const isOnDonatePage = computed(() => route.path.startsWith('/donate'))
const isSearching = computed(() =>
    isOnDonatePage.value
        ? causeStore.isSearchingCauses
        : isLoadingArticles.value
)
const placeholderText = computed(() =>
    isOnDonatePage.value ? 'Search causes...' : 'Search wikidonate...'
)
const searchResults = computed(() => causeStore.searchResults)
const showSuggestions = computed(() => searchQuery.value.length > 0)

// Methods
const onInput = () => {
    clearTimeout(timeoutId.value)
    timeoutId.value = setTimeout(fetchSuggestions, 300)
}

const fetchSuggestions = async () => {
    if (isOnDonatePage.value) {
        // Cause search logic
        if (searchQuery.value.length > 1) {
            await causeStore.searchCauses({ title: searchQuery.value })
        } else {
            causeStore.clearSearchResults()
        }
    } else {
        // Article search logic
        if (searchQuery.value.length > 1) {
            isLoadingArticles.value = true
            try {
                const response = await articleService.searchArticles(
                    searchQuery.value
                )
                suggestions.value = response.data
            } finally {
                isLoadingArticles.value = false
            }
        } else {
            suggestions.value = []
        }
    }
}

const clearSearch = () => {
    searchQuery.value = ''
    suggestions.value = []
    causeStore.clearSearchResults()
    clearTimeout(timeoutId.value)
    timeoutId.value = null
}

const handleSearch = () => {
    if (isOnDonatePage.value || !searchQuery.value) {
        return
    } else {
        let searchUrl = `/article/new?title=${encodeURIComponent(searchQuery.value)}`
        const foundSuggestion = suggestions.value.find(
            (suggestion) =>
                suggestion.title.toLowerCase() ===
                searchQuery.value.toLowerCase()
        )

        if (foundSuggestion) {
            searchUrl = `/article?title=${encodeURIComponent(foundSuggestion.slug)}`
        }

        suggestions.value = []
        router.push(searchUrl)
    }
}

const selectSuggestion = (suggestion) => {
    searchQuery.value = suggestion.title
    const searchUrl = `/article?title=${encodeURIComponent(suggestion.slug)}`
    suggestions.value = []
    router.push(searchUrl)
}

const selectCause = (cause) => {
    router.push(`/donate/${cause.id}`)
    clearSearch()
}

const handleClickOutside = (event) => {
    if (
        searchContainer.value &&
        !searchContainer.value.contains(event.target)
    ) {
        clearSearch()
    }
}

// Lifecycle hooks
onMounted(() => {
    document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside)
    clearSearch()
})
</script>
