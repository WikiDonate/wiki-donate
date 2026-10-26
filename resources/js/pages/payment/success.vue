<template>
    <main class="w-full mx-auto max-w-2xl mt-6 bg-white rounded-2xl shadow-md overflow-hidden">
        <div class="bg-linear-to-r from-green-500 to-emerald-600 py-8 px-6 text-center">
            <div
                class="w-16 h-16 mx-auto mb-4 bg-white rounded-full flex items-center justify-center"
            >
                <font-awesome-icon
                    :icon="['fas', 'check-circle']"
                    class="w-10 h-10 text-green-500"
                />
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-wide">
                {{ t('payment.successTitle') }}
            </h1>
            <p class="text-base text-green-100 mt-2">{{ t('payment.thankYou') }}</p>
        </div>

        <div class="p-6 sm:p-8 text-center space-y-6">
            <div v-if="loading" class="flex justify-center py-4">
                <LoadingSpinner :text="t('payment.verifyingPayment')" />
            </div>

            <template v-else>
                <div
                    v-if="sessionData && sessionData.completed !== false"
                    class="bg-green-50 border border-green-200 rounded-lg p-6"
                >
                    <p class="text-green-700 font-medium mb-1">
                        {{ t('payment.processedMsg') }}
                    </p>
                    <p v-if="sessionData.amount != null" class="text-gray-600 text-sm">
                        {{ t('payment.amountLabel') }}
                        <span class="font-bold"
                            >{{ formatAmount(sessionData.amount, sessionData.currency) }}
                            {{ sessionData.currency?.toUpperCase() }}</span
                        >
                    </p>
                </div>

                <div
                    v-else-if="unverified"
                    class="bg-amber-50 border border-amber-200 rounded-lg p-6"
                >
                    <p class="text-amber-700 font-medium mb-1">
                        {{ t('payment.unverifiedTitle', { method: methodLabel(returnedMethod) }) }}
                    </p>
                    <p class="text-gray-500 text-sm">
                        {{ t('payment.unverifiedMsg') }}
                    </p>
                </div>

                <div v-else class="bg-gray-50 border border-gray-200 rounded-lg p-6">
                    <p class="text-gray-700 font-medium">{{ t('payment.successful') }}</p>
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
    const { loadPaymentMethods, methodLabel } = usePaymentMethods()
    const sessionData = ref(null)
    const loading = ref(false)
    const unverified = ref(false)

    // The gateway is always named explicitly by the checkout that redirected
    // here (`?method=<code>`). Never infer it from other query params: a new
    // method must work without touching this page.
    const returnedMethod = computed(() => String(route.query.method || '').toLowerCase())

    useHead({ title: t('payment.successTitle') })

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

    onMounted(async () => {
        loadPaymentMethods()

        // Every checkout redirects here with `?method=<code>` and a gateway
        // reference (`session_id` for Stripe, `reference` for client-capture
        // methods). The amount is always read from our own records via the
        // read-only status endpoint — never from the query string, which the
        // donor controls. Completion stays owned by the webhook, so
        // `completed === false` means "not yet", not "failed".
        const reference = route.query.session_id || route.query.reference
        const method = returnedMethod.value

        if (!reference || !method) {
            return
        }

        loading.value = true
        try {
            const response = await api.get(
                `/payment/${encodeURIComponent(method)}/status/${encodeURIComponent(reference)}`,
            )
            if (response.success) {
                sessionData.value = response.data
                unverified.value = response.data?.completed === false
            }
        } catch {
            // Silently fail — user still sees the success message
        } finally {
            loading.value = false
        }
    })
</script>
