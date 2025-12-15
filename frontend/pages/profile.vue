<template>
    <main class="w-full mx-auto max-w-4xl px-2 sm:px-4 lg:px-6 py-8">
        <!-- Page Title -->
        <h1
            class="text-3xl sm:text-4xl font-bold text-center bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-purple-600 mb-8"
        >
            Profile Details
        </h1>

        <!-- Alert -->
        <AlertMessage
            v-if="showAlert"
            :variant="alertVariant"
            :message="alertMessage"
            class="mb-6"
            @close="showAlert = false"
        />

        <!-- Loading -->
        <div v-if="userStore.isLoadingUser" class="text-center text-gray-600">
            <LoadingSpinner text="Loading user details" />
        </div>

        <form v-else class="space-y-8" @submit.prevent="onSubmit">
            <!-- User Info -->
            <div
                class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden"
            >
                <!-- Card Header -->
                <div
                    class="px-6 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white"
                >
                    <h2 class="text-lg sm:text-xl font-semibold">
                        Your Information
                    </h2>
                </div>
                <!-- Card Body -->
                <div class="p-6 space-y-5">
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >Full Name<span class="text-red-500">*</span></label
                        >
                        <FormInput
                            v-model="name"
                            type="text"
                            placeholder="Enter full name"
                            v-bind="nameProps"
                            :error-message="errors.name"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >Email Address<span class="text-red-500"
                                >*</span
                            ></label
                        >
                        <FormInput
                            v-model="email"
                            type="email"
                            placeholder="Enter your email"
                            v-bind="emailProps"
                            :error-message="errors.email"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >Phone Number</label
                        >
                        <FormInput
                            v-model="phone"
                            type="text"
                            placeholder="Phone number"
                            v-bind="phoneProps"
                            :error-message="errors.phone"
                        />
                    </div>
                </div>
            </div>

            <!-- Billing Info -->
            <div
                class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden"
            >
                <!-- Card Header -->
                <div
                    class="px-6 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white"
                >
                    <h2 class="text-lg sm:text-xl font-semibold">
                        Billing Information
                    </h2>
                </div>
                <!-- Card Body -->
                <div class="p-6 space-y-5">
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >Address <span class="text-red-500">*</span></label
                        >
                        <FormInput
                            v-model="address"
                            type="text"
                            placeholder="Enter address"
                            v-bind="addressProps"
                            :error-message="errors.address"
                        />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label
                                class="block text-sm font-medium text-gray-700 mb-1"
                                >City <span class="text-red-500">*</span></label
                            >
                            <FormInput
                                v-model="city"
                                type="text"
                                placeholder="Enter city"
                                v-bind="cityProps"
                                :error-message="errors.city"
                            />
                        </div>
                        <div>
                            <label
                                class="block text-sm font-medium text-gray-700 mb-1"
                                >State
                                <span class="text-red-500">*</span></label
                            >
                            <FormInput
                                v-model="state"
                                type="text"
                                placeholder="Enter state"
                                v-bind="stateProps"
                                :error-message="errors.state"
                            />
                        </div>
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >ZIP Code <span class="text-red-500">*</span></label
                        >
                        <FormInput
                            v-model="zipCode"
                            type="text"
                            placeholder="Enter ZIP code"
                            v-bind="zipCodeProps"
                            :error-message="errors.zipCode"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >Country <span class="text-red-500">*</span></label
                        >
                        <FormInput
                            v-model="country"
                            type="text"
                            placeholder="Enter country"
                            v-bind="countryProps"
                            :error-message="errors.country"
                        />
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="flex justify-center">
                <FormSubmitButton
                    class="w-full sm:w-1/2 md:w-1/2"
                    :text="userStore.isUpdatingUser ? 'Saving...' : 'Save'"
                    type="submit"
                    variant="primary"
                    :disabled="userStore.isUpdatingUser"
                />
            </div>
        </form>
    </main>
</template>

<script setup>
import { onMounted, watch, ref } from 'vue'
import { useForm } from 'vee-validate'
import * as yup from 'yup'
import FormInput from '~/components/FormInput.vue'
import FormSubmitButton from '~/components/FormSubmitButton.vue'
import AlertMessage from '~/components/AlertMessage.vue'
import { useUserStore } from '@/stores/userStore'

useHead({ title: 'Profile Details' })
definePageMeta({ middleware: 'auth' })

const userStore = useUserStore()

// Alert state
const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')

// Validation schema
const validationSchema = yup.object({
    name: yup.string().required('Full name is required'),
    email: yup.string().required('Email is required').email('Invalid email'),
    phone: yup
        .string()
        .matches(/^\d{12}$/, 'Phone number must be exactly 12 digits'),
    address: yup.string().required('Address is required'),
    city: yup.string().required('City is required'),
    state: yup.string().required('State is required'),
    zipCode: yup.string().required('ZIP Code is required'),
    country: yup.string().required('Country is required'),
})

// Setup vee-validate form
const { handleSubmit, defineField, errors, resetForm } = useForm({
    validationSchema,
    initialValues: {
        name: '',
        email: '',
        phone: '',
        address: '',
        city: '',
        state: '',
        zipCode: '',
        country: '',
    },
})

// Define form fields
const [name, nameProps] = defineField('name')
const [email, emailProps] = defineField('email')
const [phone, phoneProps] = defineField('phone')
const [address, addressProps] = defineField('address')
const [city, cityProps] = defineField('city')
const [state, stateProps] = defineField('state')
const [zipCode, zipCodeProps] = defineField('zipCode')
const [country, countryProps] = defineField('country')

// Fetch user on mounted
onMounted(async () => {
    userStore.getUserDetails()
})

// Watch for changes in user data and reset form
watch(
    () => userStore.user,
    (user) => {
        if (user) {
            resetForm({
                values: {
                    name: user.name ?? '',
                    email: user.email ?? '',
                    phone: user.phone ?? '',
                    address: user.address ?? '',
                    city: user.city ?? '',
                    state: user.state ?? '',
                    zipCode: user.zip_code ?? '',
                    country: user.country ?? '',
                },
            })
        }
    },
    { immediate: true }
)

// Handle submit
const onSubmit = handleSubmit(async (values) => {
    userStore.updateUser({
        name: values.name,
        email: values.email,
        phone: values.phone,
        address: values.address,
        city: values.city,
        state: values.state,
        zip_code: values.zipCode,
        country: values.country,
    })
})
</script>
