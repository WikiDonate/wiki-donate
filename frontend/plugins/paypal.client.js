// import { loadScript } from '@paypal/paypal-js'

// let paypal

// export default defineNuxtPlugin(async (nuxtApp) => {
//     const config = useRuntimeConfig()

//     try {
//         paypal = await loadScript({
//             'client-id': config.public.paypalClientId,
//             currency: 'USD',
//             intent: 'capture',
//             'disable-funding': 'credit,card', // Optional: disable specific funding methods
//         })

//         return {
//             provide: { paypal },
//         }
//     } catch (error) {
//         console.error('Failed to load PayPal JS SDK:', error)
//         throw error // Or handle gracefully
//     }
// })
