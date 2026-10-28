import { loadStripe } from '@stripe/stripe-js'

/**
 * Stripe.js loader.
 *
 * The publishable key is public by design and now comes from the
 * payment_methods registry (GET /v1/payment-methods/{code}/public-key), not
 * from a VITE_ build variable — changing the key no longer needs a rebuild.
 */
const cache = new Map()

export async function useClientStripe(publishableKey) {
    if (!publishableKey) {
        throw new Error('Stripe publishable key is not configured')
    }

    if (!cache.has(publishableKey)) {
        cache.set(publishableKey, loadStripe(publishableKey))
    }

    return await cache.get(publishableKey)
}

export default useClientStripe
