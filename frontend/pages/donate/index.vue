<template>
    <main
        class="w-full mx-auto max-w-7xl mt-6 bg-white rounded-2xl shadow-md overflow-hidden"
    >
        <!-- Gradient Header -->
        <div
            class="bg-gradient-to-r from-indigo-600 to-purple-600 py-6 px-6 text-center"
        >
            <h1
                class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white tracking-wide"
            >
                Explore Causes
            </h1>
            <p class="text-base sm:text-lg text-indigo-100 mt-2">
                Browse and support causes you care about.
            </p>
        </div>

        <!-- Content -->
        <div class="container p-4 sm:p-6 md:p-8">
            <div v-if="isLoadingCauses" class="flex justify-center py-12">
                <LoadingSpinner text="Loading Causes" />
            </div>

            <!-- Empty state -->
            <div
                v-else-if="causes.length === 0"
                class="text-center text-gray-500 text-lg font-semibold py-12"
            >
                No causes found.
            </div>

            <div v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="cause in causes"
                    :key="cause.id"
                    class="bg-white rounded-xl shadow-md border border-gray-200 hover:shadow-lg hover:border-indigo-300 transition cursor-pointer flex flex-col"
                    @click="goToCause(cause.id)"
                >
                    <!-- Card header -->
                    <div
                        class="bg-indigo-50 px-4 py-3 rounded-t-xl border-b border-gray-200"
                    >
                        <h2
                            class="text-lg sm:text-xl text-indigo-700 font-semibold"
                        >
                            Cause
                        </h2>
                    </div>

                    <!-- Card content -->
                    <div class="p-4 sm:p-5 flex flex-col flex-grow">
                        <h3
                            class="text-lg sm:text-xl font-bold text-gray-800 mb-3 line-clamp-2"
                        >
                            {{ cause.title }}
                        </h3>
                        <p class="text-gray-600 flex-grow line-clamp-4">
                            {{ cause.description }}
                        </p>
                    </div>

                    <!-- Action button -->
                    <div class="px-4 sm:px-5 pb-4">
                        <button
                            class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-2 rounded-full font-semibold hover:from-indigo-500 hover:to-purple-500 transition"
                        >
                            View & Donate
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>
</template>

<script setup>
import { useCauseStore } from '@/stores/causeStore'

useHead({
    title: 'Donate',
})

definePageMeta({
    middleware: 'auth',
})

const causeStore = useCauseStore()
const { causes, isLoadingCauses } = storeToRefs(causeStore)
const router = useRouter()

const goToCause = (id) => {
    router.push(`/donate/${id}`)
}

onMounted(() => {
    causeStore.getCauses()
})
</script>
