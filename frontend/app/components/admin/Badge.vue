<template>
    <span
        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
        :class="variantClasses"
    >
        <slot>{{ text }}</slot>
    </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    variant: {
        type: String,
        default: 'success',
        validator: (v) =>
            [
                'success',
                'warning',
                'danger',
                'info',
                'purple',
                'amber',
            ].includes(v),
    },
    text: {
        type: String,
        default: '',
    },
})

const variantClasses = computed(() => {
    const map = {
        success: 'bg-green-100 text-green-800',
        warning: 'bg-yellow-100 text-yellow-800',
        danger: 'bg-red-100 text-red-800',
        info: 'bg-blue-100 text-blue-800',
        purple: 'bg-purple-100 text-purple-800',
        amber: 'bg-amber-100 text-amber-800',
    }
    return map[props.variant] || map.success
})
</script>
