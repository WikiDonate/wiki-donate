<!-- eslint-disable vue/require-explicit-emits -->
<template>
    <button
        :class="[buttonClasses, disabledClass]"
        v-bind="$attrs"
        :disabled="props.disabled"
        @click="$emit('submit')"
    >
        {{ isI18nKey(text) ? t(text) : text }}
    </button>
</template>

<script setup>
    import { computed } from 'vue'
    import { useI18n } from 'vue-i18n'

    const { t } = useI18n()

    const isI18nKey = (text) => typeof text === 'string' && /^[a-z]+\.[a-zA-Z.]+$/.test(text)

    // Define the props for the component
    const props = defineProps({
        text: {
            type: String,
            default: 'common.submit', // Default text for the button (i18n key)
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
            ? 'w-full bg-linear-to-r from-indigo-600 to-purple-600 transition-all duration-300 text-white font-bold py-2 px-4 rounded-md hover:from-indigo-500 hover:to-purple-500'
            : 'w-full bg-gray-200 text-gray-800 font-bold py-2 px-4 rounded-md hover:bg-gray-300'
    })

    // Add a conditional class for the disabled state
    const disabledClass = computed(() => {
        return props.disabled ? 'opacity-50 cursor-not-allowed' : ''
    })
</script>
