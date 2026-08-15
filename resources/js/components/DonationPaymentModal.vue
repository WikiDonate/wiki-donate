<template>
    <Modal
        :model-value="modelValue"
        title="Donate via Formula"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div class="space-y-5">
            <div class="bg-indigo-50 rounded-lg p-4 border border-indigo-100">
                <div class="flex justify-between items-center mb-3">
                    <h4 class="font-semibold text-indigo-700 text-sm">
                        Formula Breakdown
                    </h4>
                    <button
                        class="text-indigo-400 hover:text-indigo-600 transition-colors"
                        :title="
                            showFormula
                                ? 'Hide formula details'
                                : 'Show formula details'
                        "
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
                    :class="
                        showFormula
                            ? 'max-h-96 opacity-100'
                            : 'max-h-0 opacity-0'
                    "
                >
                    <div class="space-y-2">
                        <div
                            v-for="(item, i) in formula"
                            :key="i"
                            class="flex justify-between items-center text-sm"
                        >
                            <span class="text-gray-700">{{
                                item.organization
                            }}</span>
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
                <font-awesome-icon
                    :icon="['fas', 'lock']"
                    class="w-8 h-8 text-amber-500 mb-3"
                />
                <p class="text-gray-700 text-sm font-medium mb-3">
                    Please log in to make a donation.
                </p>
                <NuxtLink
                    to="/login"
                    class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors"
                >
                    <font-awesome-icon
                        :icon="['fas', 'sign-in-alt']"
                        class="w-4 h-4"
                    />
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
                </div>

                <!-- Payment method selector -->
                <div>
                    <span class="block text-gray-700 text-sm font-bold mb-2">
                        Payment Method
                    </span>
                    <div
                        class="grid grid-cols-2 gap-1 p-1 bg-gray-100 rounded-lg"
                    >
                        <button
                            type="button"
                            class="flex items-center justify-center gap-2 px-3 py-2 rounded-md text-sm font-semibold transition-colors"
                            :class="
                                paymentMethod === 'card'
                                    ? 'bg-white text-indigo-700 shadow-sm'
                                    : 'text-gray-600 hover:text-gray-800'
                            "
                            :disabled="isDonating"
                            @click="paymentMethod = 'card'"
                        >
                            <font-awesome-icon
                                :icon="['fas', 'credit-card']"
                                class="w-4 h-4"
                            />
                            Card
                        </button>
                        <button
                            type="button"
                            class="flex items-center justify-center gap-2 px-3 py-2 rounded-md text-sm font-semibold transition-colors"
                            :class="
                                paymentMethod === 'paypal'
                                    ? 'bg-white text-indigo-700 shadow-sm'
                                    : 'text-gray-600 hover:text-gray-800'
                            "
                            :disabled="isDonating"
                            @click="paymentMethod = 'paypal'"
                        >
                            <font-awesome-icon
                                :icon="['fab', 'paypal']"
                                class="w-4 h-4"
                            />
                            PayPal
                        </button>
                    </div>
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
                        <font-awesome-icon
                            :icon="['fas', 'times']"
                            class="w-3 h-3"
                        />
                    </button>
                </div>

                <div
                    v-if="paymentMethod === 'card'"
                    class="flex justify-center"
                >
                    <button
                        class="flex items-center justify-center gap-2.5 px-6 py-2.5 rounded-xl text-white font-semibold text-sm tracking-wide uppercase bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed shadow-sm"
                        :disabled="isDonating || !amount || Number(amount) <= 0"
                        @click="handleStripe"
                    >
                        <svg
                            v-if="!isDonating"
                            class="w-5 h-5 flex-shrink-0"
                            viewBox="0 0 24 24"
                            fill="currentColor"
                        >
                            <path
                                d="M13.976 10.816c-.038-1.242 1.035-1.932 1.827-2.344.612-.318 1.418-.546 2.182-.558.813-.013 1.599.192 2.23.583.453.282.845.652 1.14 1.088l-1.915 1.024c-.25-.363-.61-.637-1.015-.784-.495-.18-1.033-.22-1.542-.108-.558.123-1.068.431-1.36.885-.362.563-.41 1.28-.13 1.885.217.47.605.84 1.085 1.032.485.195 1.022.203 1.51.032.396-.138.74-.412.98-.776l1.916 1.024c-.43.695-1.017 1.26-1.716 1.644-.836.458-1.812.618-2.774.456-.99-.167-1.887-.698-2.466-1.519-.609-.86-.727-2.01-.322-2.968.353-.836 1.016-1.508 1.843-1.872.873-.383 1.878-.387 2.754-.02.415.175.784.448 1.07.804l-1.914 1.024c-.016-.004-.034-.006-.05-.01-.424-.105-.895-.096-1.31.033z"
                                fill="currentColor"
                                opacity=".9"
                            />
                            <path
                                d="M10.617 16.661L8.15 7.63l9.376-2.186.61 2.725c-1.18.266-2.607.586-4.278.962-1.67.376-2.496.562-3.733.843l2.79 6.687h-2.298z"
                                fill="currentColor"
                                opacity=".4"
                            />
                            <path
                                d="M6.597 9.944L4.2 6.128l9.372-2.186.597 2.684a317.771 317.771 0 00-2.9.652c-.073.057-.12.098-.134.115l.462 1.552z"
                                fill="currentColor"
                                opacity=".2"
                            />
                        </svg>
                        <LoadingSpinner v-if="isDonating" class="w-4 h-4" />
                        <span>{{
                            isDonating ? 'Processing...' : 'Pay with Card'
                        }}</span>
                    </button>
                </div>

                <div
                    v-else
                    id="paypal-button-container"
                    class="paypal-buttons min-h-[45px]"
                >
                    <div
                        v-if="!paypalSdkLoaded"
                        class="flex justify-center items-center py-4"
                    >
                        <LoadingSpinner class="w-6 h-6" />
                    </div>
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
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue'
import { donateService } from '~/services/donateService'

const authStore = useAuthStore()

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    formula: {
        type: Array,
        default: () => [],
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
const paymentMethod = ref('card')

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
                reject(new Error('Failed to load PayPal SDK'))
            )
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

async function renderPaypalButtons() {
    // Clear any previously rendered buttons before re-rendering.
    const container = document.getElementById('paypal-button-container')
    if (!container) return

    // If a previous button set is still alive (e.g. modal reopened), close it
    // and clear the container so we don't stack duplicate button sets.
    if (paypalButtonsRendered.value && paypalButtonsInstance) {
        if (typeof paypalButtonsInstance.close === 'function') {
            try {
                paypalButtonsInstance.close()
            } catch {
                // ignore teardown errors
            }
        }
        paypalButtonsInstance = null
        container.innerHTML = ''
        paypalButtonsRendered.value = false
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
                        formula: props.formula,
                        details: props.details,
                    }
                    const response =
                        await donateService.createPaypalOrder(params)

                    if (response.success && response.data?.order_id) {
                        return response.data.order_id
                    }

                    throw new Error(
                        response.errors?.[0] ||
                            response.message ||
                            'Failed to create PayPal order'
                    )
                },
                onApprove: async (data) => {
                    isDonating.value = true
                    alertMessage.value = ''
                    try {
                        const response = await donateService.capturePaypalOrder(
                            {
                                order_id: data.orderID,
                            }
                        )

                        if (response.success) {
                            const donationId = response.data?.donation_id || ''
                            const amt =
                                response.data?.amount ?? amount.value ?? ''
                            const cur = response.data?.currency || 'USD'
                            window.location.href = `/payment/success?paypal=1&donation_id=${donationId}&amount=${amt}&currency=${cur}`
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
                            error?.errors?.[0] ||
                            error?.message ||
                            'Failed to process donation'
                        isDonating.value = false
                    }
                },
                onCancel: () => {
                    window.location.href = '/payment/cancel'
                },
                onError: (err) => {
                    alertVariant.value = 'error'
                    alertMessage.value =
                        err?.message || 'Something went wrong with PayPal.'
                },
            })
            .render('#paypal-button-container')

        paypalButtonsRendered.value = true
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value =
            error?.message || 'Failed to load PayPal. Please try again.'
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
    if (
        clientId &&
        !window.paypal &&
        !document.getElementById('paypal-js-sdk')
    ) {
        loadPayPalSdk().catch(() => {
            // Swallow preload errors — the synchronous render path will surface them.
        })
    }
}

async function handleStripe() {
    if (!amount.value || Number(amount.value) <= 0) return

    isDonating.value = true
    alertMessage.value = ''
    try {
        const params = {
            amount: Number(amount.value),
            donor_name: authStore.user?.username || '',
            donor_email: authStore.user?.email || '',
            formula: props.formula,
            details: props.details,
        }
        const response = await donateService.createCheckoutSession(params)
        if (response.success && response.data?.checkout_url) {
            // Redirect user to Stripe Checkout
            window.location.href = response.data.checkout_url
        } else {
            alertVariant.value = 'error'
            alertMessage.value =
                response.errors?.[0] ||
                response.message ||
                'Failed to create checkout session'
            isDonating.value = false
        }
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value =
            error?.errors?.[0] || error?.message || 'Failed to process donation'
        isDonating.value = false
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
            paymentMethod.value = 'card'
            paypalButtonsRendered.value = false
            loadPayPalSdkPreload()
        }
    }
)

watch(paymentMethod, (method) => {
    if (method === 'paypal' && props.modelValue) {
        // Give the DOM a tick to mount the container before rendering.
        setTimeout(() => renderPaypalButtons(), 0)
    }
})

onBeforeUnmount(() => {
    if (paypalButtonsInstance && paypalButtonsInstance.close) {
        try {
            paypalButtonsInstance.close()
        } catch {
            // ignore teardown errors
        }
    }
    paypalButtonsInstance = null
})
</script>
