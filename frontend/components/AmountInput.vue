<!-- eslint-disable vue/html-self-closing -->
<template>
    <input
        :id="id"
        type="number"
        class="border border-gray-300 rounded py-2 px-3 w-full md:w-auto focus:outline-none focus:ring-1 focus:ring-blue-200"
        :placeholder="placeholder"
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>

<script setup>
import { defineEmits, defineProps } from 'vue'

defineProps({
    id: {
        type: String,
        default: 'amount',
    },
    placeholder: {
        type: String,
        default: 'Custom Amount',
    },
    modelValue: {
        type: [String, Number],
        default: '',
    },
})

defineEmits(['update:modelValue'])
</script>
