import { install } from 'vue3-recaptcha-v2'

export default defineNuxtPlugin((nuxtApp) => {
    const config = useRuntimeConfig()
    nuxtApp.vueApp.use(install, {
        sitekey: config.public.recaptchaSiteKey,
        cnDomains: false,
    })
})
