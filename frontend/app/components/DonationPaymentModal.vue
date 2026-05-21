<template>
    <Modal
        :model-value="modelValue"
        title="Donate via Formula"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div class="space-y-5">
            <div class="bg-indigo-50 rounded-lg p-4 border border-indigo-100">
                <h4 class="font-semibold text-indigo-700 text-sm mb-3">
                    Formula Breakdown
                </h4>
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
                    :disabled="isDonating || processingPaypal"
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

            <button
                class="w-full flex items-center justify-center gap-3 px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold hover:from-indigo-500 hover:to-purple-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed shadow-md"
                :disabled="
                    isDonating ||
                    processingPaypal ||
                    !amount ||
                    Number(amount) <= 0
                "
                @click="handleStripe"
            >
                <font-awesome-icon
                    :icon="['fab', 'cc-stripe']"
                    class="w-5 h-5"
                />
                <span>{{ isDonating ? 'Processing...' : 'Stripe' }}</span>
            </button>

            <div class="flex items-center">
                <div class="flex-grow border-t border-gray-300" />
                <span class="mx-3 text-gray-500 text-sm">OR</span>
                <div class="flex-grow border-t border-gray-300" />
            </div>

            <button
                class="w-full flex items-center justify-center gap-3 px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold hover:from-indigo-500 hover:to-purple-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed shadow-md"
                :disabled="
                    isDonating ||
                    processingPaypal ||
                    !amount ||
                    Number(amount) <= 0
                "
                @click="handlePayPal"
            >
                <font-awesome-icon
                    :icon="['fab', 'cc-paypal']"
                    class="w-5 h-5"
                />
                <span>{{ processingPaypal ? 'Processing...' : 'PayPal' }}</span>
            </button>

            <div ref="paypalAnchor" class="hidden" />
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
import { ref, watch, computed } from 'vue'
import { donateService } from '~/services/donateService'

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
    causeId: {
        type: [String, Number],
        default: null,
    },
})

const emit = defineEmits([
    'update:modelValue',
    'paymentSuccess',
    'paymentError',
])

const quickAmounts = [10, 25, 50, 100, 250, 500]

const amount = ref('')
const isDonating = ref(false)
const processingPaypal = ref(false)
const alertMessage = ref('')
const alertVariant = ref('error')
const paypalAnchor = ref(null)

const alertClass = computed(() => {
    const map = {
        success: 'bg-green-100 border-green-400 text-green-700',
        error: 'bg-red-100 border-red-400 text-red-700',
    }
    return map[alertVariant.value] || ''
})

async function handleStripe() {
    if (!amount.value || Number(amount.value) <= 0) return

    isDonating.value = true
    alertMessage.value = ''
    try {
        const params = { amount: Number(amount.value) }
        if (props.causeId) {
            params.cause_id = props.causeId
        }
        const response = await donateService.donateNow(params)
        if (response.success) {
            alertVariant.value = 'success'
            alertMessage.value = `Thank you for your donation of $${amount.value}!`
            emit('paymentSuccess', {
                method: 'stripe',
                amount: Number(amount.value),
            })
        } else {
            alertVariant.value = 'error'
            alertMessage.value =
                response.errors?.[0] ||
                response.message ||
                'Failed to process donation'
        }
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value =
            error?.errors?.[0] || error?.message || 'Failed to process donation'
    } finally {
        isDonating.value = false
    }
}

async function handlePayPal() {
    if (!amount.value || Number(amount.value) <= 0) return

    const { $paypal } = useNuxtApp()
    if (!$paypal) {
        alertVariant.value = 'error'
        alertMessage.value = 'PayPal is not available'
        return
    }

    processingPaypal.value = true
    alertMessage.value = ''

    const container = paypalAnchor.value
    if (!container) return

    container.innerHTML = ''
    container.classList.remove('hidden')

    const buttons = $paypal.Buttons({
        createOrder(data, actions) {
            return actions.order.create({
                purchase_units: [
                    {
                        amount: {
                            value: String(Number(amount.value)),
                            currency_code: 'USD',
                        },
                    },
                ],
            })
        },
        onApprove: async (data, actions) => {
            try {
                const details = await actions.order.capture()
                cleanup()
                await handlePayPalSuccess(details)
            } catch (err) {
                cleanup()
                handlePayPalError(err)
            }
        },
        onCancel: () => {
            cleanup()
            alertVariant.value = 'error'
            alertMessage.value = 'PayPal payment was cancelled'
            processingPaypal.value = false
        },
        onError: (err) => {
            cleanup()
            handlePayPalError(err)
            processingPaypal.value = false
        },
    })

    function cleanup() {
        container.innerHTML = ''
        container.classList.add('hidden')
    }

    buttons.render(container).then(() => {
        const btn = container.querySelector(
            '.paypal-button, .paypal-buttons [data-paypal-button], .paypal-buttons button'
        )
        if (btn) {
            btn.click()
        } else {
            cleanup()
            alertVariant.value = 'error'
            alertMessage.value = 'Could not initiate PayPal'
            processingPaypal.value = false
        }
    })
}

async function handlePayPalSuccess(details) {
    try {
        const paymentData = {
            payment_id: details.purchase_units[0].payments.captures[0].id,
            customer_id: details.payer.payer_id,
            amount: details.purchase_units[0].amount.value,
            currency: details.purchase_units[0].amount.currency_code,
            status: details.status,
            source: 'paypal',
        }
        if (props.causeId) {
            paymentData.cause_id = props.causeId
        }
        const response =
            await donateService.recordPaymentAndDistribute(paymentData)
        if (response.success) {
            alertVariant.value = 'success'
            alertMessage.value = `Thank you for your PayPal donation of $${details.purchase_units[0].amount.value}!`
            emit('paymentSuccess', {
                method: 'paypal',
                amount: Number(details.purchase_units[0].amount.value),
            })
        } else {
            alertVariant.value = 'error'
            alertMessage.value =
                response.errors?.[0] ||
                response.message ||
                'Failed to process donation'
        }
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value =
            error?.errors?.[0] || error?.message || 'Failed to process donation'
    } finally {
        processingPaypal.value = false
    }
}

function handlePayPalError(err) {
    alertVariant.value = 'error'
    alertMessage.value = err?.message || err || 'PayPal payment failed'
}

watch(
    () => props.modelValue,
    (val) => {
        if (val) {
            amount.value = ''
            alertMessage.value = ''
            isDonating.value = false
            processingPaypal.value = false
        }
    }
)
</script>
