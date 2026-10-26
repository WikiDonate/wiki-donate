import { computed, ref } from 'vue'
import api from '~/config/apiConfig'

/**
 * Shared, cached access to the payment-method registry.
 *
 * Two sources, deliberately separate:
 *  - GET /v1/payment-methods        → active + servable rows (donor checkout)
 *  - GET /v1/admin/payment-methods  → the full registry incl. inactive rows,
 *    used by admin filters/badges so historical donations made with a method
 *    that is now disabled (e.g. Stripe) still show a label instead of a raw
 *    code. Admin-only; non-admins never call it.
 *
 * Every method-aware UI reads from here instead of hardcoding
 * 'paypal'/'stripe'. The module-level refs mean each endpoint is hit once per
 * page load no matter how many components consume it.
 */

const methods = ref([])
const payoutMethods = ref([])
const adminMethods = ref([])
const loading = ref(false)
const loaded = ref(false)
const error = ref('')

let inflight = null
let adminInflight = null

function normalize(rows) {
    return (Array.isArray(rows) ? rows : []).map((row) => ({
        code: String(row.code ?? ''),
        label: row.label || String(row.code ?? ''),
        type: row.type || 'income',
        sort_order: Number(row.sort_order ?? 0),
        // Browser-safe credential (PayPal client id / Stripe publishable key),
        // served from the payment_methods registry instead of a VITE_ build var.
        public_key: row.public_key || '',
        capabilities: row.capabilities ?? {},
        // Only the admin registry carries these; public rows are always active.
        active: row.active ?? true,
        available: row.available ?? true,
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

async function fetchAdminRegistry() {
    const response = await api.get('/admin/payment-methods')
    if (!response?.success) {
        throw new Error(response?.message || 'Failed to load payment methods')
    }
    adminMethods.value = normalize(response.data?.methods)
}

/**
 * Load the public registry once. Safe to call from every component's
 * onMounted — concurrent callers share the same in-flight promise.
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

/**
 * Load the admin registry (includes inactive methods). Call from admin pages
 * only — the endpoint requires the Admin role. Failures are swallowed so an
 * older deployment without the endpoint never breaks the page.
 */
async function loadAdminMethods(force = false) {
    if (adminMethods.value.length > 0 && !force) return
    if (adminInflight) return adminInflight

    adminInflight = fetchAdminRegistry()
        .catch(() => {
            // 403 for non-admins / endpoint absent: labels fall back to codes.
        })
        .finally(() => {
            adminInflight = null
        })

    return adminInflight
}

export function usePaymentMethods() {
    /**
     * Every known method (public income ∪ public payout ∪ full admin
     * registry), for transaction filters and labels. Active/servable rows sort
     * first so the picker never leads with a disabled method.
     */
    const allMethods = computed(() => {
        const byCode = new Map()
        for (const method of [...methods.value, ...payoutMethods.value, ...adminMethods.value]) {
            if (!byCode.has(method.code)) byCode.set(method.code, method)
        }
        return [...byCode.values()].sort((a, b) => {
            if (a.active !== b.active) return a.active ? -1 : 1
            return a.sort_order - b.sort_order
        })
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
        const variants = { paypal: 'info', stripe: 'purple', bkash: 'amber', unknown: 'warning' }
        return variants[String(code ?? '').toLowerCase()] || 'success'
    }

    return {
        methods,
        payoutMethods,
        adminMethods,
        allMethods,
        loading,
        loaded,
        error,
        loadPaymentMethods,
        loadAdminMethods,
        hasMethod,
        methodLabel,
        methodVariant,
    }
}
