<!-- eslint-disable vue/require-explicit-emits -->
<template>
    <button
        :class="buttonClasses"
        :disabled="disabled"
        @click="!disabled && $emit('click')"
    >
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
    disabled: {
        type: Boolean,
        default: false,
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
    let variantClass =
        props.variant === 'primary'
            ? 'bg-gradient-to-r from-indigo-600 to-purple-600 transition-all duration-300 text-white font-bold py-2 px-4 rounded-md hover:from-indigo-500 hover:to-purple-500'
            : 'bg-gray-200 text-gray-800 font-bold py-2 px-4 rounded-md hover:bg-gray-300'

    // Disabled state classes
    if (props.disabled) {
        variantClass =
            props.variant === 'primary'
                ? 'bg-gray-400 text-gray-200 font-bold py-2 px-4 rounded-md cursor-not-allowed opacity-70'
                : 'bg-gray-100 text-gray-400 font-bold py-2 px-4 rounded-md cursor-not-allowed opacity-70'
    }

    return `${widthClass} ${variantClass} ${props.class}`
})
</script>
