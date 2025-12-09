<template>
    <main
        class="w-full flex flex-col items-center justify-center px-2 sm:px-4 py-8"
    >
        <!-- Header with logo -->
        <div class="w-full max-w-4xl flex flex-col items-center mb-8">
            <div class="flex items-center justify-center mb-2">
                <div
                    class="w-12 h-12 lg:w-16 lg:h-16 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg mr-3"
                >
                    <font-awesome-icon
                        :icon="['fas', 'hands-praying']"
                        class="h-8 w-8 lg:h-10 lg:w-10 text-white"
                    />
                </div>
                <h1
                    class="font-bold text-2xl sm:text-3xl lg:text-5xl bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-purple-600"
                >
                    WikiDonate
                </h1>
            </div>
            <p class="text-sm sm:text-base text-gray-600 text-center max-w-md">
                Discover charities and make a difference through knowledge
            </p>
        </div>

        <!-- Search -->
        <div
            class="w-full max-w-2xl mx-auto mb-10 px-2 sm:px-4 transform transition-all duration-300 hover:scale-[1.02]"
        >
            <SearchBoxHome ref="searchBox" />
        </div>

        <!-- Language selection -->
        <div class="w-full max-w-4xl mx-auto px-2 sm:px-4">
            <h2
                class="text-center text-gray-700 font-medium mb-5 text-base sm:text-lg"
            >
                Choose your language
            </h2>
            <div
                class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4"
            >
                <button
                    v-for="lang in languages"
                    :key="lang.code"
                    class="bg-white p-3 sm:p-4 rounded-xl shadow-sm hover:shadow-md transition-all duration-300 flex flex-col items-center group hover:-translate-y-1 border border-gray-100 hover:border-indigo-200"
                    @click="changeLanguage(lang.code)"
                >
                    <span
                        class="text-base sm:text-lg font-medium text-gray-800 group-hover:text-indigo-600 transition-colors"
                    >
                        {{ lang.name }}
                    </span>
                    <span class="text-xs uppercase text-gray-500 mt-1">
                        {{ lang.code }}
                    </span>
                </button>
            </div>
        </div>
    </main>
</template>

<script setup>
useHead({
    title: 'WikiDonate - Discover Charities',
})

definePageMeta({
    layout: 'full-layout',
})

const router = useRouter()
const searchBox = ref(null)

// Configure your supported languages
const languages = [
    { code: 'en', name: 'English' },
    { code: 'es', name: 'Spanish' },
    { code: 'fr', name: 'French' },
    { code: 'de', name: 'German' },
    { code: 'ar', name: 'Arabic' },
    { code: 'zh-CN', name: 'Chinese' },
    { code: 'ja', name: 'Japanese' },
    { code: 'pt-BR', name: 'Portuguese' },
    { code: 'ru', name: 'Russian' },
]

const changeLanguage = (langCode) => {
    const attempt = () => {
        const googleTranslateSelect = document.querySelector('.goog-te-combo')
        if (googleTranslateSelect) {
            googleTranslateSelect.value = langCode
            googleTranslateSelect.dispatchEvent(new Event('change'))
            searchBox.value.setValueOnLanguageChange(langCode)
            router.push('/main')
        } else {
            setTimeout(attempt, 50)
        }
    }
    attempt()
}
</script>
