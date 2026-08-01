<template>
    <div class="language-select-wrapper">
        <div
            v-if="!isLoaded"
            class="flex items-center gap-2 px-2.5 py-1.5 text-sm text-gray-400 border border-gray-200 rounded-lg"
        >
            <font-awesome-icon :icon="['fas', 'language']" class="w-3.5 h-3.5" />
            <span>Loading...</span>
        </div>

        <div v-else class="relative notranslate">
            <button
                class="flex items-center gap-1.5 px-2.5 py-1.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors"
                @click.stop="isOpen = !isOpen"
            >
                <span class="text-base">{{ flags[activeLanguage] || '' }}</span>
                <span>{{ activeLanguage.toUpperCase() }}</span>
                <font-awesome-icon :icon="['fas', 'chevron-down']" class="w-3 h-3 text-gray-400 transition-transform" :class="{ 'rotate-180': isOpen }" />
            </button>

            <ul
                v-if="isOpen"
                class="absolute top-full mt-1 right-0 bg-white border border-gray-200 rounded-lg shadow-lg z-50 max-h-64 overflow-y-auto py-1 min-w-[160px]"
            >
                <li
                    v-for="lang in supportedLanguages"
                    :key="lang"
                    class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 cursor-pointer transition-colors"
                    :class="{ 'bg-indigo-50 text-indigo-700 font-semibold': lang === activeLanguage }"
                    @click="selectLang(lang)"
                >
                    <span class="text-base">{{ flags[lang] || '' }}</span>
                    <span>{{ languageNames[lang] || lang.toUpperCase() }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const { activeLanguage, supportedLanguages, setLanguage, isLoaded } = useGoogleTranslate()

const isOpen = ref(false)

const selectLang = (lang) => {
    isOpen.value = false
    setLanguage(lang)
}

const close = (e) => {
    if (!e.target.closest('.language-select-wrapper')) {
        isOpen.value = false
    }
}

onMounted(() => document.addEventListener('click', close))
onUnmounted(() => document.removeEventListener('click', close))

const flags = {
    'en': '🇬🇧', 'bn': '🇧🇩', 'ur': '🇵🇰', 'hi': '🇮🇳', 'ar': '🇸🇦',
    'es': '🇪🇸', 'fr': '🇫🇷', 'de': '🇩🇪', 'it': '🇮🇹', 'pt': '🇧🇷',
    'ru': '🇷🇺', 'zh-CN': '🇨🇳', 'zh-TW': '🇹🇼', 'ja': '🇯🇵', 'ko': '🇰🇷',
    'tr': '🇹🇷', 'nl': '🇳🇱', 'pl': '🇵🇱', 'sv': '🇸🇪', 'th': '🇹🇭',
    'vi': '🇻🇳', 'id': '🇮🇩', 'ms': '🇲🇾', 'tl': '🇵🇭', 'uk': '🇺🇦',
    'ro': '🇷🇴', 'el': '🇬🇷', 'cs': '🇨🇿', 'hu': '🇭🇺', 'fi': '🇫🇮',
    'da': '🇩🇰', 'no': '🇳🇴', 'he': '🇮🇱', 'fa': '🇮🇷', 'ta': '🇮🇳',
    'te': '🇮🇳', 'mr': '🇮🇳', 'gu': '🇮🇳', 'sw': '🇰🇪', 'am': '🇪🇹',
    'my': '🇲🇲', 'km': '🇰🇭',
}

const languageNames = {
    'en': 'English', 'bn': 'বাংলা', 'ur': 'اردو', 'hi': 'हिन्दी', 'ar': 'العربية',
    'es': 'Español', 'fr': 'Français', 'de': 'Deutsch', 'it': 'Italiano', 'pt': 'Português',
    'ru': 'Русский', 'zh-CN': '中文 (简体)', 'zh-TW': '中文 (繁體)', 'ja': '日本語', 'ko': '한국어',
    'tr': 'Türkçe', 'nl': 'Nederlands', 'pl': 'Polski', 'sv': 'Svenska', 'th': 'ไทย',
    'vi': 'Tiếng Việt', 'id': 'Bahasa Indonesia', 'ms': 'Bahasa Melayu', 'tl': 'Filipino', 'uk': 'Українська',
    'ro': 'Română', 'el': 'Ελληνικά', 'cs': 'Čeština', 'hu': 'Magyar', 'fi': 'Suomi',
    'da': 'Dansk', 'no': 'Norsk', 'he': 'עברית', 'fa': 'فارسی', 'ta': 'தமிழ்',
    'te': 'తెలుగు', 'mr': 'मराठी', 'gu': 'ગુજરાતી', 'sw': 'Kiswahili', 'am': 'አማርኛ',
    'my': 'မြန်မာစာ', 'km': 'ភាសាខ្មែរ',
}
</script>

<style scoped>
.language-select-wrapper :deep(.language-options::-webkit-scrollbar) { width: 4px; }
.language-select-wrapper :deep(.language-options::-webkit-scrollbar-track) { background: #f9fafb; }
.language-select-wrapper :deep(.language-options::-webkit-scrollbar-thumb) { background: #d1d5db; border-radius: 2px; }
</style>
