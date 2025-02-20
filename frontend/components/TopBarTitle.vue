<template>
    <section
        class="container mx-auto bg-white border-b border-b-gray-300 py-2 px-1 flex justify-between"
    >
        <!-- Left Title -->
        <div class="space-x-4 text-sm flex basis-6/12 justify-start">
            <h1 class="font-bold text-xl mb-2">
                {{ pageTitle }}
            </h1>
        </div>

        <!-- Right items -->
        <div class="space-x-4 text-sm flex basis-6/12 justify-end items-center">
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
