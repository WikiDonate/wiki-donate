export default defineNuxtConfig({
    compatibilityDate: '2024-04-03',
    devtools: { enabled: true },
    css: ['~/assets/css/main.css'],
    plugins: ['~/plugins/fontawesome.js'],
    modules: [
        '@nuxt/eslint',
        '@pinia/nuxt',
        'pinia-plugin-persistedstate/nuxt',
    ],
    build: {
        transpile: ['vee-validate'],
    },
    dir: {
        assets: 'assets',
    },
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
})
