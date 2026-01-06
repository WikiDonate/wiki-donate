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
                placeholder="Search Wikidonate..."
                class="flex-grow border-0 p-3 pl-5 text-gray-700 focus:outline-none w-full rounded-l-full"
                @input="fetchSuggestions"
            />

            <!-- Language Dropdown -->
            <div
                class="flex items-center justify-center border-l border-gray-200"
            >
                <GoogleTranslateSelect
                    ref="translateSelect"
                    class="px-0 sm:px-2 uppercase text-sm font-semibold text-gray-700 focus:outline-none"
                    default-language-code="en"
                    default-page-language-code="en"
                    :fetch-browser-language="true"
                    :languages="languagesList"
                    trigger="click"
                />
            </div>

            <!-- Submit Button -->
            <button
                type="submit"
                class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-5 hover:from-indigo-500 hover:to-purple-500 transition-all duration-300 flex items-center justify-center rounded-r-full"
            >
                <font-awesome-icon
                    :icon="['fas', 'magnifying-glass']"
                    class="h-4 w-4 text-white"
                />
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
                    v-for="suggestion in suggestions"
                    :key="suggestion"
                    class="cursor-pointer px-5 py-3 hover:bg-indigo-50 transition-colors duration-200 border-b border-gray-100 last:border-b-0"
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
            </ul>
        </div>
    </div>
</template>

<script setup>
import GoogleTranslateSelect from '@google-translate-select/vue3'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { articleService } from '~/services/articleService'
import languagesList from '~/static/languages.json'

const router = useRouter()
const searchQuery = ref('')
const suggestions = ref([])
const searchContainer = ref(null)
const selectedSuggestions = ref(null)
const translateSelect = ref(null)

const fetchSuggestions = async () => {
    if (searchQuery.value.length > 1) {
        try {
            selectedSuggestions.value = []
            const response = await articleService.searchArticles(
                searchQuery.value
            )
            suggestions.value = response.data
            selectedSuggestions.value = response.data
        } catch (error) {
            console.error(error)
        }
    } else {
        suggestions.value = []
    }
}

const handleSearch = () => {
    if (searchQuery.value) {
        let searchUrl = `/article/new?title=${encodeURIComponent(searchQuery.value)}`
        const foundSuggestion = selectedSuggestions.value.find(
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

const handleClickOutside = (event) => {
    if (
        searchContainer.value &&
        !searchContainer.value.contains(event.target)
    ) {
        suggestions.value = []
    }
}

const setValueOnLanguageChange = (value) => {
    translateSelect.value.selectedLanguageCode = value
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside)
})

defineExpose({
    setValueOnLanguageChange,
})
</script>
