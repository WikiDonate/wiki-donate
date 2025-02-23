<!-- eslint-disable vue/html-self-closing -->
<template>
    <div ref="searchContainer" class="relative w-full">
        <form class="flex" @submit.prevent="handleSearch">
            <!-- Search Input Field -->
            <input
                v-model="searchQuery"
                type="text"
                placeholder="Search Wikidonate"
                class="flex-grow border border-gray-300 p-2 rounded-l focus:outline-none focus:ring-1 focus:ring-blue-200 w-full"
                @input="fetchSuggestions"
            />

            <!-- Language Dropdown -->
            <GoogleTranslateSelect
                ref="translateSelect"
                class="border border-gray-300 p-1 rounded-l focus:outline-none focus:ring-1 focus:ring-blue-200"
                default-language-code="en"
                default-page-language-code="en"
                :fetch-browser-language="true"
                trigger="click"
            />

            <!-- Submit Button -->
            <button
                type="submit"
                class="bg-gray-400 text-white px-4 rounded-r hover:bg-gray-300"
            >
                Search
            </button>
        </form>

        <!-- Suggestions Dropdown -->
        <div
            v-if="suggestions.length > 0"
            id="suggestionsbar"
            class="absolute top-full mt-1 w-full bg-white border border-gray-300 rounded shadow-md z-50"
        >
            <ul>
                <li
                    v-for="suggestion in suggestions"
                    :key="suggestion"
                    class="cursor-pointer hover:bg-gray-200 px-4 py-2"
                    @click="selectSuggestion(suggestion)"
                >
                    {{ suggestion.title }}
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
