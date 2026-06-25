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
                        Email Verification
                    </h1>
                    <p class="mt-2 text-sm">
                        Verifying your email address
                    </p>
                </div>

                <div class="p-6 sm:p-8 text-center">
                    <!-- Loading State -->
                    <div v-if="isLoading" class="space-y-4">
                        <div class="flex justify-center">
                            <div class="relative">
                                <div
                                    class="w-16 h-16 border-4 border-indigo-200 rounded-full animate-spin"
                                ></div>
                                <div
                                    class="w-16 h-16 border-4 border-transparent border-t-indigo-600 rounded-full absolute top-0 left-0 animate-spin"
                                ></div>
                            </div>
                        </div>
                        <p class="text-gray-600 text-sm">
                            Please wait while we verify your email address...
                        </p>
                    </div>

                    <!-- Success State -->
                    <div v-else-if="isSuccess" class="space-y-4">
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
                                        d="M5 13l4 4L19 7"
                                    ></path>
                                </svg>
                            </div>
                        </div>
                        <h2 class="text-xl font-semibold text-gray-800">
                            Email Verified!
                        </h2>
                        <p class="text-gray-600 text-sm">
                            Your email address has been successfully verified.
                            You can now access all features of your account.
                        </p>
                        <div class="pt-4">
                            <NuxtLink
                                to="/login"
                                class="inline-block bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-medium px-6 py-3 rounded-lg hover:from-indigo-700 hover:to-purple-700 transition-all duration-200 shadow-md hover:shadow-lg"
                            >
                                Continue to Login
                            </NuxtLink>
                        </div>
                    </div>

                    <!-- Error State -->
                    <div v-else class="space-y-4">
                        <div class="flex justify-center">
                            <div
                                class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center"
                            >
                                <svg
                                    class="w-8 h-8 text-red-500"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    ></path>
                                </svg>
                            </div>
                        </div>
                        <h2 class="text-xl font-semibold text-gray-800">
                            Verification Failed
                        </h2>
                        <p class="text-gray-600 text-sm">
                            {{ errorMessage || 'The verification link is invalid or has expired. Please request a new one.' }}
                        </p>
                        <div class="pt-4 space-y-2">
                            <NuxtLink
                                to="/login"
                                class="inline-block bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-medium px-6 py-3 rounded-lg hover:from-indigo-700 hover:to-purple-700 transition-all duration-200 shadow-md hover:shadow-lg"
                            >
                                Back to Login
                            </NuxtLink>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</template>

<script setup>
import { userService } from '~/services/userService'

useHead({
    title: 'Verify Email',
})

const route = useRoute()
const isLoading = ref(true)
const isSuccess = ref(false)
const errorMessage = ref('')

onMounted(async () => {
    // Check if we have query parameters from backend redirect
    const { status, message } = route.query

    if (status) {
        // Handle redirect from backend
        isLoading.value = false
        if (status === 'success') {
            isSuccess.value = true
        } else {
            errorMessage.value = message || 'Verification failed.'
        }
        return
    }

    // Handle direct API call with id and hash
    const { id, hash } = route.query

    if (!id || !hash) {
        isLoading.value = false
        errorMessage.value = 'Invalid verification link.'
        return
    }

    try {
        const response = await userService.verifyEmail(id, hash)

        if (response.success) {
            isSuccess.value = true
        } else {
            errorMessage.value = response.message || 'Verification failed.'
        }
    } catch (error) {
        errorMessage.value = error.message || 'An error occurred during verification.'
    } finally {
        isLoading.value = false
    }
})
</script>
