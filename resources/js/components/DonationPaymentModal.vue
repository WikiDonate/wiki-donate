<template>
    <Modal
        :model-value="modelValue"
        title="Donate via Formula"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div class="space-y-5">
            <div class="bg-indigo-50 rounded-lg p-4 border border-indigo-100">
                <div class="flex justify-between items-center mb-3">
                    <h4 class="font-semibold text-indigo-700 text-sm">Formula Breakdown</h4>
                    <button
                        class="text-indigo-400 hover:text-indigo-600 transition-colors"
                        :title="showFormula ? 'Hide formula details' : 'Show formula details'"
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
                            <span>Total</span>
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
                    Please log in to make a donation.
                </p>
                <NuxtLink
                    to="/login"
                    class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors"
                >
                    <font-awesome-icon :icon="['fas', 'sign-in-alt']" class="w-4 h-4" />
                    Log In
                </NuxtLink>
            </div>

            <!-- Donation form for authenticated users -->
            <template v-else>
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Donation Amount ($)
                        <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="amount"
                        type="number"
                        min="1"
                        step="0.01"
                        placeholder="Enter amount"
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
                        * A maximum of 0.1% is for operational costs.
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

                <!-- PayPal buttons (disabled until an amount is entered) -->
                <div
                    v-if="hasValidAmount"
                    id="paypal-button-container"
                    class="paypal-buttons min-h-11.25"
                >
                    <div v-if="!paypalSdkLoaded" class="flex justify-center items-center py-4">
                        <LoadingSpinner class="w-6 h-6" />
                    </div>
                </div>
                <div v-else class="paypal-buttons min-h-11.25">
                    <button
                        type="button"
                        disabled
                        class="w-full h-11.25 flex items-center justify-center gap-2 rounded-lg bg-gray-200 text-gray-500 font-semibold text-sm cursor-not-allowed"
                    >
                        <font-awesome-icon :icon="['fab', 'paypal']" class="w-4 h-4" />
                        Enter amount to donate
                    </button>
                </div>
            </template>
        </div>

        <template #footer>
            <Button
                variant="secondary"
                text="Cancel"
                width="auto"
                class="px-6"
                @click="$emit('update:modelValue', false)"
            />
        </template>
    </Modal>
</template>

<script setup>
import { ref, watch, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { donateService } from '~/services/donateService'

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

const paypalSdkLoaded = ref(false)
const paypalButtonsRendered = ref(false)
let paypalButtonsInstance = null

const alertClass = computed(() => {
    const map = {
        success: 'bg-green-100 border-green-400 text-green-700',
        error: 'bg-red-100 border-red-400 text-red-700',
    }
    return map[alertVariant.value] || ''
})

const hasValidAmount = computed(() => Number(amount.value) > 0 && props.modelValue)

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
            existing.addEventListener('error', () => reject(new Error('Failed to load PayPal SDK')))
            return
        }

        const script = document.createElement('script')
        script.id = 'paypal-js-sdk'
        script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(
            clientId
        )}&currency=USD`
        script.async = true
        script.onload = () => resolve(window.paypal)
        script.onerror = () => reject(new Error('Failed to load PayPal SDK'))
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
        alertMessage.value = 'PayPal is not configured.'
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
                        throw new Error('Please enter a valid donation amount.')
                    }

                    const params = {
                        amount: Number(amount.value),
                        donor_name: authStore.user?.username || '',
                        donor_email: authStore.user?.email || '',
                        formula_id: props.formulaId || null,
                        details: props.details,
                    }
                    const response = await donateService.createPaypalOrder(params)

                    if (response.success && response.data?.order_id) {
                        return response.data.order_id
                    }

                    throw new Error(
                        response.errors?.[0] || response.message || 'Failed to create PayPal order'
                    )
                },
                onApprove: async (data) => {
                    isDonating.value = true
                    alertMessage.value = ''
                    try {
                        const response = await donateService.capturePaypalOrder({
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
                                'Failed to capture payment'
                            isDonating.value = false
                        }
                    } catch (error) {
                        alertVariant.value = 'error'
                        alertMessage.value =
                            error?.errors?.[0] || error?.message || 'Failed to process donation'
                        isDonating.value = false
                    }
                },
                onCancel: () => {
                    window.location.href = `/payment/cancel?back=${encodeURIComponent(route.fullPath)}`
                },
                onError: (err) => {
                    alertVariant.value = 'error'
                    alertMessage.value = err?.message || 'Something went wrong with PayPal.'
                },
            })
            .render('#paypal-button-container')

        paypalButtonsRendered.value = true
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value = error?.message || 'Failed to load PayPal. Please try again.'
    }
}

onMounted(() => {
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
            loadPayPalSdkPreload()
        }
    }
)

// Render the real PayPal buttons only once a valid amount is entered, and
// tear them down again if the amount is cleared or becomes invalid.
watch(amount, (val) => {
    if (!props.modelValue) return

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
