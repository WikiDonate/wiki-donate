<template>
    <Modal
        :model-value="modelValue"
        :title="t('donate.title')"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div class="space-y-5">
            <div class="bg-indigo-50 rounded-lg p-4 border border-indigo-100">
                <div class="flex justify-between items-center mb-3">
                    <h4 class="font-semibold text-indigo-700 text-sm">
                        {{ t('donate.formulaBreakdown') }}
                    </h4>
                    <button
                        class="text-indigo-400 hover:text-indigo-600 transition-colors"
                        :title="showFormula ? t('donate.hideFormula') : t('donate.showFormula')"
                        @click="showFormula = !showFormula"
                    >
                        <font-awesome-icon
                            :icon="['fas', showFormula ? 'eye' : 'eye-slash']"
                            class="w-4 h-4"
                        />
                    </button>
                </div>
                <div
                    class="overflow-hidden transition-all duration-300 ease-in-out"
                    :class="showFormula ? 'max-h-96 opacity-100' : 'max-h-0 opacity-0'"
                >
                    <div class="space-y-2">
                        <div
                            v-for="(item, i) in formula"
                            :key="i"
                            class="flex justify-between items-center text-sm"
                        >
                            <span class="text-gray-700">{{ item.organization }}</span>
                            <span class="font-semibold text-indigo-600"
                                >{{ item.percentage }}%</span
                            >
                        </div>
                        <div
                            class="flex justify-between items-center pt-2 border-t border-indigo-200 font-bold text-gray-800"
                        >
                            <span>{{ t('donate.total') }}</span>
                            <span class="text-indigo-700">100%</span>
                        </div>
                    </div>
                    <p
                        v-if="details"
                        class="mt-2 pt-2 border-t border-indigo-200 text-xs text-gray-500 italic"
                    >
                        {{ details }}
                    </p>
                </div>
            </div>

            <!-- Login prompt for non-authenticated users -->
            <div
                v-if="!authStore.isAuthenticated"
                class="bg-amber-50 rounded-lg p-5 border border-amber-200 text-center"
            >
                <font-awesome-icon :icon="['fas', 'lock']" class="w-8 h-8 text-amber-500 mb-3" />
                <p class="text-gray-700 text-sm font-medium mb-3">
                    {{ t('donate.loginPrompt') }}
                </p>
                <NuxtLink
                    to="/login"
                    class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors"
                >
                    <font-awesome-icon :icon="['fas', 'sign-in-alt']" class="w-4 h-4" />
                    {{ t('donate.logIn') }}
                </NuxtLink>
            </div>

            <!-- Donation form for authenticated users -->
            <template v-else>
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        {{ t('donate.donationAmount') }}
                        <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="amount"
                        type="number"
                        min="0.5"
                        max="500000"
                        step="0.01"
                        :placeholder="t('donate.enterAmount')"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                        :disabled="isDonating"
                    />
                    <div class="flex flex-wrap gap-2 mt-3">
                        <button
                            v-for="quickAmount in quickAmounts"
                            :key="quickAmount"
                            type="button"
                            class="px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors"
                            :class="{
                                'bg-indigo-100 text-indigo-700 font-medium':
                                    Number(amount) === quickAmount,
                            }"
                            @click="amount = quickAmount"
                        >
                            ${{ quickAmount }}
                        </button>
                    </div>
                    <p class="text-xs text-gray-600 mt-3 italic">
                        {{ t('donate.operationalCosts') }}
                    </p>
                </div>

                <div
                    v-if="alertMessage"
                    class="flex items-start p-3 rounded-lg border text-sm"
                    :class="alertClass"
                >
                    <span class="flex-1">{{ alertMessage }}</span>
                    <button
                        class="ml-2 text-gray-400 hover:text-gray-600"
                        @click="alertMessage = ''"
                    >
                        <font-awesome-icon :icon="['fas', 'times']" class="w-3 h-3" />
                    </button>
                </div>

                <!-- Payment-method picker: options come from GET /v1/payment-methods,
                     so enabling a new method in the registry surfaces it here with
                     no front-end change. -->
                <div v-if="methods.length > 1">
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        {{ t('donate.paymentMethod') }}
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <button
                            v-for="method in methods"
                            :key="method.code"
                            type="button"
                            class="flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold transition-colors"
                            :class="
                                selectedMethod === method.code
                                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                            "
                            :disabled="isDonating"
                            @click="selectMethod(method.code)"
                        >
                            <font-awesome-icon
                                v-if="methodIcon(method.code)"
                                :icon="methodIcon(method.code)"
                                class="w-4 h-4"
                            />
                            {{ method.label }}
                        </button>
                    </div>
                </div>

                <p v-else-if="!loadingMethods && !methods.length" class="text-sm text-amber-700">
                    {{ t('donate.noPaymentMethods') }}
                </p>

                <!-- PayPal Smart Buttons (disabled until an amount is entered) -->
                <div
                    v-if="selectedMethod === 'paypal'"
                    v-show="hasValidAmount"
                    id="paypal-button-container"
                    class="paypal-buttons min-h-11.25"
                >
                    <div v-if="!paypalSdkLoaded" class="flex justify-center items-center py-4">
                        <LoadingSpinner class="w-6 h-6" />
                    </div>
                </div>

                <!-- Any non-PayPal method is a hosted-checkout redirect. The
                     gateway difference lives on the server, so a newly enabled
                     method works here with no template change. -->
                <button
                    v-else-if="hasValidAmount"
                    type="button"
                    class="w-full h-11.25 flex items-center justify-center gap-2 rounded-lg bg-indigo-600 text-white font-semibold text-sm hover:bg-indigo-700 transition-colors disabled:opacity-60"
                    :disabled="isDonating"
                    @click="startHostedCheckout"
                >
                    <font-awesome-icon
                        :icon="methodIcon(selectedMethod) || ['fas', 'credit-card']"
                        class="w-4 h-4"
                    />
                    {{ t('donate.continueToCheckout') }}
                </button>

                <!-- Placeholder while no valid amount has been entered -->
                <div v-if="!hasValidAmount" class="paypal-buttons min-h-11.25">
                    <button
                        type="button"
                        disabled
                        class="w-full h-11.25 flex items-center justify-center gap-2 rounded-lg bg-gray-200 text-gray-500 font-semibold text-sm cursor-not-allowed"
                    >
                        {{ t('donate.enterAmountToDonate') }}
                    </button>
                </div>
            </template>
        </div>

        <template #footer>
            <Button
                variant="secondary"
                :text="t('common.cancel')"
                width="auto"
                class="px-6"
                @click="$emit('update:modelValue', false)"
            />
        </template>
    </Modal>
</template>

<script setup>
    import { ref, watch, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { donateService } from '~/services/donateService'
    import { usePaymentMethods } from '~/composables/usePaymentMethods'

    const { t } = useI18n()
    const {
        methods,
        loading: loadingMethods,
        loadPaymentMethods,
        methodLabel,
        hasMethod,
    } = usePaymentMethods()
    const authStore = useAuthStore()
    const route = useRoute()

    const props = defineProps({
        modelValue: {
            type: Boolean,
            default: false,
        },
        formula: {
            type: Array,
            default: () => [],
        },
        formulaId: {
            type: [Number, String],
            default: null,
        },
        details: {
            type: String,
            default: '',
        },
    })

    defineEmits(['update:modelValue', 'paymentSuccess', 'paymentError'])

    const quickAmounts = [10, 25, 50, 100, 250, 500]

    const amount = ref('')
    const isDonating = ref(false)
    const alertMessage = ref('')
    const alertVariant = ref('error')
    const showFormula = ref(false)

    // Active income methods from the registry; 'paypal' is the safe default
    // while the list is still loading so the flow never dead-ends.
    const selectedMethod = ref('paypal')
    const paypalSdkLoaded = ref(false)
    const paypalButtonsRendered = ref(false)
    let paypalButtonsInstance = null

    /** Branded icon per known method code; unknown codes render text only. */
    const methodIcons = {
        paypal: ['fab', 'paypal'],
        stripe: ['fas', 'credit-card'],
    }
    const methodIcon = (code) => methodIcons[String(code ?? '').toLowerCase()] || null

    // Fail-open: if the registry has not loaded (or failed to), keep the legacy
    // PayPal rail available rather than dead-ending the donor. Only a loaded
    // registry that explicitly omits PayPal disables it.
    const paypalEnabled = computed(() => !methods.value.length || hasMethod('paypal'))

    // Keep the selection valid: never leave a code selected that the registry
    // no longer offers (e.g. a method was disabled between page loads).
    watch(methods, (list) => {
        const codes = (list || []).map((method) => method.code)
        if (!codes.length) return
        if (!codes.includes(selectedMethod.value)) {
            // Prefer PayPal (the historical default) when it is still active.
            selectedMethod.value = codes.includes('paypal') ? 'paypal' : codes[0]
        }
    })

    const alertClass = computed(() => {
        const map = {
            success: 'bg-green-100 border-green-400 text-green-700',
            error: 'bg-red-100 border-red-400 text-red-700',
        }
        return map[alertVariant.value] || ''
    })

    // Matches backend min (0.50) / max (500000) on both payment rails.
    const hasValidAmount = computed(
        () => Number(amount.value) >= 0.5 && Number(amount.value) <= 500000 && props.modelValue,
    )

    const clientId = import.meta.env.VITE_PAYPAL_CLIENT_ID || ''

    function loadPayPalSdk() {
        return new Promise((resolve, reject) => {
            if (window.paypal) {
                resolve(window.paypal)
                return
            }

            const existing = document.getElementById('paypal-js-sdk')
            if (existing) {
                existing.addEventListener('load', () => resolve(window.paypal))
                existing.addEventListener('error', () =>
                    reject(new Error(t('donate.failedToLoadPaypal'))),
                )
                return
            }

            const script = document.createElement('script')
            script.id = 'paypal-js-sdk'
            script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(
                clientId,
            )}&currency=USD`
            script.async = true
            script.onload = () => resolve(window.paypal)
            script.onerror = () => reject(new Error(t('donate.failedToLoadPaypal')))
            document.head.appendChild(script)
        })
    }

    function destroyPaypalButtons() {
        const container = document.getElementById('paypal-button-container')
        if (paypalButtonsInstance && typeof paypalButtonsInstance.close === 'function') {
            try {
                paypalButtonsInstance.close()
            } catch {
                // ignore teardown errors
            }
        }
        paypalButtonsInstance = null
        if (container) {
            container.innerHTML = ''
        }
        paypalButtonsRendered.value = false
    }

    async function renderPaypalButtons() {
        // Guard: the user may have switched methods while the SDK was loading.
        if (selectedMethod.value !== 'paypal' || !paypalEnabled.value) return

        // Clear any previously rendered buttons before re-rendering.
        const container = document.getElementById('paypal-button-container')
        if (!container) return

        // If a previous button set is still alive (e.g. modal reopened), close it
        // and clear the container so we don't stack duplicate button sets.
        if (paypalButtonsRendered.value && paypalButtonsInstance) {
            destroyPaypalButtons()
        }

        if (paypalButtonsRendered.value) {
            return
        }

        if (!clientId) {
            alertVariant.value = 'error'
            alertMessage.value = t('donate.paypalNotConfigured')
            paypalSdkLoaded.value = true
            return
        }

        try {
            const paypal = await loadPayPalSdk()
            paypalSdkLoaded.value = true

            paypalButtonsInstance = paypal
                .Buttons({
                    style: {
                        layout: 'vertical',
                        color: 'gold',
                        shape: 'rect',
                        label: 'paypal',
                        height: 45,
                    },
                    createOrder: async () => {
                        if (!amount.value || Number(amount.value) <= 0) {
                            throw new Error(t('donate.enterValidAmount'))
                        }

                        const params = {
                            amount: Number(amount.value),
                            donor_name: authStore.user?.username || '',
                            donor_email: authStore.user?.email || '',
                            formula_id: props.formulaId || null,
                            details: props.details,
                        }
                        const response = await donateService.createOrder('paypal', params)

                        if (response.success && response.data?.order_id) {
                            return response.data.order_id
                        }

                        throw new Error(
                            response.errors?.[0] ||
                                response.message ||
                                t('donate.failedToCreateOrder'),
                        )
                    },
                    onApprove: async (data) => {
                        isDonating.value = true
                        alertMessage.value = ''
                        try {
                            const response = await donateService.captureOrder('paypal', {
                                order_id: data.orderID,
                            })

                            if (response.success) {
                                const donationId = response.data?.donation_id || ''
                                const amt = response.data?.amount ?? amount.value ?? ''
                                const cur = response.data?.currency || 'USD'
                                window.location.href = `/payment/success?paypal=1&donation_id=${donationId}&amount=${amt}&currency=${cur}&back=${encodeURIComponent(route.fullPath)}`
                            } else {
                                alertVariant.value = 'error'
                                alertMessage.value =
                                    response.errors?.[0] ||
                                    response.message ||
                                    t('donate.failedToCapture')
                                isDonating.value = false
                            }
                        } catch (error) {
                            alertVariant.value = 'error'
                            alertMessage.value =
                                error?.errors?.[0] || error?.message || t('donate.failedToProcess')
                            isDonating.value = false
                        }
                    },
                    onCancel: () => {
                        window.location.href = `/payment/cancel?back=${encodeURIComponent(route.fullPath)}`
                    },
                    onError: (err) => {
                        alertVariant.value = 'error'
                        alertMessage.value = err?.message || t('donate.paypalError')
                    },
                })
                .render('#paypal-button-container')

            paypalButtonsRendered.value = true
        } catch (error) {
            alertVariant.value = 'error'
            alertMessage.value = error?.message || t('donate.failedToLoadPaypal')
        }
    }

    // Switching methods must tear the PayPal buttons down so we never leave a
    // stale button set behind the newly selected method.
    function selectMethod(code) {
        if (code === selectedMethod.value) return
        selectedMethod.value = code
        alertMessage.value = ''
        destroyPaypalButtons()
        if (code === 'paypal') loadPayPalSdkPreload()
    }

    /**
     * Hosted-checkout handoff for any non-PayPal method. The generic endpoint
     * returns `checkout_url`/`approval_url` plus a normalised `reference`.
     */
    async function startHostedCheckout() {
        if (!amount.value || Number(amount.value) <= 0) {
            alertVariant.value = 'error'
            alertMessage.value = t('donate.enterValidAmount')
            return
        }

        isDonating.value = true
        alertMessage.value = ''

        try {
            const response = await donateService.createOrder(selectedMethod.value, {
                amount: Number(amount.value),
                donor_name: authStore.user?.username || '',
                donor_email: authStore.user?.email || '',
                formula_id: props.formulaId || null,
                details: props.details,
            })

            const target = response.data?.checkout_url || response.data?.approval_url
            if (response.success && target) {
                window.location.href = target
                return
            }

            alertVariant.value = 'error'
            alertMessage.value =
                response.errors?.[0] ||
                response.message ||
                t('donate.methodError', { method: methodLabel(selectedMethod.value) })
            isDonating.value = false
        } catch (error) {
            alertVariant.value = 'error'
            alertMessage.value =
                error?.errors?.[0] ||
                error?.message ||
                t('donate.methodError', { method: methodLabel(selectedMethod.value) })
            isDonating.value = false
        }
    }

    onMounted(() => {
        loadPaymentMethods()
        if (props.modelValue) {
            loadPayPalSdkPreload()
        }
    })

    // Preload the SDK in the background while the user is still choosing an amount,
    // so buttons render quickly once they select PayPal.
    function loadPayPalSdkPreload() {
        if (clientId && !window.paypal && !document.getElementById('paypal-js-sdk')) {
            loadPayPalSdk().catch(() => {
                // Swallow preload errors — the synchronous render path will surface them.
            })
        }
    }

    watch(
        () => props.modelValue,
        (val) => {
            if (val) {
                amount.value = ''
                alertMessage.value = ''
                isDonating.value = false
                showFormula.value = false
                destroyPaypalButtons()
                loadPaymentMethods()
                if (selectedMethod.value === 'paypal') loadPayPalSdkPreload()
            }
        },
    )

    // Render the real PayPal buttons only once a valid amount is entered, and
    // tear them down again if the amount is cleared or becomes invalid.
    watch(amount, (val) => {
        if (!props.modelValue) return

        // Only the PayPal rail uses in-page buttons; Stripe is a redirect.
        // Also require paypal to be an actually-enabled registry method before
        // rendering its SDK buttons.
        if (selectedMethod.value !== 'paypal' || !paypalEnabled.value) return

        if (Number(val) > 0 && !paypalButtonsRendered.value) {
            nextTick(() => renderPaypalButtons())
        } else if (Number(val) <= 0 && paypalButtonsRendered.value) {
            destroyPaypalButtons()
        }
    })

    onBeforeUnmount(() => {
        destroyPaypalButtons()
    })
</script>
