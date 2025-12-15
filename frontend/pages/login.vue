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
                        Login to Your Account
                    </h1>
                    <p class="mt-2 text-sm">
                        Welcome back! Please sign in to continue
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
                            >Password <span class="text-red-500">*</span>
                        </label>
                        <FormInput
                            v-model="password"
                            type="password"
                            placeholder="Enter your password"
                            v-bind="passwordProps"
                            :error-message="errors['password']"
                        />
                        <!-- Forgot Password Link -->
                        <div class="text-right mb-1">
                            <NuxtLink
                                to="/forgot-password"
                                class="text-sm font-medium text-indigo-600 hover:text-indigo-500 hover:underline"
                            >
                                Forgot Password?
                            </NuxtLink>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex justify-center mt-4">
                            <FormSubmitButton
                                :text="
                                    isLoading ? 'Authenticating...' : 'Login'
                                "
                                type="submit"
                                variant="primary"
                                :disabled="isLoading"
                            />
                        </div>

                        <!-- Sign Up Link -->
                        <div class="text-center text-sm text-gray-600 mt-6">
                            Don't have an account?
                            <NuxtLink
                                to="/create-account"
                                class="font-medium text-indigo-600 hover:text-indigo-500 hover:underline ml-1"
                            >
                                Create Account
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
import { authService } from '~/services/authService'
import { useAuthStore } from '~/stores/authStore'

useHead({
    title: 'Login',
})

const router = useRouter()
const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const isLoading = ref(false)
const authStore = useAuthStore()

const validationSchema = yup.object({
    username: yup.string().required('Username is required'),
    password: yup.string().required('Password is required'),
})

// Setup VeeValidate
const { handleSubmit, defineField, errors } = useForm({
    validationSchema,
})

// Define fields using defineField
const [username, usernameProps] = defineField('username')
const [password, passwordProps] = defineField('password')

const onSubmit = handleSubmit(async (values) => {
    // Reset alert visibility
    showAlert.value = false
    isLoading.value = true

    try {
        const response = await authService.login({
            username: values.username,
            password: values.password,
        })

        if (!response.success) {
            alertVariant.value = 'error'
            alertMessage.value = response.errors[0]
            showAlert.value = true
            isLoading.value = false
            return
        }

        authStore.login(response.data)
        localStorage.setItem('token', response.data.token)
        router.push('/main')
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value = error.errors?.[0] || 'Login failed'
        showAlert.value = true
    } finally {
        isLoading.value = false
    }
})
</script>
