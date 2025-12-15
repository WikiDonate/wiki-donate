<template>
    <main
        class="w-full mx-auto max-w-7xl mt-6 bg-white rounded-2xl shadow-md overflow-hidden"
    >
        <!-- Gradient Header -->
        <div
            class="bg-gradient-to-r from-indigo-600 to-purple-600 py-6 px-6 text-center"
        >
            <h1
                class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white tracking-wide"
            >
                Make a Donation
            </h1>
            <p class="text-base sm:text-lg text-indigo-100 mt-2">
                Support causes and make a difference today.
            </p>
        </div>

        <!-- Main Content -->
        <div class="container mx-auto p-4 sm:p-6">
            <div v-if="isLoadingCause" class="flex justify-center py-12">
                <LoadingSpinner text="Loading cause details..." />
            </div>

            <template v-else>
                <!-- Payment Method Alert -->
                <div v-if="!isFetchingCard && !hasPaymentMethod" class="mb-6">
                    <div
                        class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-lg shadow-sm"
                    >
                        <div class="flex items-start">
                            <svg
                                class="h-6 w-6 text-yellow-400 flex-shrink-0"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                            <p class="ml-3 text-sm text-yellow-700">
                                You don't have any payment method attached yet.
                                <NuxtLink
                                    to="/billing"
                                    class="font-medium underline text-yellow-700 hover:text-yellow-600"
                                >
                                    Please add a payment method to donate by
                                    card.
                                </NuxtLink>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col md:flex-row gap-6">
                    <!-- Left Section -->
                    <div class="w-full md:w-7/12 lg:w-8/12 space-y-6">
                        <!-- Cause Card -->
                        <div
                            class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden"
                        >
                            <div
                                class="bg-indigo-50 px-4 py-3 border-b border-gray-200"
                            >
                                <h2
                                    class="text-xl font-semibold text-indigo-700"
                                >
                                    Cause
                                </h2>
                            </div>
                            <div class="p-4 sm:p-5">
                                <h3
                                    class="text-lg sm:text-xl font-bold text-gray-800 mb-3"
                                >
                                    {{ cause?.title }}
                                </h3>
                                <p class="text-gray-600 leading-relaxed">
                                    {{ cause?.description }}
                                </p>
                            </div>
                        </div>

                        <!-- Donor Information Section -->
                        <div
                            class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden"
                        >
                            <div
                                class="bg-indigo-50 px-4 py-3 border-b border-gray-200"
                            >
                                <h2
                                    class="text-xl font-semibold text-indigo-700"
                                >
                                    Donor Information
                                </h2>
                            </div>
                            <div class="p-4 sm:p-5">
                                <form
                                    class="space-y-4"
                                    @submit.prevent="submitDonation"
                                >
                                    <div>
                                        <label
                                            class="block text-gray-700 text-sm font-bold mb-2"
                                            for="name"
                                        >
                                            User Name
                                            <span class="text-red-500">*</span>
                                        </label>
                                        <FormInput
                                            v-model="name"
                                            :disabled="true"
                                            type="text"
                                            placeholder="Enter full name"
                                            v-bind="nameProps"
                                            :error-message="errors['name']"
                                        />
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Right Section -->
                    <div class="w-full md:w-5/12 lg:w-4/12">
                        <div
                            class="bg-white rounded-xl shadow-md border border-gray-200 md:sticky md:top-6 overflow-hidden"
                        >
                            <div
                                class="bg-indigo-50 px-4 py-3 border-b border-gray-200"
                            >
                                <h2
                                    class="text-xl font-semibold text-indigo-700"
                                >
                                    Supporting NGOs
                                </h2>
                            </div>
                            <div class="p-4 sm:p-5">
                                <!-- NGO List -->
                                <div class="space-y-4 mb-6">
                                    <div
                                        v-for="ngo in cause?.ngos"
                                        :key="ngo.id"
                                        class="flex items-start"
                                    >
                                        <div class="flex-shrink-0 mr-3">
                                            <img
                                                :src="
                                                    generateAvatar(ngo?.title)
                                                "
                                                :alt="`${ngo?.title} Logo`"
                                                class="rounded-full w-10 h-10 sm:w-12 sm:h-12 object-cover border border-gray-200"
                                            />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h3
                                                class="font-medium text-gray-800 break-words"
                                                :class="{
                                                    truncate:
                                                        windowWidth >= 640,
                                                }"
                                            >
                                                {{ ngo?.title }}
                                            </h3>
                                            <p
                                                class="text-xs text-gray-500 break-words"
                                                :class="{
                                                    truncate:
                                                        windowWidth >= 640,
                                                }"
                                            >
                                                {{ ngo?.address }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Loading state when checking payment method -->
                                <div
                                    v-if="isFetchingCard"
                                    class="text-center py-6"
                                >
                                    <LoadingSpinner
                                        text="Checking payment method..."
                                    />
                                </div>

                                <!-- Donation Amount -->
                                <div v-else>
                                    <!-- Message -->
                                    <AlertMessage
                                        v-if="showAlert"
                                        :variant="alertVariant"
                                        :message="alertMessage"
                                        class="mb-4"
                                        @close="showAlert = false"
                                    />
                                    <label
                                        class="block text-gray-700 text-sm font-bold mb-2"
                                    >
                                        Donation Amount ($)
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <FormInput
                                        v-model="amount"
                                        type="number"
                                        placeholder="Enter amount "
                                        v-bind="amountProps"
                                        :error-message="errors['amount']"
                                        :disabled="
                                            isDonating ||
                                            processingPaypalPayment
                                        "
                                    />

                                    <!-- Quick Amount Buttons -->
                                    <div class="flex flex-wrap gap-2 mt-3">
                                        <button
                                            v-for="quickAmount in quickAmounts"
                                            :key="quickAmount"
                                            type="button"
                                            class="px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors"
                                            @click="amount = quickAmount"
                                        >
                                            ${{ quickAmount }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Donate Button -->
                                <FormSubmitButton
                                    class="w-full mt-6"
                                    :text="
                                        isDonating
                                            ? 'Processing...'
                                            : 'Donate By Card'
                                    "
                                    type="submit"
                                    variant="primary"
                                    @click="onSubmit"
                                    :disabled="
                                        !hasPaymentMethod ||
                                        isDonating ||
                                        processingPaypalPayment
                                    "
                                />

                                <div class="flex items-center my-4">
                                    <div
                                        class="flex-grow border-t border-gray-300"
                                    ></div>
                                    <span class="mx-3 text-gray-500 text-sm"
                                        >OR</span
                                    >
                                    <div
                                        class="flex-grow border-t border-gray-300"
                                    ></div>
                                </div>

                                <PaypalButton
                                    :amount="amount || 0"
                                    :disabled="
                                        !hasPaymentMethod ||
                                        !amount ||
                                        amount <= 0
                                    "
                                    @paymentSuccess="handlePayPalSuccess"
                                    @paymentError="handlePayPalError"
                                    :processingPaypalPayment="
                                        processingPaypalPayment
                                    "
                                    class="w-full"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </main>
</template>

<script setup>
import { useForm } from 'vee-validate'
import * as yup from 'yup'
import FormInput from '~/components/FormInput.vue'
import PaypalButton from '~/components/PaypalButton.vue'

import { donateService } from '~/services/donateService'
import { paymentMethodService } from '~/services/paymentMethodService'
import { useCauseStore } from '~/stores/causeStore'

useHead({
    title: 'Make a Donation',
})

definePageMeta({
    middleware: 'auth',
})

const quickAmounts = [10, 25, 50, 100, 250, 500]

// Generate consistent avatars based on NGO name using DiceBear avatars
const generateAvatar = (seed) => {
    return `https://api.dicebear.com/7.x/initials/svg?seed=${encodeURIComponent(seed)}&radius=50&backgroundType=gradientLinear`
}

const authStore = useAuthStore()
const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const isFetchingCard = ref(true)
const hasPaymentMethod = ref(false)
const isDonating = ref(false)
const route = useRoute()

const causeStore = useCauseStore()
const { cause, isLoadingCause } = storeToRefs(causeStore)
const processingPaypalPayment = ref(false)

const validationSchema = yup.object({
    amount: yup
        .number()
        .required('Donation amount is required')
        .min(1, 'Donation amount must be at least $1')
        .typeError('Please enter a valid amount'),
})

// Setup VeeValidate
const { handleSubmit, defineField, errors, resetForm } = useForm({
    validationSchema,
    initialValues: {
        name: authStore.user.username ?? '',
    },
})

// Define fields using defineField
const [name, nameProps] = defineField('name')
const [amount, amountProps] = defineField('amount')

const checkPaymentMethod = async () => {
    isFetchingCard.value = true
    try {
        const response = await paymentMethodService.getCard()
        hasPaymentMethod.value = response.success && response.data !== null
    } catch (error) {
        hasPaymentMethod.value = false
    } finally {
        isFetchingCard.value = false
    }
}

const onSubmit = handleSubmit(async (values) => {
    if (!hasPaymentMethod.value) return

    isDonating.value = true
    showAlert.value = false

    try {
        const response = await donateService.donateNow({
            amount: values?.amount,
            cause_id: route.params.id,
        })

        if (response.success) {
            showAlert.value = true
            alertVariant.value = 'success'
            alertMessage.value = `Thank you for your donation of $${values.amount}! Your contribution is greatly appreciated.`
            resetForm()
        } else {
            if (response.errors && response.errors.length > 0) {
                showAlert.value = true
                alertVariant.value = 'error'
                alertMessage.value =
                    response.errors.join(', ') || 'Failed to process donation'
            } else {
                showAlert.value = true
                alertVariant.value = 'error'
                alertMessage.value =
                    response.message || 'Failed to process donation'
            }
        }
    } catch (error) {
        // Handle network/other errors
        if (error?.errors && error?.errors.length > 0) {
            showAlert.value = true
            alertVariant.value = 'error'
            alertMessage.value =
                error.errors.join(', ') || 'Failed to process donation'
        } else {
            showAlert.value = true
            alertVariant.value = 'error'
            alertMessage.value =
                error?.message ||
                'Failed to process donation. Please try again.'
        }
    } finally {
        isDonating.value = false
    }
})

onMounted(() => {
    causeStore.getCause(route.params.id)
    checkPaymentMethod()
})

const handlePayPalSuccess = async (details) => {
    processingPaypalPayment.value = true

    const paymentData = {
        payment_id: details.purchase_units[0].payments.captures[0].id,
        customer_id: details.payer.payer_id,
        amount: details.purchase_units[0].amount.value,
        currency: details.purchase_units[0].amount.currency_code,
        status: details.status,
        cause_id: route.params.id,
        source: 'paypal',
    }

    try {
        const response =
            await donateService.recordPaymentAndDistribute(paymentData)

        if (response.success) {
            showAlert.value = true
            alertVariant.value = 'success'
            alertMessage.value = `Thank you for your PayPal donation of $${details.purchase_units[0].amount.value}!`
            resetForm()
        } else {
            if (response.errors && response.errors.length > 0) {
                showAlert.value = true
                alertVariant.value = 'error'
                alertMessage.value =
                    response.errors.join(', ') || 'Failed to process donation'
            } else {
                showAlert.value = true
                alertVariant.value = 'error'
                alertMessage.value =
                    response.message || 'Failed to process donation'
            }
        }
    } catch (error) {
        if (error?.errors && error?.errors.length > 0) {
            showAlert.value = true
            alertVariant.value = 'error'
            alertMessage.value =
                error.errors.join(', ') || 'Failed to process donation'
        } else {
            showAlert.value = true
            alertVariant.value = 'error'
            alertMessage.value =
                error?.message ||
                'Failed to process donation. Please try again.'
        }
    } finally {
        processingPaypalPayment.value = false
    }
}
const handlePayPalError = (err) => {
    console.log('Details in error', err)
    showAlert.value = true
    alertVariant.value = 'error'
    alertMessage.value = err || `Something went wrong!`
}
</script>
