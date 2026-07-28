<template>
    <main
        class="w-full mx-auto max-w-2xl mt-6 bg-white rounded-2xl shadow-md overflow-hidden"
    >
        <div
            class="bg-gradient-to-r from-green-500 to-emerald-600 py-8 px-6 text-center"
        >
            <div
                class="w-16 h-16 mx-auto mb-4 bg-white rounded-full flex items-center justify-center"
            >
                <font-awesome-icon
                    :icon="['fas', 'check-circle']"
                    class="w-10 h-10 text-green-500"
                />
            </div>
            <h1
                class="text-2xl sm:text-3xl font-extrabold text-white tracking-wide"
            >
                Payment Successful
            </h1>
            <p class="text-base text-green-100 mt-2">
                Thank you for your generous donation!
            </p>
        </div>

        <div class="p-6 sm:p-8 text-center space-y-6">
            <div v-if="loading" class="flex justify-center py-4">
                <LoadingSpinner text="Verifying payment..." />
            </div>

            <template v-else>
                <div
                    v-if="sessionData"
                    class="bg-green-50 border border-green-200 rounded-lg p-6"
                >
                    <p class="text-green-700 font-medium mb-1">
                        Your donation has been processed.
                    </p>
                    <p class="text-gray-600 text-sm">
                        Amount:
                        <span class="font-bold"
                            >{{ formatAmount(sessionData.amount) }}
                            {{ sessionData.currency?.toUpperCase() }}</span
                        >
                    </p>
                </div>

                <div
                    v-else
                    class="bg-gray-50 border border-gray-200 rounded-lg p-6"
                >
                    <p class="text-gray-700 font-medium">
                        Your payment was successful.
                    </p>
                </div>

                <NuxtLink
                    to="/"
                    class="inline-flex items-center justify-center gap-2 px-8 py-3 rounded-lg bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold hover:from-indigo-500 hover:to-purple-500 transition-colors shadow-md"
                >
                    <font-awesome-icon
                        :icon="['fas', 'home']"
                        class="w-4 h-4"
                    />
                    Back to Home
                </NuxtLink>
            </template>
        </div>
    </main>
</template>

<script setup>
import api from '~/config/apiConfig'

const route = useRoute()
const sessionData = ref(null)
const loading = ref(false)

useHead({ title: 'Payment Successful' })

definePageMeta({
    // No auth middleware — users returning from Stripe may not be authenticated
})

const formatAmount = (amount) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount)
}

onMounted(async () => {
    const sessionId = route.query.session_id
    if (sessionId) {
        loading.value = true
        try {
            const response = await api.get(`/stripe/checkout/${sessionId}`)
            if (response.success) {
                sessionData.value = response.data
            }
        } catch {
            // Silently fail — user still sees success message
        } finally {
            loading.value = false
        }
    }
})
</script>
