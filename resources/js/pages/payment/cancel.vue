<template>
    <main class="w-full mx-auto max-w-2xl mt-6 bg-white rounded-2xl shadow-md overflow-hidden">
        <div class="bg-gradient-to-r from-gray-500 to-gray-600 py-8 px-6 text-center">
            <div
                class="w-16 h-16 mx-auto mb-4 bg-white rounded-full flex items-center justify-center"
            >
                <font-awesome-icon
                    :icon="['fas', 'times-circle']"
                    class="w-10 h-10 text-gray-500"
                />
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-wide">
                Payment Cancelled
            </h1>
            <p class="text-base text-gray-200 mt-2">
                Your payment was not processed. No charges have been made.
            </p>
        </div>

        <div class="p-6 sm:p-8">
            <div class="text-center space-y-6">
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-6">
                    <font-awesome-icon
                        :icon="['fas', 'shield-alt']"
                        class="w-8 h-8 text-gray-400 mb-3"
                    />
                    <p class="text-gray-700 font-medium mb-1">
                        No worries — your payment information is safe.
                    </p>
                    <p class="text-gray-500 text-sm">You can try again whenever you're ready.</p>
                </div>

                <NuxtLink
                    :to="backUrl"
                    class="inline-flex items-center justify-center gap-2 px-8 py-3 rounded-lg bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold hover:from-indigo-500 hover:to-purple-500 transition-colors shadow-md"
                >
                    <font-awesome-icon :icon="['fas', 'home']" class="w-4 h-4" />
                    {{ backLabel }}
                </NuxtLink>

                <p class="text-gray-400 text-xs">
                    Having trouble?
                    <NuxtLink to="/contact" class="text-indigo-600 hover:text-indigo-800 underline"
                        >Contact support</NuxtLink
                    >
                </p>
            </div>
        </div>
    </main>
</template>

<script setup>
useHead({ title: 'Payment Cancelled' })

definePageMeta({
    // No auth middleware — users returning from Stripe may not be authenticated
})

const route = useRoute()

const backUrl = computed(() => {
    const back = route.query.back
    return back && back.startsWith('/') ? back : '/'
})

const backLabel = computed(() => (backUrl.value === '/' ? 'Back to Home' : 'Back to Article'))
</script>
