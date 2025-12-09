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
                        Password Recovery
                    </h1>
                    <p class="mt-2 text-sm">
                        Enter your email to reset your password
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
                            for="email"
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >Email <span class="text-red-500">*</span>
                        </label>
                        <FormInput
                            v-model="email"
                            type="email"
                            placeholder="Enter your email"
                            v-bind="emailProps"
                            :error-message="errors['email']"
                        />

                        <!-- Submit Button -->
                        <div class="flex justify-center mt-4">
                            <FormSubmitButton
                                :text="isLoading ? 'Sending...' : 'Send'"
                                type="submit"
                                variant="primary"
                                :disabled="isLoading"
                            />
                        </div>

                        <!-- Back to Login Link -->
                        <div class="text-center text-sm text-gray-600 mt-6">
                            Remember your password?
                            <NuxtLink
                                to="/login"
                                class="font-medium text-indigo-600 hover:text-indigo-500 hover:underline ml-1"
                            >
                                Back to Login
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
    title: 'Forgot Password',
})

const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const isLoading = ref(false)

const validationSchema = yup.object({
    email: yup
        .string()
        .required('Email is required')
        .email('Email must be a valid email'),
})

// Setup VeeValidate
const { handleSubmit, defineField, errors } = useForm({
    validationSchema,
})

// Define fields using defineField
const [email, emailProps] = defineField('email')

const onSubmit = handleSubmit(async (values) => {
    showAlert.value = false
    isLoading.value = true

    try {
        const response = await userService.forgotPassword({
            email: values.email,
        })

        if (!response.success) {
            alertVariant.value = 'error'
            alertMessage.value = response.errors[0]
            showAlert.value = true
            isLoading.value = false
            return
        }

        alertVariant.value = 'success'
        alertMessage.value = response.message
        showAlert.value = true
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value = error.errors[0]
        showAlert.value = true
    } finally {
        isLoading.value = false
    }
})
</script>
