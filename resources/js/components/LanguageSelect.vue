<template>
    <div class="language-select-wrapper">
        <div
            v-if="isLoadingLocale"
            class="flex items-center gap-2 px-2.5 py-1.5 text-sm text-gray-400 border border-gray-200 rounded-lg"
        >
            <font-awesome-icon :icon="['fas', 'language']" class="w-3.5 h-3.5" />
            <span>{{ t('common.loading') }}</span>
        </div>

        <div v-else class="relative">
            <button
                class="flex items-center gap-1.5 px-2.5 py-1.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors"
                @click.stop="isOpen = !isOpen"
            >
                <span class="text-base">{{ languageFlags[activeLocale] || '' }}</span>
                <span>{{ activeLocale.toUpperCase() }}</span>
                <font-awesome-icon
                    :icon="['fas', 'chevron-down']"
                    class="w-3 h-3 text-gray-400 transition-transform"
                    :class="{ 'rotate-180': isOpen }"
                />
            </button>

            <ul
                v-if="isOpen"
                class="absolute top-full mt-1 right-0 bg-white border border-gray-200 rounded-lg shadow-lg z-50 max-h-64 overflow-y-auto py-1 min-w-[160px]"
            >
                <li
                    v-for="lang in supportedLocales"
                    :key="lang"
                    class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 cursor-pointer transition-colors"
                    :class="{
                        'bg-indigo-50 text-indigo-700 font-semibold': lang === activeLocale,
                    }"
                    @click="selectLang(lang)"
                >
                    <span class="text-base">{{ languageFlags[lang] || '' }}</span>
                    <span>{{ languageNames[lang] || lang.toUpperCase() }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
    import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { languageFlags, languageNames, rtlLanguages, setLocale, supportedLocales } from '~/i18n'

    const { locale, t } = useI18n()
    const isOpen = ref(false)
    const isLoadingLocale = ref(false)

    const activeLocale = computed(() => locale.value)

    const applyDocumentLanguage = (lang) => {
        if (typeof document === 'undefined') return
        document.documentElement.setAttribute('lang', lang)
        document.documentElement.setAttribute('dir', rtlLanguages.includes(lang) ? 'rtl' : 'ltr')
    }

    applyDocumentLanguage(locale.value)
    watch(locale, (lang) => applyDocumentLanguage(lang))

    const selectLang = async (lang) => {
        isOpen.value = false
        if (lang === locale.value) return
        isLoadingLocale.value = true
        try {
            await setLocale(lang)
        } catch (error) {
            if (import.meta.env.DEV) console.error('[i18n] Failed to switch locale:', error)
        } finally {
            isLoadingLocale.value = false
        }
    }

    const close = (e) => {
        if (!e.target.closest('.language-select-wrapper')) {
            isOpen.value = false
        }
    }

    onMounted(() => document.addEventListener('click', close))
    onUnmounted(() => document.removeEventListener('click', close))
</script>

<style scoped>
    .language-select-wrapper :deep(.language-options::-webkit-scrollbar) {
        width: 4px;
    }
    .language-select-wrapper :deep(.language-options::-webkit-scrollbar-track) {
        background: #f9fafb;
    }
    .language-select-wrapper :deep(.language-options::-webkit-scrollbar-thumb) {
        background: #d1d5db;
        border-radius: 2px;
    }
</style>
