import { ref, readonly } from 'vue'

const defaultLanguage = 'en'
const supportedLanguages = [
    'en',
    'bn',
    'ur',
    'hi',
    'ar',
    'es',
    'fr',
    'de',
    'it',
    'pt',
    'ru',
    'zh-CN',
    'zh-TW',
    'ja',
    'ko',
    'tr',
    'nl',
    'pl',
    'sv',
    'th',
    'vi',
    'id',
    'ms',
    'tl',
    'uk',
    'ro',
    'el',
    'cs',
    'hu',
    'fi',
    'da',
    'no',
    'he',
    'fa',
    'ta',
    'te',
    'mr',
    'gu',
    'sw',
    'am',
    'my',
    'km',
]

const activeLanguage = ref(defaultLanguage)
const isLoaded = ref(false)

const deleteGoogtransCookie = () => {
    const cookieName = 'googtrans'
    const expiry = 'expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;'
    document.cookie = `${cookieName}=; ${expiry}`
    document.cookie = `${cookieName}=; ${expiry} domain=${window.location.hostname};`
    const baseDomain = window.location.hostname.split('.').slice(-2).join('.')
    if (baseDomain !== window.location.hostname) {
        document.cookie = `${cookieName}=; ${expiry} domain=.${baseDomain};`
    }
}

const updateGoogleTranslate = (lang) => {
    if (!isLoaded.value) return
    const select = document.querySelector('.goog-te-combo')
    if (select) {
        select.value = lang
        select.dispatchEvent(new Event('change'))
    }
}

const setLanguage = (lang) => {
    if (!supportedLanguages.includes(lang)) {
        console.warn(`[google-translate] Unsupported language: ${lang}.`)
        return
    }

    if (lang === defaultLanguage) {
        deleteGoogtransCookie()
        if (activeLanguage.value !== defaultLanguage) {
            location.reload()
        }
        activeLanguage.value = defaultLanguage
        return
    }

    if (lang !== activeLanguage.value) {
        activeLanguage.value = lang
        updateGoogleTranslate(lang)
        document.cookie = `googtrans=/en/${lang};path=/;`
    }
}

const initializeGoogleTranslate = () => {
    if (!window.google?.translate?.TranslateElement) return
    // eslint-disable-next-line no-new
    new window.google.translate.TranslateElement(
        {
            pageLanguage: defaultLanguage,
            includedLanguages: supportedLanguages.join(','),
            autoDisplay: false,
            multilanguagePage: false,
            layout:
                window.google.translate.TranslateElement.InlineLayout.VERTICAL,
        },
        'nuxt_translate_element'
    )
    isLoaded.value = true
    updateGoogleTranslate(activeLanguage.value)
}

const loadGoogleTranslate = () => {
    if (isLoaded.value) return
    if (document.querySelector('#google-translate-script')) return
    window.googleTranslateElementInit = initializeGoogleTranslate
    if (window.google?.translate?.TranslateElement) {
        initializeGoogleTranslate()
        return
    }
    const script = document.createElement('script')
    script.id = 'google-translate-script'
    script.src =
        '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit'
    script.async = true
    script.defer = true
    script.onload = () => {
        initializeGoogleTranslate()
    }
    script.onerror = () => {
        console.error('[google-translate] Failed to load Google Translate script.')
    }
    document.body.appendChild(script)
}

let initialized = false

export function initGoogleTranslate() {
    if (initialized) return
    initialized = true

    if (typeof window !== 'undefined') {
        const cookieLang = document.cookie
            .split('; ')
            .find((row) => row.startsWith('googtrans='))
            ?.split('=')[1]
            ?.split('/')[2]
        if (cookieLang && supportedLanguages.includes(cookieLang)) {
            activeLanguage.value = cookieLang
        }
        loadGoogleTranslate()
    }
}

export function useGoogleTranslate() {
    return {
        activeLanguage: readonly(activeLanguage),
        supportedLanguages: readonly(supportedLanguages),
        setLanguage,
        isLoaded: readonly(isLoaded),
    }
}
