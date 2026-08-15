<!-- eslint-disable vue/html-self-closing -->
<template>
    <nav
        v-if="totalPages > 1"
        class="flex items-center space-x-1"
        aria-label="Pagination"
    >
        <!-- Previous Button -->
        <button
            class="relative inline-flex items-center px-2 py-1 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            :disabled="currentPage === 1"
            aria-label="Previous page"
            @click="emitPage(currentPage - 1)"
        >
            <font-awesome-icon :icon="['fas', 'angle-left']" class="w-5 h-5" />
        </button>

        <!-- Dynamic Pages -->
        <template v-for="page in displayPages" :key="page">
            <span v-if="page === '...'" class="px-2 text-gray-500 select-none"
                >...</span
            >
            <button
                v-else
                :class="[
                    'px-3 py-1 text-sm border rounded-md',
                    currentPage === page
                        ? 'bg-indigo-50 text-indigo-600 border-indigo-500'
                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50',
                ]"
                @click="emitPage(page)"
            >
                {{ page }}
            </button>
        </template>

        <!-- Next Button -->
        <button
            class="relative inline-flex items-center px-2 py-1 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            :disabled="currentPage === totalPages"
            aria-label="Next page"
            @click="emitPage(currentPage + 1)"
        >
            <font-awesome-icon :icon="['fas', 'angle-right']" class="w-5 h-5" />
        </button>
    </nav>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    currentPage: { type: Number, required: true },
    totalPages: { type: Number, required: true },
})

const emit = defineEmits(['page-change'])

const emitPage = (page) => {
    if (page >= 1 && page <= props.totalPages) {
        emit('page-change', page)
    }
}

const displayPages = computed(() => {
    const total = props.totalPages
    const current = props.currentPage
    const pages = []

    if (total <= 3) {
        for (let i = 1; i <= total; i++) pages.push(i)
    } else if (total === 4) {
        pages.push(1, 2, '...', 4)
    } else {
        if (current <= 2) {
            pages.push(1, 2, '...', total)
        } else if (current >= total - 1) {
            pages.push(1, '...', total - 1, total)
        } else {
            pages.push(1, '...', current, '...', total)
        }
    }

    return pages
})
</script>
