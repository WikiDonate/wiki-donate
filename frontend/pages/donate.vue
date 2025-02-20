<template>
    <main class="w-full mx-auto">
        <div class="container mx-auto p-4 md:p-6 w-full sm:w-2/3">
            <div class="w-full max-w-md p-6 bg-white rounded-lg shadow-md">
                <h1
                    class="text-2xl lg:text-4xl font-bold text-gray-800 mb-6 text-center"
                >
                    Make A Donation
                </h1>
                <!-- Message -->
                <AlertMessage
                    v-if="showAlert"
                    :variant="alertVariant"
                    :message="alertMessage"
                    @close="showAlert = false"
                />

                <form @submit.prevent="submitPayment">
                    <!-- Card Holder Name -->
                    <div class="mb-4">
                        <label
                            for="name"
                            class="block text-sm font-medium text-gray-700"
                            >Card Holder Name
                        </label>
                        <FormInput
                            v-model="name"
                            type="text"
                            placeholder="Enter your name"
                            class="mb-3"
                            v-bind="nameProps"
                            :error-message="errors['name']"
                        />
                    </div>

                    <!-- Email -->
                    <div class="mb-4">
                        <label
                            for="email"
                            class="block text-sm font-medium text-gray-700"
                            >Email
                        </label>
                        <FormInput
                            v-model="email"
                            type="email"
                            placeholder="Enter your email"
                            class="mb-3"
                            v-bind="emailProps"
                            :error-message="errors['email']"
                        />
                    </div>

                    <!-- Card Number -->
                    <div class="mb-4">
                        <label
                            for="cardNumber"
                            class="block text-sm font-medium text-gray-700"
                            >Card Number
                        </label>
                        <FormInput
                            v-model="cardNumber"
                            type="text"
                            placeholder="1234 5678 9012 3456"
                            class="mb-3"
                            v-bind="cardNumberProps"
                            :error-message="errors['cardNumber']"
                        />
                    </div>

                    <!-- Expiry Date -->
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label
                                for="expiryMonth"
                                class="block text-sm font-medium text-gray-700"
                                >Expiry Month
                            </label>
                            <FormInput
                                v-model="expiryMonth"
                                type="text"
                                placeholder="MM"
                                class="mb-3"
                                v-bind="expiryMonthProps"
                                :error-message="errors['expiryMonth']"
                            />
                        </div>
                        <div>
                            <label
                                for="expiryYear"
                                class="block text-sm font-medium text-gray-700"
                                >Expiry Year
                            </label>
                            <FormInput
                                v-model="expiryYear"
                                type="text"
                                placeholder="YYYY"
                                class="mb-3"
                                v-bind="expiryYearProps"
                                :error-message="errors['expiryYear']"
                            />
                        </div>
                    </div>

                    <!-- CVV -->
                    <div class="mb-4">
                        <label
                            for="cvv"
                            class="block text-sm font-medium text-gray-700"
                            >CVV
                        </label>
                        <FormInput
                            v-model="cvv"
                            type="text"
                            placeholder="123"
                            class="mb-3"
                            v-bind="cvvProps"
                            :error-message="errors['cvv']"
                        />
                    </div>

                    <!-- Amount -->
                    <div class="mb-6">
                        <label
                            for="amount"
                            class="block text-sm font-medium text-gray-700"
                            >Amount
                        </label>
                        <FormInput
                            v-model="amount"
                            type="text"
                            placeholder="50.00"
                            class="mb-3"
                            v-bind="amountProps"
                            :error-message="errors['amount']"
                        />
                    </div>

                    <!-- Submit Button -->
                    <FormSubmitButton
                        text="Donate Now"
                        type="submit"
                        variant="primary"
                        @click="onSubmit"
                    />
                </form>
            </div>
        </div>
    </main>
</template>

<script setup>
import { useForm } from 'vee-validate'
import * as yup from 'yup'
import FormInput from '~/components/FormInput.vue'
import { donateService } from '~/services/donateService'

useHead({
    title: 'Donate',
})

definePageMeta({
    middleware: 'auth',
})

const authStore = useAuthStore()
const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')

const validationSchema = yup.object({
    name: yup.string().required('Name is required'),
    cardNumber: yup
        .string()
        .required('Card Number is required')
        .matches(/^\d{16}$/, 'Card number must be exactly 16 digits'),
    email: yup
        .string()
        .required('Email is required')
        .email('Email must be a valid email'),
    expiryMonth: yup
        .string()
        .required('Expiry month is required')
        .matches(
            /^(0[1-9]|1[0-2])$/,
            'Expiry month must be a valid month (01-12)'
        ),
    expiryYear: yup
        .string()
        .required('Expiry year is required')
        .test(
            'is-future-year',
            'Expiry year must be in the future',
            (value) => parseInt(value) >= new Date().getFullYear()
        ),
    cvv: yup
        .string()
        .required('CVV is required')
        .matches(/^\d{3,4}$/, 'CVV must be 3 or 4 digits long'),
    amount: yup.string().required('Amount is required'),
})

// Setup VeeValidate
const { handleSubmit, defineField, errors, resetForm } = useForm({
    validationSchema,
    initialValues: {
        email: authStore.user.email ?? '',
    },
})

// Define fields using defineField
const [name, nameProps] = defineField('name')
const [email, emailProps] = defineField('email')
const [cardNumber, cardNumberProps] = defineField('cardNumber')
const [expiryMonth, expiryMonthProps] = defineField('expiryMonth')
const [expiryYear, expiryYearProps] = defineField('expiryYear')
const [cvv, cvvProps] = defineField('cvv')
const [amount, amountProps] = defineField('amount')

const onSubmit = handleSubmit(async (values) => {
    showAlert.value = false

    try {
        const response = await donateService.saveDonate(values)
        if (!response.success) {
            setTimeout(() => {
                alertVariant.value = 'error'
                alertMessage.value = response.errors[0]
                showAlert.value = true
            }, 0)
            return
        }

        resetForm()
        setTimeout(() => {
            alertVariant.value = 'success'
            alertMessage.value = response.message
            showAlert.value = true
        }, 0)
    } catch (error) {
        console.error(error)
        setTimeout(() => {
            alertVariant.value = 'error'
            alertMessage.value = error.errors[0]
            showAlert.value = true
        }, 0)
    }
})
</script>
