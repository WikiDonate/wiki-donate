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
    plugins: ['~/plugins/fontawesome.js', '~/plugins/paypal.client.js'],
    modules: [
        '@nuxt/eslint',
        '@pinia/nuxt',
        'pinia-plugin-persistedstate/nuxt',
        '@unlok-co/nuxt-stripe',
    ],
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
                '@paypal/paypal-js',
                'vue3-recaptcha-v2',
                '@vueup/vue-quill',
                'interactjs',
                'axios',
                '@google-translate-select/vue3',
                'vue-toast-notification',
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
            paypalClientId: process.env.NUXT_PUBLIC_PAYPAL_CLIENT_ID,
        },
    },
    stripe: {
        client: {
            key: process.env.NUXT_STRIPE_PUBLIC_KEYS,
        },
    },
})
