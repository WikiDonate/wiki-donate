export default defineNuxtConfig({
    future: {
        compatibilityVersion: 4,
    },
    compatibilityDate: '2024-04-03',
    devtools: { enabled: true },
    css: [
        '~/assets/css/main.css',
        'vue-toast-notification/dist/theme-default.css',
    ],
    plugins: ['~/plugins/fontawesome.js'],
    modules: [
        '@nuxt/eslint',
        
        '@pinia/nuxt',
        'pinia-plugin-persistedstate/nuxt',
        '@unlok-co/nuxt-stripe',
        'nuxt-google-translate',
    ],
    googleTranslate: {
        defaultLanguage: 'en',
        supportedLanguages: [
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
        ],
    },
    vite: {
        optimizeDeps: {
            include: [
                '@vue/devtools-core',
                '@vue/devtools-kit',
                '@fortawesome/fontawesome-svg-core',
                '@fortawesome/vue-fontawesome',
                '@fortawesome/free-brands-svg-icons',
                '@fortawesome/free-regular-svg-icons',
                '@fortawesome/free-solid-svg-icons',
                '@vueup/vue-quill',
                'interactjs',
                'axios',
                'vue-toast-notification',
                'yup',
            ],
        },
    },
    build: {
        transpile: ['vee-validate'],
    },
    // Nuxt 4 default srcDir is 'app/'
    // Removing custom 'dir' config as Nuxt 4 handles this via app/ directory structure
    postcss: {
        plugins: {
            tailwindcss: {},
            autoprefixer: {},
        },
    },
    ssr: false,
    runtimeConfig: {
        public: {
            apiUrl:
                process.env.NUXT_PUBLIC_API_URL ||
                'http://localhost:8000/api/v1',

            recaptchaSiteKey: process.env.NUXT_PUBLIC_RECAPTCHA_SITE_KEY || '',
        },
    },
    stripe: {
        client: {
            key: process.env.NUXT_STRIPE_PUBLIC_KEY,
        },
    },
})
