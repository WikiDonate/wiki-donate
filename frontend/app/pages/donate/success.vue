<template>
    <main
        class="w-full mx-auto max-w-2xl mt-6 bg-white rounded-2xl shadow-md overflow-hidden"
    >
        <!-- Gradient Header -->
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
                Donation Successful!
            </h1>
            <p class="text-base text-green-100 mt-2">
                Thank you for your generous contribution.
            </p>
        </div>

        <!-- Content -->
        <div class="p-6 sm:p-8">
            <!-- Loading state -->
            <div v-if="isLoading" class="flex justify-center py-8">
                <LoadingSpinner text="Loading donation details..." />
            </div>

            <!-- Error state -->
            <div
                v-else-if="error"
                class="bg-red-50 border border-red-200 rounded-lg p-6 text-center"
            >
                <font-awesome-icon
                    :icon="['fas', 'exclamation-triangle']"
                    class="w-8 h-8 text-red-400 mb-3"
                />
                <h3 class="text-lg font-semibold text-red-700 mb-2">
                    {{ error.title }}
                </h3>
                <p class="text-red-600 text-sm mb-4">
                    {{ error.message }}
                </p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <NuxtLink
                        to="/donate"
                        class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 transition-colors"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'heart']"
                            class="w-4 h-4"
                        />
                        Try Again
                    </NuxtLink>
                    <NuxtLink
                        to="/how-it-works"
                        class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg border border-gray-300 text-gray-700 text-sm font-semibold hover:bg-gray-50 transition-colors"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'search']"
                            class="w-4 h-4"
                        />
                        Explore Causes
                    </NuxtLink>
                </div>
            </div>

            <!-- Success state -->
            <div v-else-if="sessionData" class="space-y-6">
                <!-- Confirmation message -->
                <div
                    class="bg-green-50 border border-green-200 rounded-lg p-4 text-center"
                >
                    <p class="text-green-800 font-medium">
                        Your payment of
                        <span class="font-bold text-lg">{{
                            formatCurrency(sessionData.amount, sessionData.currency)
                        }}</span>
                        has been processed successfully.
                    </p>
                    <p class="text-green-600 text-sm mt-1">
                        A confirmation email will be sent to
                        <span class="font-medium">{{
                            sessionData.donor_email
                        }}</span>
                        if provided.
                    </p>
                </div>

                <!-- Donation Details -->
                <div
                    class="bg-gray-50 rounded-xl border border-gray-200 overflow-hidden"
                >
                    <div
                        class="bg-indigo-50 px-4 py-3 border-b border-gray-200"
                    >
                        <h2 class="text-lg font-semibold text-indigo-700">
                            Donation Details
                        </h2>
                    </div>
                    <div class="p-4 space-y-3">
                        <div
                            class="flex justify-between items-center py-2 border-b border-gray-100"
                        >
                            <span class="text-gray-600 text-sm">Amount</span>
                            <span class="font-semibold text-gray-900">{{
                                formatCurrency(
                                    sessionData.amount,
                                    sessionData.currency
                                )
                            }}</span>
                        </div>

                        <div
                            v-if="sessionData.donor_name"
                            class="flex justify-between items-center py-2 border-b border-gray-100"
                        >
                            <span class="text-gray-600 text-sm">Donor</span>
                            <span class="font-medium text-gray-800">{{
                                sessionData.donor_name
                            }}</span>
                        </div>

                        <div
                            v-if="sessionData.donor_email"
                            class="flex justify-between items-center py-2 border-b border-gray-100"
                        >
                            <span class="text-gray-600 text-sm">Email</span>
                            <span class="font-medium text-gray-800">{{
                                sessionData.donor_email
                            }}</span>
                        </div>

                        <div
                            class="flex justify-between items-center py-2 border-b border-gray-100"
                        >
                            <span class="text-gray-600 text-sm">Status</span>
                            <span
                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                :class="statusClass"
                            >
                                {{ sessionData.payment_status }}
                            </span>
                        </div>

                        <div
                            v-if="sessionData.cause_id"
                            class="flex justify-between items-center py-2"
                        >
                            <span class="text-gray-600 text-sm">Cause ID</span>
                            <span class="font-medium text-gray-800">{{
                                sessionData.cause_id
                            }}</span>
                        </div>

                        <div
                            class="flex justify-between items-center py-2 border-t border-gray-200"
                        >
                            <span class="text-gray-600 text-sm"
                                >Session ID</span
                            >
                            <span
                                class="font-mono text-xs text-gray-500 truncate ml-2 max-w-[200px]"
                                :title="sessionData.session_id"
                            >
                                {{ sessionData.session_id }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Action buttons -->
                <div class="flex flex-col sm:flex-row gap-3">
                    <NuxtLink
                        to="/donate"
                        class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-lg bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold hover:from-indigo-500 hover:to-purple-500 transition-colors shadow-md"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'heart']"
                            class="w-4 h-4"
                        />
                        Make Another Donation
                    </NuxtLink>
                    <NuxtLink
                        to="/how-it-works"
                        class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-lg border border-gray-300 text-gray-700 font-semibold hover:bg-gray-50 transition-colors"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'search']"
                            class="w-4 h-4"
                        />
                        Explore Causes
                    </NuxtLink>
                </div>
            </div>

            <!-- Empty / no session_id -->
            <div
                v-else
                class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center"
            >
                <font-awesome-icon
                    :icon="['fas', 'info-circle']"
                    class="w-8 h-8 text-yellow-400 mb-3"
                />
                <h3 class="text-lg font-semibold text-yellow-700 mb-2">
                    No Donation Information
                </h3>
                <p class="text-yellow-600 text-sm mb-4">
                    We couldn't find donation details. If you just completed a
                    donation, it may still be processing.
                </p>
                <NuxtLink
                    to="/donate"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-yellow-600 text-white text-sm font-semibold hover:bg-yellow-700 transition-colors"
                >
                    <font-awesome-icon
                        :icon="['fas', 'heart']"
                        class="w-4 h-4"
                    />
                    Go to Donate
                </NuxtLink>
            </div>
        </div>
    </main>
</template>

<script setup>
import { donateService } from '~/services/donateService'

useHead({
    title: 'Donation Successful',
})

const authStore = useAuthStore()

definePageMeta({
    // No auth middleware — users returning from Stripe may not be authenticated
})

const route = useRoute()
const sessionId = ref(route.query.session_id || null)
const sessionData = ref(null)
const isLoading = ref(true)
const error = ref(null)

const statusClass = computed(() => {
    if (!sessionData.value) return ''
    const status = sessionData.value.payment_status
    if (status === 'paid') return 'bg-green-100 text-green-800'
    if (status === 'unpaid') return 'bg-yellow-100 text-yellow-800'
    return 'bg-gray-100 text-gray-800'
})

function formatCurrency(amount, currency = 'usd') {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(amount)
}

async function fetchSession() {
    // No session_id in URL
    if (!sessionId.value) {
        isLoading.value = false
        return
    }

    // Validate session_id format (must start with cs_)
    if (!sessionId.value.startsWith('cs_')) {
        error.value = {
            title: 'Invalid Session ID',
            message:
                'The donation session reference appears to be invalid. Please try making another donation.',
        }
        isLoading.value = false
        return
    }

    try {
        const response = await donateService.getCheckoutSession(sessionId.value)

        if (response.success && response.data) {
            sessionData.value = response.data

            // Check payment status
            if (
                response.data.payment_status !== 'paid' &&
                response.data.payment_status !== 'no_payment_required'
            ) {
                error.value = {
                    title: 'Payment Not Completed',
                    message:
                        'Your payment has not been confirmed yet. This may take a few moments. If you believe this is an error, please contact support.',
                }
            }
        } else {
            error.value = {
                title: 'Session Not Found',
                message:
                    response.message ||
                    'We could not find your donation session. It may have expired or been cancelled.',
            }
        }
    } catch (err) {
        error.value = {
            title: 'Unable to Load Details',
            message:
                err?.message ||
                err?.errors?.[0] ||
                'A network error occurred while loading your donation details. Please try again.',
        }
    } finally {
        isLoading.value = false
    }
}

onMounted(() => {
    fetchSession()
})
</script>
