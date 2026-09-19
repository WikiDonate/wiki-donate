import { createI18n } from 'vue-i18n'

export const defaultLocale = 'en'

// Same language list as the old Google Translate widget (googleTranslate.js)
export const supportedLocales = [
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

// Locales that should render right-to-left
export const rtlLanguages = ['ar', 'ur', 'he', 'fa']

export const languageFlags = {
    en: '🇬🇧',
    bn: '🇧🇩',
    ur: '🇵🇰',
    hi: '🇮🇳',
    ar: '🇸🇦',
    es: '🇪🇸',
    fr: '🇫🇷',
    de: '🇩🇪',
    it: '🇮🇹',
    pt: '🇧🇷',
    ru: '🇷🇺',
    'zh-CN': '🇨🇳',
    'zh-TW': '🇹🇼',
    ja: '🇯🇵',
    ko: '🇰🇷',
    tr: '🇹🇷',
    nl: '🇳🇱',
    pl: '🇵🇱',
    sv: '🇸🇪',
    th: '🇹🇭',
    vi: '🇻🇳',
    id: '🇮🇩',
    ms: '🇲🇾',
    tl: '🇵🇭',
    uk: '🇺🇦',
    ro: '🇷🇴',
    el: '🇬🇷',
    cs: '🇨🇿',
    hu: '🇭🇺',
    fi: '🇫🇮',
    da: '🇩🇰',
    no: '🇳🇴',
    he: '🇮🇱',
    fa: '🇮🇷',
    ta: '🇮🇳',
    te: '🇮🇳',
    mr: '🇮🇳',
    gu: '🇮🇳',
    sw: '🇰🇪',
    am: '🇪🇹',
    my: '🇲🇲',
    km: '🇰🇭',
}

export const languageNames = {
    en: 'English',
    bn: 'বাংলা',
    ur: 'اردو',
    hi: 'हिन्दी',
    ar: 'العربية',
    es: 'Español',
    fr: 'Français',
    de: 'Deutsch',
    it: 'Italiano',
    pt: 'Português',
    ru: 'Русский',
    'zh-CN': '中文 (简体)',
    'zh-TW': '中文 (繁體)',
    ja: '日本語',
    ko: '한국어',
    tr: 'Türkçe',
    nl: 'Nederlands',
    pl: 'Polski',
    sv: 'Svenska',
    th: 'ไทย',
    vi: 'Tiếng Việt',
    id: 'Bahasa Indonesia',
    ms: 'Bahasa Melayu',
    tl: 'Filipino',
    uk: 'Українська',
    ro: 'Română',
    el: 'Ελληνικά',
    cs: 'Čeština',
    hu: 'Magyar',
    fi: 'Suomi',
    da: 'Dansk',
    no: 'Norsk',
    he: 'עברית',
    fa: 'فارسی',
    ta: 'தமிழ்',
    te: 'తెలుగు',
    mr: 'मराठी',
    gu: 'ગુજરાતી',
    sw: 'Kiswahili',
    am: 'አማርኛ',
    my: 'မြန်မာစာ',
    km: 'ភាសាខ្មែរ',
}

const STORAGE_KEY = 'wikidonate-locale'
const COOKIE_NAME = 'wikidonate_locale'

function getInitialLocale() {
    if (typeof window === 'undefined') return defaultLocale

    // 1. localStorage
    try {
        const stored = localStorage.getItem(STORAGE_KEY)
        if (stored && supportedLocales.includes(stored)) return stored
    } catch {
        // ignore storage errors
    }

    // 2. Cookie (migrate from old googtrans if present)
    const cookies = document.cookie.split('; ')
    const localeCookie = cookies.find((row) => row.startsWith(`${COOKIE_NAME}=`))
    if (localeCookie) {
        const value = decodeURIComponent(localeCookie.split('=')[1])
        if (supportedLocales.includes(value)) return value
    }
    const oldGt = cookies.find((row) => row.startsWith('googtrans='))
    if (oldGt) {
        const value = oldGt.split('=')[1]?.split('/')[2]
        if (value && supportedLocales.includes(value)) return value
    }

    // 3. Browser language
    const navLang = (navigator.language || 'en').toLowerCase()
    const exact = supportedLocales.find((l) => l.toLowerCase() === navLang)
    if (exact) return exact
    const base = navLang.split('-')[0]
    const baseMatch = supportedLocales.find((l) => l.toLowerCase() === base)
    if (baseMatch) return baseMatch

    return defaultLocale
}

function persistLocale(locale) {
    if (typeof window === 'undefined') return
    try {
        localStorage.setItem(STORAGE_KEY, locale)
    } catch {
        // ignore
    }
    document.cookie = `${COOKIE_NAME}=${encodeURIComponent(locale)}; path=/; max-age=31536000; samesite=Lax`
}

function applyLocaleMeta(locale) {
    if (typeof document === 'undefined') return
    document.documentElement.setAttribute('lang', locale)
    document.documentElement.setAttribute('dir', rtlLanguages.includes(locale) ? 'rtl' : 'ltr')
}

// Lazy-load the locale JSON from resources/js/i18n/locales/<code>.json
function loadLocaleMessages(locale) {
    return import(`./locales/${locale}.json`)
}

const i18n = createI18n({
    legacy: false,
    locale: defaultLocale,
    fallbackLocale: defaultLocale,
    messages: {
        en: {},
    },
})

export async function setLocale(locale) {
    if (!supportedLocales.includes(locale)) return
    if (!i18n.global.te('language.loading', locale)) {
        const messages = await loadLocaleMessages(locale)
        i18n.global.setLocaleMessage(locale, messages.default ?? messages)
    }
    i18n.global.locale.value = locale
    persistLocale(locale)
    applyLocaleMeta(locale)
}

export async function loadInitialLocale() {
    const initial = getInitialLocale()
    await setLocale(initial)
}

export { i18n }
export default i18n
