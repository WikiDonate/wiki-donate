<template>
    <div
        class="bg-white rounded-xl shadow-md border border-gray-200 p-4 sm:p-5 flex items-center gap-3 sm:gap-4"
    >
        <div
            class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg flex items-center justify-center flex-shrink-0"
            :class="iconBgClass"
        >
            <font-awesome-icon
                :icon="icon"
                class="w-5 h-5 sm:w-6 sm:h-6"
                :class="iconColorClass"
            />
        </div>
        <div class="min-w-0">
            <p class="text-xs sm:text-sm text-gray-500 truncate">{{ label }}</p>
            <p class="text-xl sm:text-2xl font-bold text-gray-800">
                {{ value }}
            </p>
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
