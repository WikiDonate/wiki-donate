<!-- eslint-disable vue/require-explicit-emits -->
<!-- eslint-disable vue/html-self-closing -->
<template>
    <div>
        <input
            :type="type"
            :value="modelValue"
            :class="inputClasses"
            :placeholder="placeholder"
            @input="$emit('update:modelValue', $event.target.value)"
            @blur="$emit('blur')"
        />
        <div v-if="errorMessage" class="text-red-600 text-sm mt-1">
            {{ errorMessage }}
        </div>
    </div>
</template>

<script setup>
import { computed, defineProps } from 'vue'

// Define props
const props = defineProps({
    placeholder: {
        type: String,
        default: '',
    },
    modelValue: {
        type: [String, Number],
        default: '',
    },
    type: {
        type: String,
        default: 'text',
    },
    errorMessage: {
        type: String,
        default: '',
    },
})

// Classes for input based on validation state
const inputClasses = computed(() => {
    return [
        'border rounded py-2 px-3 w-full focus:outline-none',
        props.errorMessage
            ? 'border-red-500 focus:ring-red-500'
            : 'border-gray-300 focus:ring-blue-200',
    ]
})
</script>
