<!-- eslint-disable vue/require-explicit-emits -->
<template>
    <button
        :class="[buttonClasses, disabledClass]"
        v-bind="$attrs"
        :disabled="props.disabled"
        @click="$emit('submit')"
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
    disabled: {
        type: Boolean,
        default: false, // Default disabled state
    },
})

// Compute button classes based on the variant
const buttonClasses = computed(() => {
    return props.variant === 'primary'
        ? 'w-full bg-blue-600 text-white font-bold py-2 px-4 rounded-md hover:bg-blue-700'
        : 'w-full bg-gray-200 text-gray-800 font-bold py-2 px-4 rounded-md hover:bg-gray-300'
})

// Add a conditional class for the disabled state
const disabledClass = computed(() => {
    return props.disabled ? 'opacity-50 cursor-not-allowed' : ''
})
</script>
