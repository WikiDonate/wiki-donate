<template>
    <div>
        <div
            v-if="processingPaypalPayment"
            class="flex items-center justify-center rounded-lg bg-[#F2BA36] p-3 text-white shadow-md"
        >
            <span class="font-semibold">Processing payment...</span>
        </div>
        <div v-else-if="amountValid">
            <div ref="paypalRef" class="paypal-button-container"/>
        </div>
        <div
            v-else
            class="mx-auto rounded-lg border-red-500 bg-red-50 p-4 text-center shadow-md"
        >
            <div class="flex items-center justify-center space-x-2">
                <svg
                    class="h-6 w-6 text-red-500"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M18.364 5.636l-1.414-1.414a9 9 0 00-12.728 0l-1.414 1.414a9 9 0 000 12.728l1.414 1.414a9 9 0 0012.728 0l1.414-1.414a9 9 0 000-12.728z"
                    />
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M12 8v4m0 4h.01"
                    />
                </svg>
                <span class="text-red-700 font-semibold"
                    >Please enter an amount to pay through PayPal</span
                >
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch, onMounted, nextTick } from 'vue'

const props = defineProps({
    amount: {
        type: Number,
        required: true,
    },
    currency: {
        type: String,
        default: 'USD',
    },
    style: {
        type: Object,
        default: () => ({
            layout: 'vertical',
            color: 'gold',
            shape: 'rect',
            label: 'paypal',
            height: 42,
        }),
    },
    processingPaypalPayment: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['paymentSuccess', 'paymentError', 'paymentCancel'])

const paypalRef = ref(null)
const paypalInstance = ref(null) // Hold PayPal instance
const { $paypal } = useNuxtApp()
const amountValid = ref(props.amount > 0)

async function renderPaypalButton() {
    if (typeof window === 'undefined' || !$paypal) return

    if (!amountValid.value) return

    if (paypalRef.value) {
        paypalRef.value.innerHTML = ''
    }

    await nextTick()

    paypalInstance.value = $paypal.Buttons({
        style: props.style,
        createOrder(data, actions) {
            if (props.amount <= 0) {
                return actions.reject()
            }
            return actions.order.create({
                purchase_units: [
                    {
                        amount: {
                            value: props.amount,
                            currency_code: props.currency,
                        },
                    },
                ],
            })
        },
        onApprove(data, actions) {
            return actions.order.capture().then((details) => {
                emit('paymentSuccess', details)
            })
        },
        onError(err) {
            emit('paymentError', err)
        },
    })

    paypalInstance.value.render(paypalRef.value)
}

onMounted(() => {
    renderPaypalButton()
})

watch(
    () => props.amount,
    async (newVal) => {
        amountValid.value = newVal > 0
        await renderPaypalButton()
    }
)
</script>
