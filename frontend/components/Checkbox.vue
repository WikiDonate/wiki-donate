<!-- eslint-disable vue/html-self-closing -->
<template>
    <div class="flex items-center space-x-2">
        <input
            :id="checkboxId"
            type="checkbox"
            :checked="modelValue"
            class="form-checkbox h-4 w-4 text-indigo-600 rounded focus:ring-0 focus:outline-none"
            @change="emitChange"
        />
    </div>
</template>

<script setup>
import { defineEmits, defineProps } from 'vue'

// Define props for checkbox
defineProps({
    modelValue: {
        type: Boolean,
        default: false, // Default unchecked state
    },
    checkboxId: {
        type: Number,
        default: null, // Optional custom ID for the checkbox
    },
})

// Emit update event to notify parent of changes
const emit = defineEmits(['update:modelValue'])

const emitChange = (event) => {
    emit('update:modelValue', event.target.checked)
}
</script>
