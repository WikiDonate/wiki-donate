<!-- eslint-disable vue/html-self-closing -->
<template>
    <img :src="imageSrc" :alt="imageAlt" :class="customClass" />
</template>

<script setup>
defineProps({
    imageSrc: {
        type: String,
        required: true,
    },
    imageAlt: {
        type: String,
        default: 'Image',
    },
    customClass: {
        type: String,
        default: '',
    },
})
</script>
