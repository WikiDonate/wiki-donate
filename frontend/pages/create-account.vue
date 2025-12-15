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
                    <!-- Message -->
                    <AlertMessage
                        v-if="showAlert"
                        :variant="alertVariant"
                        :message="alertMessage"
                        class="mb-6"
                        @close="showAlert = false"
                    />

                    <!-- Form -->
                    <form class="space-y-4" @submit.prevent="onSubmit">
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
                            Email
                        </label>
                        <FormInput
                            v-model="email"
                            type="email"
                            placeholder="Enter your email (Recommended)"
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
import { RecaptchaV2 } from 'vue3-recaptcha-v2'
import * as yup from 'yup'
import { userService } from '~/services/userService'

useHead({
    title: 'Create Account',
})

const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const reCaptchaToken = ref('')

const handleErrorCallback = () => {
    reCaptchaToken.value = null
}
const handleExpiredCallback = () => {
    reCaptchaToken.value = null
}
const handleLoadCallback = (response) => {
    reCaptchaToken.value = response
}

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
    email: yup.string().nullable().email('Email must be a valid email'),
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

const onSubmit = handleSubmit(async (values) => {
    // Reset alert visibility
    showAlert.value = false
    isLoading.value = true

    try {
        // if (!reCaptchaToken.value) {
        //     setTimeout(() => {
        //         alertVariant.value = 'error'
        //         alertMessage.value = 'Please verify you are not a robot'
        //         showAlert.value = true
        //     }, 0)
        //     return
        // }

        const response = await userService.register({
            ...values,
            // token: reCaptchaToken.value,
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

        resetForm()
        alertVariant.value = 'success'
        alertMessage.value = response.message
        showAlert.value = true
    } catch (error) {
        setTimeout(() => {
            console.log(error)
            alertVariant.value = 'error'
            alertMessage.value = error.errors[0]
            showAlert.value = true
        }, 0)
    } finally {
        isLoading.value = false
    }
})
</script>
