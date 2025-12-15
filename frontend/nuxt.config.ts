export default defineNuxtConfig({
    compatibilityDate: '2024-04-03',
    devtools: { enabled: true },
    css: [
        '~/assets/css/main.css',
        'vue-toast-notification/dist/theme-default.css',
    ],
    // plugins: ['~/plugins/fontawesome.js', '~/plugins/paypal.client.js'],
    plugins: ['~/plugins/fontawesome.js'],
    modules: [
        '@nuxt/eslint',
        '@pinia/nuxt',
        'pinia-plugin-persistedstate/nuxt',
        '@unlok-co/nuxt-stripe',
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
                'https://api.wikidonate.org/api/v1',

            recaptchaSiteKey: process.env.NUXT_PUBLIC_RECAPTCHA_SITE_KEY || '',
            paypalClientId: process.env.NUXT_PUBLIC_PAYPAL_CLIENT_ID,
        },
    },
    stripe: {
        client: {
            key: process.env.NUXT_STRIPE_PUBLIC_KEYS,
        },
    },
})
