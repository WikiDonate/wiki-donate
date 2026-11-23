import { ref, watch } from 'vue'

// Google's free dictionary endpoint — the same one scripts/translate_locales.py
// uses. It needs no API key and no DB table; translations are kept in memory
// for the session only.
const TRANSLATE_ENDPOINT = 'https://clients5.google.com/translate_a/t'

// Google expects these codes; our locale list mostly matches, except the two
// Chinese variants which it wants as "zh-CN" / "zh-TW" (already correct here).
const toGoogleCode = (locale) => locale

// Session-scoped memo so switching back to a locale never re-hits the network.
const cache = new Map()

const cacheKey = (target, text) => `${target}:${text}`

async function requestTranslation(text, target, signal) {
    const url =
        `${TRANSLATE_ENDPOINT}?client=dict-chrome-ex&sl=en&tl=${encodeURIComponent(target)}` +
        `&q=${encodeURIComponent(text)}`

    const response = await fetch(url, { signal })
    if (!response.ok) throw new Error(`[translate] HTTP ${response.status}`)

    const data = await response.json()
    // The endpoint returns ["translated"] or [["translated", ...]] depending on input.
    if (Array.isArray(data?.[0])) return data[0][0]
    if (typeof data?.[0] === 'string') return data[0]
    return text
}

/**
 * Translate an article title into the active locale via Google's free endpoint.
 *
 * @param {import('vue').Ref<string>} sourceTitle - reactive source (English) title.
 * @param {import('vue').Ref<string>} locale - active vue-i18n locale code.
 * @returns {{ translatedTitle: import('vue').Ref<string>, isTranslating: import('vue').Ref<boolean> }}
 */
export function useArticleTitleTranslation(sourceTitle, locale) {
    const translatedTitle = ref('')
    const isTranslating = ref(false)
    let controller = null

    const translate = async (text, target) => {
        controller?.abort()
        controller = null

        // English (or empty source) needs no translation — show the original.
        if (!text || !target || target === 'en') {
            translatedTitle.value = ''
            isTranslating.value = false
            return
        }

        const key = cacheKey(target, text)
        if (cache.has(key)) {
            translatedTitle.value = cache.get(key)
            isTranslating.value = false
            return
        }

        const activeController = new AbortController()
        controller = activeController
        isTranslating.value = true

        try {
            const result = await requestTranslation(
                text,
                toGoogleCode(target),
                activeController.signal,
            )
            cache.set(key, result)
            translatedTitle.value = result
        } catch (error) {
            // Aborts are expected on rapid locale/title changes; stay silent.
            if (error.name !== 'AbortError' && import.meta.env.DEV) {
                console.error('[translate] Failed to translate article title:', error)
            }
        } finally {
            if (controller === activeController) {
                controller = null
                isTranslating.value = false
            }
        }
    }

    watch([sourceTitle, locale], ([text, target]) => translate(text, target), { immediate: true })

    return { translatedTitle, isTranslating }
}
