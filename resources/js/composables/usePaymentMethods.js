import { computed, ref } from 'vue'
import api from '~/config/apiConfig'

/**
 * Shared, cached access to the active payment-method registry
 * (GET /v1/payment-methods).
 *
 * Every method-aware UI (admin filters, badges, payout rails, donor modal)
 * reads from here instead of hardcoding 'paypal'/'stripe'. The module-level
 * refs mean the endpoint is hit once per page load no matter how many
 * components consume it.
 */

const methods = ref([])
const payoutMethods = ref([])
const loading = ref(false)
const loaded = ref(false)
const error = ref('')

let inflight = null

function normalize(rows) {
    return (Array.isArray(rows) ? rows : []).map((row) => ({
        code: String(row.code ?? ''),
        label: row.label || String(row.code ?? ''),
        type: row.type || 'income',
        sort_order: Number(row.sort_order ?? 0),
        capabilities: row.capabilities ?? {},
    }))
}

async function fetchRegistry() {
    const response = await api.get('/payment-methods')
    if (!response?.success) {
        throw new Error(response?.message || 'Failed to load payment methods')
    }
    methods.value = normalize(response.data?.methods)
    payoutMethods.value = normalize(response.data?.payout_methods)
    loaded.value = true
    error.value = ''
}

/**
 * Load the registry once. Safe to call from every component's onMounted —
 * concurrent callers share the same in-flight promise.
 */
async function loadPaymentMethods(force = false) {
    if (loaded.value && !force) return
    if (inflight) return inflight

    loading.value = true
    inflight = fetchRegistry()
        .catch((e) => {
            error.value = e?.message || 'Failed to load payment methods'
            // Never throw: a registry outage must not blank an admin page.
        })
        .finally(() => {
            loading.value = false
            inflight = null
        })

    return inflight
}

export function usePaymentMethods() {
    /** Every active method (income ∪ payout), for transaction filters. */
    const allMethods = computed(() => {
        const byCode = new Map()
        for (const method of [...methods.value, ...payoutMethods.value]) {
            if (!byCode.has(method.code)) byCode.set(method.code, method)
        }
        return [...byCode.values()].sort((a, b) => a.sort_order - b.sort_order)
    })

    const hasMethod = (code) =>
        allMethods.value.some((method) => method.code === String(code ?? '').toLowerCase())

    /** Human label for a code, falling back to the raw code (never blank). */
    const methodLabel = (code) => {
        const normalized = String(code ?? '').toLowerCase()
        const match = allMethods.value.find((method) => method.code === normalized)
        return match?.label || (normalized ? normalized : '—')
    }

    /** Stable badge colour per method code. */
    const methodVariant = (code) => {
        const variants = { paypal: 'info', stripe: 'purple', bkash: 'amber' }
        return variants[String(code ?? '').toLowerCase()] || 'success'
    }

    return {
        methods,
        payoutMethods,
        allMethods,
        loading,
        loaded,
        error,
        loadPaymentMethods,
        hasMethod,
        methodLabel,
        methodVariant,
    }
}
