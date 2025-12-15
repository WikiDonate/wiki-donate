<!-- eslint-disable vue/require-explicit-emits -->
<!-- eslint-disable vue/html-self-closing -->
<template>
    <div>
        <textarea
            :value="modelValue"
            :class="inputClasses"
            rows="4"
            @input="$emit('update:modelValue', $event.target.value)"
            @blur="$emit('blur')"
        ></textarea>
        <div v-if="errorMessage" class="text-red-600 text-sm mt-1">
            {{ errorMessage }}
        </div>
    </div>
</template>

<script setup>
import { computed, defineProps } from 'vue'

// Define props
const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    errorMessage: {
        type: String,
        default: '',
    },
})

// Classes for textarea based on validation state
const inputClasses = computed(() => {
    return [
        'border rounded py-2 px-3 w-full focus:outline-none resize-none',
        props.errorMessage
            ? 'border-red-500 focus:ring-red-500'
            : 'border-gray-300 focus:ring-blue-200',
    ]
})
</script>
