import { loadStripe } from '@stripe/stripe-js'

let stripePromise = null

export async function useClientStripe() {
    if (!stripePromise) {
        stripePromise = loadStripe(import.meta.env.VITE_STRIPE_PUBLIC_KEY)
    }
    return await stripePromise
}
