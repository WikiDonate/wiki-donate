<template>
    <main class="w-full mx-auto max-w-2xl mt-6 bg-white rounded-2xl shadow-md overflow-hidden">
        <div class="bg-linear-to-r py-8 px-6 text-center" :class="view.gradient">
            <div
                class="w-16 h-16 mx-auto mb-4 bg-white rounded-full flex items-center justify-center"
            >
                <font-awesome-icon :icon="view.icon" class="w-10 h-10" :class="view.iconClass" />
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-wide">
                {{ view.title }}
            </h1>
            <p class="text-base mt-2" :class="view.subtitleClass">{{ view.subtitle }}</p>
        </div>

        <div class="p-6 sm:p-8 text-center space-y-6">
            <div v-if="loading" class="flex justify-center py-4">
                <LoadingSpinner :text="t('payment.verifyingPayment')" />
            </div>

            <template v-else>
                <!-- Confirmed -->
                <div
                    v-if="state === 'completed'"
                    class="bg-green-50 border border-green-200 rounded-lg p-6"
                >
                    <p class="text-green-700 font-medium mb-1">
                        {{ t('payment.processedMsg') }}
                    </p>
                    <p v-if="sessionData?.amount != null" class="text-gray-600 text-sm">
                        {{ t('payment.amountLabel') }}
                        <span class="font-bold"
                            >{{ formatAmount(sessionData.amount, sessionData.currency) }}
                            {{ (sessionData.currency || 'USD').toUpperCase() }}</span
                        >
                    </p>
                </div>

                <!-- Waiting for the gateway/webhook to confirm -->
                <div
                    v-else-if="state === 'pending'"
                    class="bg-amber-50 border border-amber-200 rounded-lg p-6"
                >
                    <p class="text-amber-700 font-medium mb-1">
                        {{ t('payment.statePendingTitle') }}
                    </p>
                    <p class="text-gray-500 text-sm">{{ t('payment.statePendingMsg') }}</p>
                    <p v-if="reference" class="text-gray-400 text-xs mt-3 font-mono break-all">
                        {{ t('payment.referenceLabel') }}: {{ reference }}
                    </p>
                </div>

                <!-- Refunded -->
                <div
                    v-else-if="state === 'refunded'"
                    class="bg-slate-50 border border-slate-200 rounded-lg p-6"
                >
                    <p class="text-slate-700 font-medium mb-1">
                        {{ t('payment.stateRefundedTitle') }}
                    </p>
                    <p class="text-gray-500 text-sm">{{ t('payment.stateRefundedMsg') }}</p>
                </div>

                <!-- Declined / cancelled / expired -->
                <div v-else-if="isFailure" class="bg-red-50 border border-red-200 rounded-lg p-6">
                    <p class="text-red-700 font-medium mb-1">
                        {{ t('payment.stateFailedTitle') }}
                    </p>
                    <p class="text-gray-500 text-sm">{{ t('payment.stateFailedMsg') }}</p>
                    <p v-if="reference" class="text-gray-400 text-xs mt-3 font-mono break-all">
                        {{ t('payment.referenceLabel') }}: {{ reference }}
                    </p>
                </div>

                <!-- No local record: never claim success we cannot verify -->
                <div v-else class="bg-gray-50 border border-gray-200 rounded-lg p-6">
                    <p class="text-gray-700 font-medium mb-1">
                        {{ t('payment.stateUnknownTitle') }}
                    </p>
                    <p class="text-gray-500 text-sm">{{ t('payment.stateUnknownMsg') }}</p>
                    <p v-if="reference" class="text-gray-400 text-xs mt-3 font-mono break-all">
                        {{ t('payment.referenceLabel') }}: {{ reference }}
                    </p>
                </div>

                <NuxtLink
                    :to="backUrl"
                    class="inline-flex items-center justify-center gap-2 px-8 py-3 rounded-lg bg-linear-to-r from-indigo-600 to-purple-600 text-white font-semibold hover:from-indigo-500 hover:to-purple-500 transition-colors shadow-md"
                >
                    <font-awesome-icon :icon="['fas', 'home']" class="w-4 h-4" />
                    {{ backLabel }}
                </NuxtLink>
            </template>
        </div>
    </main>
</template>

<script setup>
    import { useI18n } from 'vue-i18n'
    import { safeInternalPath } from '~/utils/safeRedirect'
    import api from '~/config/apiConfig'

    const { t } = useI18n()
    const route = useRoute()
    const { loadPaymentMethods } = usePaymentMethods()

    // Lifecycle reported by the status endpoint: completed | pending |
    // failed | cancelled | expired | refunded | unknown.
    const state = ref('unknown')
    const sessionData = ref(null)
    const loading = ref(true)

    // Poll only while the gateway is still deciding. Webhooks normally land
    // within a couple of seconds; this window covers that lag without leaving
    // the donor refreshing manually.
    const MAX_POLLS = 10
    const POLL_INTERVAL_MS = 3000
    let pollTimer = null
    let polls = 0

    // The gateway is always named explicitly by the checkout that redirected
    // here (`?method=<code>`). Never infer it from other query params: a new
    // method must work without touching this page.
    const returnedMethod = computed(() => String(route.query.method || '').toLowerCase())

    // Stripe returns `session_id`, PayPal returns `reference`.
    const reference = computed(() =>
        String(route.query.session_id || route.query.reference || '').trim(),
    )

    const isFailure = computed(() => ['failed', 'cancelled', 'expired'].includes(state.value))

    definePageMeta({
        // No auth middleware — users returning from Stripe/PayPal may not be authenticated
    })

    const formatAmount = (amount, currency = 'USD') => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency || 'USD',
        }).format(amount)
    }

    const backUrl = computed(() => {
        return safeInternalPath(route.query.back)
    })

    const backLabel = computed(() =>
        backUrl.value === '/' ? t('common.backToHome') : t('common.backToArticle'),
    )

    const view = computed(() => {
        switch (state.value) {
            case 'completed':
                return {
                    gradient: 'from-green-500 to-emerald-600',
                    subtitleClass: 'text-green-100',
                    icon: ['fas', 'check-circle'],
                    iconClass: 'text-green-500',
                    title: t('payment.successTitle'),
                    subtitle: t('payment.thankYou'),
                }
            case 'pending':
                return {
                    gradient: 'from-amber-500 to-orange-500',
                    subtitleClass: 'text-amber-100',
                    icon: ['fas', 'clock'],
                    iconClass: 'text-amber-500',
                    title: t('payment.statePendingTitle'),
                    subtitle: t('payment.statePendingSubtitle'),
                }
            case 'refunded':
                return {
                    gradient: 'from-slate-500 to-slate-600',
                    subtitleClass: 'text-slate-100',
                    icon: ['fas', 'rotate-left'],
                    iconClass: 'text-slate-500',
                    title: t('payment.stateRefundedTitle'),
                    subtitle: t('payment.stateRefundedSubtitle'),
                }
            case 'failed':
            case 'cancelled':
            case 'expired':
                return {
                    gradient: 'from-red-500 to-rose-600',
                    subtitleClass: 'text-red-100',
                    icon: ['fas', 'times-circle'],
                    iconClass: 'text-red-500',
                    title: t('payment.stateFailedTitle'),
                    subtitle: t('payment.stateFailedSubtitle'),
                }
            default:
                return {
                    gradient: 'from-gray-500 to-gray-600',
                    subtitleClass: 'text-gray-100',
                    icon: ['fas', 'circle-exclamation'],
                    iconClass: 'text-gray-500',
                    title: t('payment.stateUnknownTitle'),
                    subtitle: t('payment.stateUnknownSubtitle'),
                }
        }
    })

    // The tab title must match the outcome too: it used to be the static
    // "Payment Successful" even for a declined checkout.
    useHead({ title: computed(() => view.value.title) })

    // Normalise the API payload; keeps working against an older backend that
    // only sent `completed`.
    const applyStatus = (data) => {
        sessionData.value = data ?? null

        const reported = String(data?.state || '').toLowerCase()
        if (
            ['completed', 'pending', 'failed', 'cancelled', 'expired', 'refunded'].includes(
                reported,
            )
        ) {
            state.value = reported
            return
        }

        if (data?.completed === true) state.value = 'completed'
        else if (data?.completed === false) state.value = 'pending'
        else state.value = 'unknown'
    }

    const fetchStatus = async () => {
        if (!reference.value || !returnedMethod.value) {
            state.value = 'unknown'
            return
        }

        try {
            const response = await api.get(
                `/payment/${encodeURIComponent(returnedMethod.value)}/status/${encodeURIComponent(reference.value)}`,
            )

            if (response.success) applyStatus(response.data)
            else state.value = 'unknown'
        } catch (error) {
            // A 404 means we have no record of this reference — say so instead
            // of pretending the payment succeeded. During polling, other
            // failures keep the current state and retry.
            if (error?.response?.status === 404 || state.value !== 'pending') {
                state.value = 'unknown'
            }
        }
    }

    const schedulePoll = () => {
        if (polls >= MAX_POLLS) return

        polls += 1
        pollTimer = setTimeout(async () => {
            await fetchStatus()
            if (state.value === 'pending') schedulePoll()
        }, POLL_INTERVAL_MS)
    }

    onMounted(async () => {
        loadPaymentMethods()

        if (!reference.value || !returnedMethod.value) {
            loading.value = false
            return
        }

        await fetchStatus()
        loading.value = false

        if (state.value === 'pending') schedulePoll()
    })

    onUnmounted(() => {
        if (pollTimer) clearTimeout(pollTimer)
    })
</script>
