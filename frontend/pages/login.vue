<template>
    <main class="w-full mx-auto">
        <div class="container mx-auto p-4 md:p-6 w-full sm:w-2/3">
            <h1
                class="text-2xl lg:text-4xl font-bold text-gray-800 mb-6 text-center"
            >
                Login
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

                <!-- Submit Button -->
                <div class="flex justify-center">
                    <FormSubmitButton
                        text="Login"
                        type="submit"
                        variant="primary"
                        @click="onSubmit"
                    />
                </div>
                <!-- Forgot Password Link -->
                <div class="text-right mb-3">
                    <NuxtLink
                        to="/forgot-password"
                        class="text-sm text-blue-600 hover:underline"
                        >Forgot Password?</NuxtLink
                    >
                </div>
            </form>
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

    try {
        const response = await authService.login({
            username: values.username,
            password: values.password,
        })

        if (!response.success) {
            alertVariant.value = 'error'
            alertMessage.value = response.errors[0]
            showAlert.value = true
            return
        }

        authStore.login(response.data)
        localStorage.setItem('token', response.data.token)
        router.push('/')
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value = error.errors[0]
        showAlert.value = true
    }
})
</script>
