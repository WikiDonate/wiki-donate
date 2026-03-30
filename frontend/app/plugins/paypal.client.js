import { loadScript } from '@paypal/paypal-js'

let paypal

export default defineNuxtPlugin(async () => {
    const config = useRuntimeConfig()

    try {
        paypal = await loadScript({
            'client-id': config.public.paypalClientId,
            currency: 'USD',
            intent: 'capture',
            'disable-funding': 'credit,card', // Optional: disable specific funding methods
        })

        return {
            provide: { paypal },
        }
    } catch (error) {
        console.warn(
            'PayPal SDK failed to load. PayPal features may be unavailable:',
            error.message
        )
        return {
            provide: { paypal: null },
        }
    }
})
