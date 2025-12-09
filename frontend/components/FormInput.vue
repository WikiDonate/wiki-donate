<!-- eslint-disable vue/require-explicit-emits -->
<!-- eslint-disable vue/html-self-closing -->
<template>
    <div>
        <div class="relative">
            <input
                :type="isPassword && showPassword ? 'text' : type"
                :value="modelValue"
                :class="[inputClasses, isPassword ? 'pr-10' : '']"
                :placeholder="placeholder"
                :disabled="disabled"
                @input="$emit('update:modelValue', $event.target.value)"
                @blur="$emit('blur')"
            />

            <!-- Eye icon only for password type -->
            <button
                v-if="isPassword"
                type="button"
                tabindex="-1"
                class="absolute inset-y-0 right-3 flex items-center text-gray-500 hover:text-gray-700"
                @click="togglePassword"
            >
                <font-awesome-icon
                    :icon="showPassword ? ['fas', 'eye-slash'] : ['fas', 'eye']"
                    class="h-5 w-5"
                />
            </button>
        </div>
        <div v-if="errorMessage" class="text-red-600 text-sm mt-1">
            {{ errorMessage }}
        </div>
    </div>
</template>

<script setup>
import { computed, defineProps, ref } from 'vue'

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
    disabled: {
        type: Boolean,
        default: false,
    },
})

// state for show/hide password
const showPassword = ref(false)
const isPassword = computed(() => props.type === 'password')

// toggle function
const togglePassword = () => {
    showPassword.value = !showPassword.value
}

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
