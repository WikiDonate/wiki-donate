<template>
    <main class="w-full mx-auto">
        <div class="container mx-auto p-4 md:p-6 w-full sm:w-2/3">
            <h1
                class="text-2xl lg:text-4xl font-bold text-gray-800 mb-6 text-center"
            >
                Create an Account
            </h1>

            <!-- Message -->
            <AlertMessage
                v-if="showAlert"
                :variant="alertVariant"
                :message="alertMessage"
                @close="showAlert = false"
            />

            <!-- Form -->
            <form @submit.prevent="onSubmit">
                <label
                    for="username"
                    class="block text-sm font-medium text-gray-700"
                    >Username
                </label>
                <FormInput
                    v-model="username"
                    type="text"
                    placeholder="Enter your username"
                    class="mb-3"
                    v-bind="usernameProps"
                    :error-message="errors['username']"
                />
                <label
                    for="password"
                    class="block text-sm font-medium text-gray-700"
                    >Password
                </label>
                <FormInput
                    v-model="password"
                    type="password"
                    placeholder="Enter your password"
                    class="mb-3"
                    v-bind="passwordProps"
                    :error-message="errors['password']"
                />
                <label
                    for="confirmPassword"
                    class="block text-sm font-medium text-gray-700"
                    >Confirm Password
                </label>
                <FormInput
                    v-model="confirmPassword"
                    type="password"
                    placeholder="Confirm password"
                    class="mb-3"
                    v-bind="confirmPasswordProps"
                    :error-message="errors['confirmPassword']"
                />
                <label
                    for="email"
                    class="block text-sm font-medium text-gray-700"
                    >Email
                </label>
                <FormInput
                    v-model="email"
                    type="email"
                    placeholder="Enter your email (Recommended)"
                    class="mb-3"
                    v-bind="emailProps"
                    :error-message="errors['email']"
                />

                <div>
                    <RecaptchaV2
                        @error-callback="handleErrorCallback"
                        @expired-callback="handleExpiredCallback"
                        @load-callback="handleLoadCallback"
                    />
                </div>

                <!-- Submit Button -->
                <div class="flex justify-center mt-3">
                    <FormSubmitButton
                        text="Create Account"
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

const onSubmit = handleSubmit(async (values) => {
    // Reset alert visibility
    showAlert.value = false

    try {
        if (!reCaptchaToken.value) {
            setTimeout(() => {
                alertVariant.value = 'error'
                alertMessage.value = 'Please verify you are not a robot'
                showAlert.value = true
            }, 0)
            return
        }

        const response = await userService.register({
            ...values,
            token: reCaptchaToken.value,
        })
        if (!response.success) {
            setTimeout(() => {
                alertVariant.value = 'error'
                alertMessage.value = response.errors[0]
                showAlert.value = true
            }, 0)
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
    }
})
</script>
