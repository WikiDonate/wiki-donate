<!-- eslint-disable vue/require-explicit-emits -->
<template>
    <button :class="buttonClasses" @click="$emit('click')">
        <slot>{{ text }}</slot>
    </button>
</template>

<script setup>
import { computed, defineProps } from 'vue'

// Define the props for the component
const props = defineProps({
    text: {
        type: String,
        default: 'Submit', // Default text for the button
    },
    variant: {
        type: String,
        default: 'primary', // Default variant
        validator: (value) => ['primary', 'secondary'].includes(value), // Validate the variant
    },
    width: {
        type: String, // Accept width as a prop
        default: '1/4', // Default to full width
        validator: (value) =>
            ['full', 'auto', '1/2', '1/3', '1/4', 'custom'].includes(value), // Validate against known widths
    },
    class: {
        type: String,
        default: '', // Optional class, can be empty
    },
})

// Compute button classes based on the variant and width
const buttonClasses = computed(() => {
    // Dynamic width classes
    const widthClass =
        props.width === 'full'
            ? 'w-full'
            : props.width === 'auto'
              ? 'w-auto'
              : `w-${props.width}` // For w-1/2, w-1/3, w-1/4

    // Variant-based classes
    const variantClass =
        props.variant === 'primary'
            ? 'bg-blue-600 text-white font-bold py-2 px-4 rounded-md hover:bg-blue-700'
            : 'bg-gray-200 text-gray-800 font-bold py-2 px-4 rounded-md hover:bg-gray-300'

    return `${widthClass} ${variantClass} ${props.class}`
})
</script>
