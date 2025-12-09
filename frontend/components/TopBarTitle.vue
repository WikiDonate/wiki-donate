<template>
    <section
        class="container mx-auto bg-white border-b border-b-indigo-300 py-2 px-2 sm:px-4 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2"
    >
        <!-- Left Title -->
        <h1
            class="font-bold text-lg sm:text-xl md:text-2xl text-center sm:text-left bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-purple-600"
        >
            {{ pageTitle }}
        </h1>

        <!-- Right items -->
        <div
            v-if="rightSide"
            class="text-sm text-indigo-600 text-center sm:text-right"
        >
            {{ rightSide }}
        </div>
    </section>
</template>

<script setup>
import { defineProps } from 'vue'

// Define props to accept dynamic content
defineProps({
    pageTitle: {
        type: String,
        default: 'Big Brother (UK TV series)', // default title if not provided
    },
    rightSide: {
        type: String,
        default: '',
    },
})
</script>
