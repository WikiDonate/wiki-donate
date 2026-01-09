<template>
    <main class="w-full mx-auto max-w-6xl">
        <div class="container mx-auto p-4 md:p-6">
            <h1
                class="text-2xl lg:text-4xl font-bold text-gray-800 mb-6 text-center"
            >
                Make a Donation
            </h1>

            <!-- Payment Method Alert -->
            <div v-if="!isFetchingCard && !hasPaymentMethod" class="mb-6">
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg
                                class="h-5 w-5 text-yellow-400"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
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
            </div>

            <div class="flex flex-col md:flex-row gap-6">
                <!-- Left Section (8/12) -->
                <div class="w-full md:w-8/12">
                    <div
                        class="bg-white rounded-lg shadow-md border border-gray-200 mb-6"
                    >
                        <div
                            class="p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg"
                        >
                            <h2 class="text-xl font-semibold">Cause</h2>
                        </div>
                        <div class="p-6">
                            <div class="mb-6">
                                <h3 class="text-lg font-semibold mb-2">
                                    {{ currentCause.title }}
                                </h3>
                                <p class="text-gray-700">
                                    {{ currentCause.description }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Donor Information Section -->
                    <div
                        class="bg-white rounded-lg shadow-md border border-gray-200 mb-6"
                    >
                        <div
                            class="p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg"
                        >
                            <h2 class="text-xl font-semibold">
                                Donor Information
                            </h2>
                        </div>
                        <div class="p-6">
                            <form @submit.prevent="submitDonation">
                                <div class="mb-4">
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
                                        class="mb-3"
                                        v-bind="nameProps"
                                        :error-message="errors['name']"
                                    />
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Section (4/12) -->
                <div class="w-full md:w-4/12">
                    <div
                        class="bg-white rounded-lg shadow-md border border-gray-200 mb-6 sticky top-4"
                    >
                        <div
                            class="p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg"
                        >
                            <h2 class="text-xl font-semibold">
                                Supporting NGOs
                            </h2>
                        </div>
                        <div class="p-6">
                            <!-- NGO List -->
                            <div
                                v-for="ngo in supportingNgos"
                                :key="ngo.id"
                                class="flex items-center mb-4"
                            >
                                <div class="w-12 h-12 mr-3 flex-shrink-0">
                                    <img
                                        :src="generateAvatar(ngo.name)"
                                        :alt="`${ngo.name} Logo`"
                                        class="rounded-full w-full h-full object-cover border border-gray-200"
                                    >
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3
                                        class="font-medium text-gray-800 truncate"
                                    >
                                        {{ ngo.name }}
                                    </h3>
                                    <p class="text-xs text-gray-500 truncate">
                                        {{ ngo.location }}
                                    </p>
                                </div>
                            </div>

                            <!-- Loading state when checking payment method -->
                            <div v-if="isFetchingCard" class="text-center py-4">
                                <LoadingSpinner
                                    text="Checking payment method..."
                                />
                            </div>

                            <!-- Donation Amount -->
                            <div v-else class="mb-4 mt-6">
                                <!-- Message -->
                                <AlertMessage
                                    v-if="showAlert"
                                    :variant="alertVariant"
                                    :message="alertMessage"
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
                                    class="mb-3"
                                    v-bind="amountProps"
                                    :error-message="errors['amount']"
                                />

                                <!-- Quick Amount Buttons -->
                                <div class="flex flex-wrap gap-2 mt-2">
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
                                class="w-full mt-4"
                                :text="
                                    isDonating
                                        ? 'Processing Donation...'
                                        : 'Make Donation By Card'
                                "
                                type="submit"
                                variant="primary"
                                :disabled="!hasPaymentMethod || isDonating"
                                @click="onSubmit"
                            />
                            <h2 class="text-center py-2">OR</h2>
                            <PaypalButton
                                :amount="amount || 0"
                                :disabled="
                                    !hasPaymentMethod || !amount || amount <= 0
                                "
                                @payment-success="handlePayPalSuccess"
                                @payment-error="handlePayPalError"
                            />
                        </div>
                    </div>
                </div>
            </div>
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

useHead({
    title: 'Make a Donation',
})

definePageMeta({
    middleware: 'auth',
})

// Sample data for causes and NGOs
const currentCause = {
    id: 1,
    title: 'Education for Underprivileged Children',
    description:
        'Supporting quality education for children in rural areas by providing school supplies, meals, and teacher training. Your donation helps us build schools and provide learning materials to children who lack access to basic education.',
}

const supportingNgos = [
    {
        id: 1,
        name: 'Education for All Foundation',
        location: 'New Delhi, India',
        focus: 'Primary education in rural areas',
    },
    {
        id: 2,
        name: "Children's Hope Initiative",
        location: 'Nairobi, Kenya',
        focus: "Girls' education",
    },
    {
        id: 3,
        name: 'Bright Future Collective',
        location: 'Lagos, Nigeria',
        focus: 'STEM education',
    },
    {
        id: 4,
        name: 'Global Learning Partners',
        location: 'Dhaka, Bangladesh',
        focus: 'Teacher training',
    },
]

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
const [phone, phoneProps] = defineField('phone')
const [amount, amountProps] = defineField('amount')

const checkPaymentMethod = async () => {
    isFetchingCard.value = true
    try {
        const response = await paymentMethodService.getCard()
        hasPaymentMethod.value = response.success && response.data !== null
    } catch (error) {
        console.error('Error checking payment method:', error)
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
        const response = await donateService.donateNow(values)

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
    checkPaymentMethod()
})

const handlePayPalSuccess = (details) => {
    resetForm()
    showAlert.value = true
    alertVariant.value = 'success'
    alertMessage.value = `Thank you for your PayPal donation of $${details.purchase_units[0].amount.value}!`
    //   donateService.recordPayPalDonation({
    //     amount: details.purchase_units[0].amount.value,
    //     paypalId: details.id
    //   });
}
const handlePayPalError = (err) => {
    console.log('Details in error', err)
    showAlert.value = true
    alertVariant.value = 'error'
    alertMessage.value = err || `Something went wrong!`
}
</script>
