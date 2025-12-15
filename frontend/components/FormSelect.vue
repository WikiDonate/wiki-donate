<!-- eslint-disable vue/require-explicit-emits -->
<template>
    <div>
        <select
            :value="modelValue"
            :class="selectClasses"
            :disabled="disabled"
            @change="$emit('update:modelValue', $event.target.value)"
            @blur="$emit('blur')"
        >
            <option disabled value="">
                {{ placeholder || 'Select an option' }}
            </option>
            <option
                v-for="(option, index) in options"
                :key="index"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>

        <div v-if="errorMessage" class="text-red-600 text-sm mt-1">
            {{ errorMessage }}
        </div>
    </div>
</template>

<script setup>
import { computed, defineProps } from 'vue'

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    options: {
        type: Array,
        required: true,
    },
    placeholder: {
        type: String,
        default: '',
    },
    errorMessage: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
})

const selectClasses = computed(() => {
    return [
        'border rounded py-2 px-3 w-full focus:outline-none',
        props.errorMessage
            ? 'border-red-500 focus:ring-red-500'
            : 'border-gray-300 focus:ring-blue-200',
    ]
})
</script>
