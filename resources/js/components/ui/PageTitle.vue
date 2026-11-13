<template>
    <h1
        class="text-3xl sm:text-4xl font-bold text-center bg-clip-text text-transparent bg-linear-to-r from-indigo-600 to-purple-600 mb-8"
    >
        {{ title }}
    </h1>
</template>

<script setup>
    defineProps({
        title: {
            type: String,
            required: true,
        },
    })
</script>
