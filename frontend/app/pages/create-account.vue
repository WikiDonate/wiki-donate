<template>
    <main class="w-full bg-white py-8">
        <div class="container mx-auto px-2 sm:px-4 max-w-lg">
            <!-- Card Container -->
            <div
                class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-200"
            >
                <!-- Header -->
                <div
                    class="p-6 text-center bg-gradient-to-r from-indigo-600 to-purple-600 text-white"
                >
                    <h1 class="text-2xl md:text-3xl font-bold">
                        Create an Account
                    </h1>
                    <p class="mt-2 text-sm">
                        Join our community and start your journey
                    </p>
                </div>

                <div class="p-3 sm:p-4 md:p-6">
                    <!-- Success Message after registration -->
                    <div v-if="registrationSuccess" class="space-y-4">
                        <div class="flex justify-center">
                            <div
                                class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center"
                            >
                                <svg
                                    class="w-8 h-8 text-green-500"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                                    ></path>
                                </svg>
                            </div>
                        </div>
                        <h2
                            class="text-xl font-semibold text-gray-800 text-center"
                        >
                            Check Your Email
                        </h2>
                        <p class="text-gray-600 text-sm text-center">
                            We've sent a verification link to
                            <strong>{{ registeredEmail }}</strong
                            >. Please check your inbox and click the link to
                            verify your account.
                        </p>
                        <p class="text-gray-500 text-xs text-center">
                            Didn't receive the email?
                            <button
                                type="button"
                                class="font-medium text-indigo-600 hover:text-indigo-500 hover:underline"
                                :disabled="resendCooldown > 0"
                                @click="resendVerification"
                            >
                                {{
                                    resendCooldown > 0
                                        ? `Resend in ${resendCooldown}s`
                                        : 'Resend verification email'
                                }}
                            </button>
                        </p>
                        <div class="pt-4 text-center">
                            <NuxtLink
                                to="/login"
                                class="inline-block bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-medium px-6 py-3 rounded-lg hover:from-indigo-700 hover:to-purple-700 transition-all duration-200 shadow-md hover:shadow-lg"
                            >
                                Go to Login
                            </NuxtLink>
                        </div>
                    </div>

                    <!-- Message -->
                    <AlertMessage
                        v-else-if="showAlert"
                        :variant="alertVariant"
                        :message="alertMessage"
                        class="mb-6"
                        @close="showAlert = false"
                    />

                    <!-- Form -->
                    <form
                        v-if="!registrationSuccess"
                        class="space-y-4"
                        @submit.prevent="onSubmit"
                    >
                        <label
                            for="username"
                            class="block text-sm font-medium text-gray-700 mb-1"
                        >
                            Username <span class="text-red-500">*</span>
                        </label>
                        <FormInput
                            v-model="username"
                            type="text"
                            placeholder="Enter your username"
                            v-bind="usernameProps"
                            :error-message="errors['username']"
                        />
                        <label
                            for="password"
                            class="block text-sm font-medium text-gray-700 mb-1"
                        >
                            Password <span class="text-red-500">*</span>
                        </label>
                        <FormInput
                            v-model="password"
                            type="password"
                            placeholder="Enter your password"
                            v-bind="passwordProps"
                            :error-message="errors['password']"
                        />
                        <label
                            for="confirmPassword"
                            class="block text-sm font-medium text-gray-700 mb-1"
                        >
                            Confirm Password
                            <span class="text-red-500">*</span>
                        </label>
                        <FormInput
                            v-model="confirmPassword"
                            type="password"
                            placeholder="Confirm password"
                            v-bind="confirmPasswordProps"
                            :error-message="errors['confirmPassword']"
                        />
                        <label
                            for="email"
                            class="block text-sm font-medium text-gray-700 mb-1"
                        >
                            Email <span class="text-red-500">*</span>
                        </label>
                        <FormInput
                            v-model="email"
                            type="email"
                            placeholder="Enter your email"
                            v-bind="emailProps"
                            :error-message="errors['email']"
                        />

                        <!-- <div>
                        <RecaptchaV2
                            @error-callback="handleErrorCallback"
                            @expired-callback="handleExpiredCallback"
                            @load-callback="handleLoadCallback"
                        />
                    </div> -->

                        <!-- Submit Button -->
                        <div class="flex justify-center mt-4">
                            <FormSubmitButton
                                :text="
                                    isLoading ? 'Creating...' : 'Create Account'
                                "
                                type="submit"
                                variant="primary"
                                :disabled="isLoading"
                            />
                        </div>

                        <!-- Login Link -->
                        <div class="text-center text-sm text-gray-600 mt-6">
                            Already have an account?
                            <NuxtLink
                                to="/login"
                                class="font-medium text-indigo-600 hover:text-indigo-500 hover:underline ml-1"
                            >
                                Sign in
                            </NuxtLink>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</template>

<script setup>
import { useForm } from 'vee-validate'
import * as yup from 'yup'
import { userService } from '~/services/userService'

useHead({
    title: 'Create Account',
})

const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const registrationSuccess = ref(false)
const registeredEmail = ref('')
const resendCooldown = ref(0)
let resendTimer = null

const validationSchema = yup.object({
    username: yup
        .string()
        .required('Username is required')
        .min(3, 'Username must be at least 3 characters'),
    password: yup
        .string()
        .required('Password is required')
        .min(6, 'Password must be at least 6 characters'),
    confirmPassword: yup
        .string()
        .required('Confirm Password is required')
        .oneOf([yup.ref('password'), null], 'Passwords must match'),
    email: yup
        .string()
        .required('Email is required')
        .email('Email must be a valid email'),
})

// Setup VeeValidate
const { handleSubmit, defineField, errors, resetForm } = useForm({
    validationSchema,
})

// Define fields using defineField
const [username, usernameProps] = defineField('username')
const [password, passwordProps] = defineField('password')
const [confirmPassword, confirmPasswordProps] = defineField('confirmPassword')
const [email, emailProps] = defineField('email')

const isLoading = ref(false)

const startResendCooldown = () => {
    resendCooldown.value = 60
    resendTimer = setInterval(() => {
        resendCooldown.value--
        if (resendCooldown.value <= 0) {
            clearInterval(resendTimer)
        }
    }, 1000)
}

const resendVerification = async () => {
    if (resendCooldown.value > 0) return

    try {
        const response = await userService.resendVerificationEmail({
            email: registeredEmail.value,
        })
        if (response.success) {
            alertVariant.value = 'success'
            alertMessage.value =
                'Verification email sent. Please check your inbox.'
            showAlert.value = true
            startResendCooldown()
        } else {
            alertVariant.value = 'error'
            alertMessage.value =
                response.errors?.[0] ||
                response.message ||
                'Failed to resend email.'
            showAlert.value = true
        }
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value =
            error.errors?.[0] || error.message || 'Failed to resend email.'
        showAlert.value = true
    }
}

const onSubmit = handleSubmit(async (values) => {
    // Reset alert visibility
    showAlert.value = false
    isLoading.value = true

    try {
        const response = await userService.register({
            ...values,
        })
        if (!response.success) {
            setTimeout(() => {
                alertVariant.value = 'error'
                alertMessage.value = response.errors[0]
                showAlert.value = true
            }, 0)
            isLoading.value = false
            return
        }

        registeredEmail.value = values.email
        resetForm()
        registrationSuccess.value = true
        startResendCooldown()
    } catch (error) {
        setTimeout(() => {
            console.log(error)
            alertVariant.value = 'error'
            alertMessage.value = error.errors?.[0] || 'Registration failed'
            showAlert.value = true
        }, 0)
    } finally {
        isLoading.value = false
    }
})

onUnmounted(() => {
    if (resendTimer) clearInterval(resendTimer)
})
</script>
