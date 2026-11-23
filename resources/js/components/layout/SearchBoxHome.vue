<!-- eslint-disable vue/html-self-closing -->
<template>
    <div ref="searchContainer" class="relative w-full max-w-2xl mx-auto">
        <form
            class="flex items-stretch shadow-lg rounded-full bg-white border border-gray-200"
            @submit.prevent="handleSearch"
        >
            <!-- Search Input Field -->
            <input
                v-model="searchQuery"
                type="text"
                :placeholder="t('search.placeholder')"
                class="grow border-0 p-3 pl-5 text-gray-700 focus:outline-none w-full rounded-l-full"
                autocomplete="off"
                @input="fetchSuggestions"
                @keydown.down.prevent="highlightNext"
                @keydown.up.prevent="highlightPrev"
                @keydown.enter.prevent="selectHighlighted"
                @keydown.escape="clearSuggestions"
            />

            <!-- Language Dropdown -->
            <div class="flex items-center border-l border-gray-200 px-1">
                <LanguageSelect />
            </div>

            <!-- Submit Button -->
            <button
                type="submit"
                class="bg-linear-to-r from-indigo-600 to-purple-600 text-white px-5 hover:from-indigo-500 hover:to-purple-500 transition-all duration-300 flex items-center justify-center rounded-r-full"
            >
                <font-awesome-icon :icon="['fas', 'magnifying-glass']" class="h-4 w-4 text-white" />
            </button>
        </form>

        <!-- Suggestions Dropdown -->
        <div
            v-if="suggestions.length > 0"
            id="suggestionsbar"
            class="absolute top-full mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg z-50 overflow-hidden animate-fadeIn"
        >
            <ul>
                <li
                    v-for="(suggestion, index) in suggestions"
                    :key="suggestion.slug || suggestion.title"
                    class="cursor-pointer px-5 py-3 transition-colors duration-200 border-b border-gray-100 last:border-b-0"
                    :class="index === highlightedIndex ? 'bg-indigo-50' : 'hover:bg-indigo-50'"
                    @click="selectSuggestion(suggestion)"
                    @mousemove="highlightedIndex = index"
                >
                    <div class="flex items-center">
                        <font-awesome-icon
                            :icon="['fas', 'magnifying-glass']"
                            class="h-4 w-4 text-indigo-500 mr-3"
                        />
                        <span class="text-gray-700">{{ suggestion.title }}</span>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
    import { onBeforeUnmount, onMounted, ref } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { useRouter } from 'vue-router'
    import { articleService } from '~/services/articleService'

    const { t } = useI18n()
    const router = useRouter()
    const searchQuery = ref('')
    const suggestions = ref([])
    const searchContainer = ref(null)
    const selectedSuggestions = ref(null)
    const highlightedIndex = ref(-1)

    const fetchSuggestions = async () => {
        const query = searchQuery.value.trim()
        if (query.length > 1) {
            try {
                selectedSuggestions.value = []
                const response = await articleService.searchArticles(query)
                suggestions.value = response.data
                selectedSuggestions.value = response.data
                highlightedIndex.value = -1
            } catch (error) {
                if (import.meta.env.DEV) console.error(error)
            }
        } else {
            suggestions.value = []
            highlightedIndex.value = -1
        }
    }

    const clearSuggestions = () => {
        suggestions.value = []
        highlightedIndex.value = -1
    }

    const highlightNext = () => {
        if (suggestions.value.length === 0) return
        highlightedIndex.value = (highlightedIndex.value + 1) % suggestions.value.length
    }

    const highlightPrev = () => {
        if (suggestions.value.length === 0) return
        highlightedIndex.value =
            (highlightedIndex.value - 1 + suggestions.value.length) % suggestions.value.length
    }

    const selectHighlighted = () => {
        const suggestion = suggestions.value[highlightedIndex.value]
        if (suggestion) {
            selectSuggestion(suggestion)
        } else {
            handleSearch()
        }
    }

    const handleSearch = async () => {
        const query = searchQuery.value.trim()
        if (query) {
            let searchUrl = `/article/new?title=${encodeURIComponent(query)}`
            const foundSuggestion = selectedSuggestions.value?.find(
                (suggestion) => suggestion.title.toLowerCase() === query.toLowerCase(),
            )

            if (foundSuggestion) {
                searchUrl = `/article?title=${encodeURIComponent(foundSuggestion.slug)}`
            } else {
                try {
                    const response = await articleService.searchArticles(query)
                    const results = response.data
                    const exactMatch = results.find(
                        (r) => r.title.toLowerCase() === query.toLowerCase(),
                    )
                    if (exactMatch) {
                        searchUrl = `/article?title=${encodeURIComponent(exactMatch.slug)}`
                    }
                } catch {
                    // fall through to new article page
                }
            }

            // Clear the input after the article is chosen so the user starts fresh.
            searchQuery.value = ''
            suggestions.value = []
            selectedSuggestions.value = []
            highlightedIndex.value = -1
            router.push(searchUrl)
        }
    }

    const selectSuggestion = (suggestion) => {
        // Clear the input after the article is chosen so the user starts fresh.
        searchQuery.value = ''
        suggestions.value = []
        selectedSuggestions.value = []
        highlightedIndex.value = -1
        router.push(`/article?title=${encodeURIComponent(suggestion.slug)}`)
    }

    const handleClickOutside = (event) => {
        if (searchContainer.value && !searchContainer.value.contains(event.target)) {
            suggestions.value = []
            highlightedIndex.value = -1
        }
    }

    onMounted(() => {
        document.addEventListener('click', handleClickOutside)
    })

    onBeforeUnmount(() => {
        document.removeEventListener('click', handleClickOutside)
    })
</script>
