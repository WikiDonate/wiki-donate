<template>
    <main class="w-full mx-auto max-w-4xl">
        <div class="container mx-auto p-4 md:p-6 w-full sm:w-2/3">
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
                <!-- Your Information Section -->
                <div
                    class="bg-white rounded-lg shadow-md border border-gray-200 mb-6"
                >
                    <div
                        class="p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg"
                    >
                        <h2 class="text-xl font-semibold">Your Information</h2>
                    </div>
                    <div class="p-6">
                        <form>
                            <div class="mb-4">
                                <label
                                    class="block text-gray-700 text-sm font-bold mb-2"
                                    for="name"
                                >
                                    Full Name
                                    <span class="text-red-500">*</span>
                                </label>
                                <FormInput
                                    v-model="name"
                                    type="text"
                                    placeholder="Enter full name"
                                    class="mb-3"
                                    v-bind="nameProps"
                                    :error-message="errors['name']"
                                />
                            </div>
                            <div class="mb-4">
                                <label
                                    class="block text-gray-700 text-sm font-bold mb-2"
                                    for="email"
                                >
                                    Email Address
                                    <span class="text-red-500">*</span>
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
                            <div class="mb-4">
                                <label
                                    class="block text-gray-700 text-sm font-bold mb-2"
                                    for="phone"
                                >
                                    Phone Number
                                </label>
                                <FormInput
                                    v-model="phone"
                                    type="text"
                                    placeholder="Phone number"
                                    class="mb-3"
                                    v-bind="phoneProps"
                                    :error-message="errors['phone']"
                                />
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Billing Information Section -->
                <div
                    class="bg-white rounded-lg shadow-md border border-gray-200 mb-6"
                >
                    <div
                        class="p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg"
                    >
                        <h2 class="text-xl font-semibold">
                            Billing Information
                        </h2>
                    </div>
                    <div class="p-6">
                        <form>
                            <div class="mb-4">
                                <label
                                    class="block text-gray-700 text-sm font-bold mb-2"
                                    for="address"
                                >
                                    Address <span class="text-red-500">*</span>
                                </label>
                                <FormInput
                                    v-model="address"
                                    type="text"
                                    placeholder="Enter address"
                                    class="mb-3"
                                    v-bind="addressProps"
                                    :error-message="errors['address']"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label
                                        class="block text-gray-700 text-sm font-bold mb-2"
                                        for="city"
                                    >
                                        City <span class="text-red-500">*</span>
                                    </label>
                                    <FormInput
                                        v-model="city"
                                        type="text"
                                        placeholder="Enter city"
                                        class="mb-3"
                                        v-bind="cityProps"
                                        :error-message="errors['city']"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="block text-gray-700 text-sm font-bold mb-2"
                                        for="state"
                                    >
                                        State
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <FormInput
                                        v-model="state"
                                        type="text"
                                        placeholder="Enter state"
                                        class="mb-3"
                                        v-bind="stateProps"
                                        :error-message="errors['state']"
                                    />
                                </div>
                            </div>
                            <div class="mb-4">
                                <label
                                    class="block text-gray-700 text-sm font-bold mb-2"
                                    for="zipCode"
                                >
                                    ZIP Code <span class="text-red-500">*</span>
                                </label>
                                <FormInput
                                    v-model="zipCode"
                                    type="text"
                                    placeholder="Enter full name"
                                    class="mb-3"
                                    v-bind="zipCodeProps"
                                    :error-message="errors['zipCode']"
                                />
                            </div>
                            <div class="mb-4">
                                <label
                                    class="block text-gray-700 text-sm font-bold mb-2"
                                    for="country"
                                >
                                    Country <span class="text-red-500">*</span>
                                </label>
                                <FormInput
                                    v-model="country"
                                    type="text"
                                    placeholder="Enter full name"
                                    class="mb-3"
                                    v-bind="countryProps"
                                    :error-message="errors['country']"
                                />
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Payment Information Section -->
                <div
                    class="bg-white rounded-lg shadow-md border border-gray-200 mb-6"
                >
                    <div
                        class="p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg"
                    >
                        <h2 class="text-xl font-semibold">
                            Payment Information
                        </h2>
                    </div>
                    <div class="p-6">
                        <form>
                            <div class="mb-4">
                                <label
                                    class="block text-gray-700 text-sm font-bold mb-2"
                                    for="card-number"
                                >
                                    Card Number
                                    <span class="text-red-500">*</span>
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
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label
                                        for="expiryMonth"
                                        class="block text-gray-700 text-sm font-bold mb-2"
                                        >Expiry Month
                                        <span class="text-red-500">*</span>
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
                                        class="block text-gray-700 text-sm font-bold mb-2"
                                        >Expiry Year
                                        <span class="text-red-500">*</span>
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
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label
                                        class="block text-gray-700 text-sm font-bold mb-2"
                                        for="cvv"
                                    >
                                        CVV <span class="text-red-500">*</span>
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
                                <div>
                                    <label
                                        class="block text-gray-700 text-sm font-bold mb-2"
                                        for="amount"
                                    >
                                        Amount
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <FormInput
                                        v-model="amount"
                                        type="text"
                                        placeholder="123"
                                        class="mb-3"
                                        v-bind="amountProps"
                                        :error-message="errors['amount']"
                                    />
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Submit Button -->
                <div
                    class="flex items-center justify-center width-full mx-auto"
                >
                    <FormSubmitButton
                        class="sm:w-1/2 md:w-1/2"
                        text="Donate Now"
                        type="submit"
                        variant="primary"
                        @click="onSubmit"
                    />
                </div>
            </form>
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
    name: yup.string().required('Full name is required'),
    email: yup
        .string()
        .required('Email is required')
        .email('Email must be a valid email'),
    phone: yup
        .string()
        .matches(/^\d{12}$/, 'Phone number must be exactly 12 digits'),
    address: yup.string().required('Address is required'),
    city: yup.string().required('City is required'),
    state: yup.string().required('state is required'),
    zipCode: yup.string().required('ZIP Code is required'),
    country: yup.string().required('Country is required'),
    cardNumber: yup
        .string()
        .required('Card Number is required')
        .matches(/^\d{16}$/, 'Card number must be exactly 16 digits'),
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
    amount: yup
        .number()
        .required('Amount is required')
        .typeError('Amount must be a valid number')
        .moreThan(0, 'Amount must be greater than 0'),
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
const [phone, phoneProps] = defineField('phone')
const [address, addressProps] = defineField('address')
const [city, cityProps] = defineField('city')
const [state, stateProps] = defineField('state')
const [zipCode, zipCodeProps] = defineField('zipCode')
const [country, countryProps] = defineField('country')
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
