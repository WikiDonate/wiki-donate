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

const bgMap = { indigo: 'bg-indigo-100', purple: 'bg-purple-100', green: 'bg-green-100', amber: 'bg-amber-100', red: 'bg-red-100', blue: 'bg-blue-100' }
const colorMap = { indigo: 'text-indigo-600', purple: 'text-purple-600', green: 'text-green-600', amber: 'text-amber-600', red: 'text-red-600', blue: 'text-blue-600' }
const iconBgClass = computed(() => bgMap[props.iconBg] || 'bg-indigo-100')
const iconColorClass = computed(() => colorMap[props.iconBg] || 'text-indigo-600')
</script>
