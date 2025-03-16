<template>
    <main class="w-full mx-auto">
        <!-- Logo -->
        <div class="flex justify-center justify-items-center">
            <span class="font-bold text-2xl lg:text-4xl lg:pl-4"
                >WikiDonate</span
            >
        </div>

        <!-- Search -->
        <div class="w-full sm:w-1/2 mx-auto p-4 flex">
            <SearchBoxHome ref="searchBox" />
        </div>

        <!-- Language buttons -->
        <div class="w-full sm:w-1/2 mx-auto p-5 grid grid-cols-3 gap-3">
            <button
                v-for="lang in languages"
                :key="lang.code"
                class="bg-gray-200 p-2 rounded-md cursor-pointer hover:bg-gray-300 transition-colors"
                @click="changeLanguage(lang.code)"
            >
                {{ lang.name }}
            </button>
        </div>
    </main>
</template>

<script setup>
useHead({
    title: 'Home',
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
