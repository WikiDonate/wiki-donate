<template>
    <div
        v-if="visible"
        :class="alertClass"
        class="flex items-start p-4 rounded-lg mb-4"
        role="alert"
    >
        <span class="flex-shrink-0">
            <svg
                v-if="variant === 'success'"
                class="w-5 h-5 text-green-500"
                fill="currentColor"
                viewBox="0 0 20 20"
                xmlns="http://www.w3.org/2000/svg"
            >
                <path
                    fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.707-5.293a1 1 0 001.414 0l3-3a1 1 0 10-1.414-1.414L10 10.586l-1.293-1.293a1 1 0 00-1.414 1.414l3 3z"
                    clip-rule="evenodd"
                />
            </svg>
            <svg
                v-if="variant === 'warning'"
                class="w-5 h-5 text-yellow-500"
                fill="currentColor"
                viewBox="0 0 20 20"
                xmlns="http://www.w3.org/2000/svg"
            >
                <path
                    fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-7h2v2h-2v-2zm0-4h2v4h-2V7z"
                    clip-rule="evenodd"
                />
            </svg>
            <svg
                v-if="variant === 'error'"
                class="w-5 h-5 text-red-500"
                fill="currentColor"
                viewBox="0 0 20 20"
                xmlns="http://www.w3.org/2000/svg"
            >
                <path
                    fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-7h2v2h-2v-2zm0-4h2v4h-2V7z"
                    clip-rule="evenodd"
                />
            </svg>
        </span>
        <span class="ml-3 break-all whitespace-pre-wrap w-full">{{
            message
        }}</span>

        <button
            class="ml-auto text-gray-400 hover:text-gray-500"
            @click="closeAlert"
        >
            <span class="sr-only">Close</span>
            <svg
                class="w-5 h-5"
                fill="currentColor"
                viewBox="0 0 20 20"
                xmlns="http://www.w3.org/2000/svg"
            >
                <path
                    fill-rule="evenodd"
                    d="M10 9.586l4.293-4.293a1 1 0 011.414 1.414L11.414 11l4.293 4.293a1 1 0 11-1.414 1.414L10 12.414l-4.293 4.293a1 1 0 11-1.414-1.414L8.586 11 4.293 6.707A1 1 0 015.707 5.293L10 9.586z"
                    clip-rule="evenodd"
                />
            </svg>
        </button>
    </div>
</template>

<script setup>
import { defineProps, ref } from 'vue'

const props = defineProps({
    variant: {
        type: String,
        default: 'success', // Default variant
        validator: (value) => ['success', 'warning', 'error'].includes(value),
    },
    message: {
        type: String,
        required: true,
    },
    duration: {
        type: Number,
        default: 0,
    },
})

const visible = ref(true)

const closeAlert = () => {
    visible.value = false
}

// Automatically hide the alert after the specified duration

if (props.duration) {
    setTimeout(() => {
        closeAlert()
    }, props.duration)
}

// Computed class based on the variant
const alertClass = computed(() => {
    switch (props.variant) {
        case 'success':
            return 'bg-green-100 border border-green-400 text-green-700'
        case 'warning':
            return 'bg-yellow-100 border border-yellow-400 text-yellow-700'
        case 'error':
            return 'bg-red-100 border border-red-400 text-red-700'
        default:
            return ''
    }
})
</script>
