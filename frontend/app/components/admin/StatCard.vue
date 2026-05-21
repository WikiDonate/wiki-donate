<template>
    <div
        class="bg-white rounded-xl shadow-md border border-gray-200 p-5 flex items-center gap-4"
    >
        <div
            class="w-12 h-12 rounded-lg flex items-center justify-center"
            :class="iconBgClass"
        >
            <font-awesome-icon
                :icon="icon"
                class="w-6 h-6"
                :class="iconColorClass"
            />
        </div>
        <div>
            <p class="text-sm text-gray-500">{{ label }}</p>
            <p class="text-2xl font-bold text-gray-800">{{ value }}</p>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    icon: {
        type: Array,
        required: true,
    },
    iconBg: {
        type: String,
        default: 'indigo',
    },
    label: {
        type: String,
        required: true,
    },
    value: {
        type: [String, Number],
        required: true,
    },
})

const iconBgClass = computed(() => `bg-${props.iconBg}-100`)

const iconColorClass = computed(() => `text-${props.iconBg}-600`)
</script>
