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
        </div>

        <!-- Search -->
        <div
            class="w-full max-w-2xl mx-auto mb-10 px-2 sm:px-4 transform transition-all duration-300 hover:scale-[1.02]"
        >
            <SearchBoxHome />
        </div>

        <!-- Language selection -->
        <div class="w-full max-w-4xl mx-auto px-2 sm:px-4">
            <div
                class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4"
            >
                <button
                    v-for="lang in topLanguages"
                    :key="lang.code"
                    class="bg-white p-3 sm:p-4 rounded-xl shadow-sm hover:shadow-md transition-all duration-300 flex flex-col items-center group hover:-translate-y-1 border border-gray-100 hover:border-indigo-200"
                    @click="selectLanguage(lang.code)"
                >
                    <span class="text-2xl mb-1">{{ lang.flag }}</span>
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
useHead({ title: 'WikiDonate - Discover Charities' })

const router = useRouter()
const { setLanguage } = useGoogleTranslate()

const topLanguages = [
    { code: 'en', name: 'English', flag: '🇬🇧' },
    { code: 'es', name: 'Español', flag: '🇪🇸' },
    { code: 'fr', name: 'Français', flag: '🇫🇷' },
    { code: 'de', name: 'Deutsch', flag: '🇩🇪' },
    { code: 'ar', name: 'العربية', flag: '🇸🇦' },
    { code: 'hi', name: 'हिन्दी', flag: '🇮🇳' },
    { code: 'bn', name: 'বাংলা', flag: '🇧🇩' },
    { code: 'zh-CN', name: '中文', flag: '🇨🇳' },
    { code: 'ja', name: '日本語', flag: '🇯🇵' },
    { code: 'pt', name: 'Português', flag: '🇧🇷' },
    { code: 'ru', name: 'Русский', flag: '🇷🇺' },
    { code: 'ur', name: 'اردو', flag: '🇵🇰' },
    { code: 'tr', name: 'Türkçe', flag: '🇹🇷' },
    { code: 'it', name: 'Italiano', flag: '🇮🇹' },
    { code: 'ko', name: '한국어', flag: '🇰🇷' },
]

const selectLanguage = (langCode) => {
    setLanguage(langCode)
    router.push('/main')
}

onMounted(() => {
    const script = document.createElement('script')
    script.src = 'https://aichatbot.devshahaj.com/widget.js'
    script.async = true
    script.id = 'ai-chatbot-script'
    script.setAttribute(
        'data-chatbot-id',
        '805bcbc5-d8ca-49a7-8c0d-c4e0501c9ba0'
    )
    script.setAttribute(
        'data-api-key',
        import.meta.env.VITE_AI_CHATBOT_API_KEY || ''
    )
    script.setAttribute('data-position', 'bottom-right')
    script.setAttribute('data-primary-color', '#3b82f6')
    script.setAttribute('data-secondary-color', '#ffffff')
    script.setAttribute('data-name', 'Wikidonate Support')
    script.setAttribute('data-avatar-url', '')
    document.body.appendChild(script)
})

onBeforeUnmount(() => {
    document.getElementById('ai-chatbot-script')?.remove()
})
</script>
