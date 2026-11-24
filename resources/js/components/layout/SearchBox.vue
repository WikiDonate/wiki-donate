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
                :placeholder="t('search.placeholder')"
                class="grow border-0 p-2 pl-5 text-gray-700 focus:outline-none w-full rounded-l-md"
                autocomplete="off"
                @input="onInput"
                @keydown.down.prevent="highlightNext"
                @keydown.up.prevent="highlightPrev"
                @keydown.enter.prevent="selectHighlighted"
                @keydown.escape="clearSearch"
            />
            <button
                type="submit"
                class="bg-linear-to-r from-indigo-600 to-purple-600 text-white px-5 hover:from-indigo-500 hover:to-purple-500 transition-all duration-300 flex items-center justify-center rounded-r-md"
            >
                <font-awesome-icon :icon="['fas', 'magnifying-glass']" class="h-4 w-4 text-white" />
            </button>
        </form>

        <div
            v-if="showSuggestions"
            id="suggestions-dropdown"
            class="absolute top-full mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg z-50 overflow-hidden animate-fadeIn"
        >
            <ul>
                <template v-if="isSearching">
                    <li class="px-5 py-2 text-gray-500 italic">{{ t('search.searching') }}</li>
                </template>

                <template v-else>
                    <template v-if="suggestions.length > 0">
                        <li
                            v-for="(suggestion, index) in suggestions"
                            :key="suggestion.slug"
                            class="cursor-pointer px-5 py-2 transition-colors duration-200 border-b border-gray-100 last:border-b-0"
                            :class="
                                index === highlightedIndex ? 'bg-indigo-50' : 'hover:bg-indigo-50'
                            "
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
                    </template>
                    <template v-else-if="searchQuery.length > 0">
                        <li class="px-5 py-2 text-gray-500 italic">{{ t('search.noResults') }}</li>
                    </template>
                </template>
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
    const isSearching = ref(false)
    const timeoutId = ref(null)
    const highlightedIndex = ref(-1)

    const showSuggestions = ref(false)

    const onInput = () => {
        showSuggestions.value = searchQuery.value.length > 0
        clearTimeout(timeoutId.value)
        timeoutId.value = setTimeout(fetchSuggestions, 300)
    }

    const fetchSuggestions = async () => {
        const query = searchQuery.value.trim()
        if (query.length > 1) {
            isSearching.value = true
            try {
                const response = await articleService.searchArticles(query)
                suggestions.value = response.data
                highlightedIndex.value = -1
            } finally {
                isSearching.value = false
            }
        } else {
            suggestions.value = []
            highlightedIndex.value = -1
        }
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

    const clearSearch = () => {
        searchQuery.value = ''
        suggestions.value = []
        showSuggestions.value = false
        highlightedIndex.value = -1
        clearTimeout(timeoutId.value)
        timeoutId.value = null
    }

    const handleSearch = async () => {
        const query = searchQuery.value.trim()
        if (!query) return

        let searchUrl = `/article/new?title=${encodeURIComponent(query)}`
        const foundSuggestion = suggestions.value.find(
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

        clearSearch()
        router.push(searchUrl)
    }

    const selectSuggestion = (suggestion) => {
        // Clear the input after the article is chosen so the user starts fresh.
        searchQuery.value = ''
        suggestions.value = []
        showSuggestions.value = false
        highlightedIndex.value = -1
        clearTimeout(timeoutId.value)
        timeoutId.value = null
        router.push(`/article?title=${encodeURIComponent(suggestion.slug)}`)
    }

    const handleClickOutside = (event) => {
        if (searchContainer.value && !searchContainer.value.contains(event.target)) {
            suggestions.value = []
            showSuggestions.value = false
            highlightedIndex.value = -1
        }
    }

    onMounted(() => {
        document.addEventListener('click', handleClickOutside)
    })

    onBeforeUnmount(() => {
        document.removeEventListener('click', handleClickOutside)
        clearTimeout(timeoutId.value)
    })
</script>
