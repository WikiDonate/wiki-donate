<template>
    <div
        class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5 hover:shadow-md transition-shadow duration-200"
    >
        <div class="flex items-start justify-between mb-3">
            <div
                class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                :class="iconBgClass"
            >
                <font-awesome-icon
                    :icon="icon"
                    class="w-4 h-4"
                    :class="iconColorClass"
                />
            </div>
            <div class="w-2 h-2 rounded-full flex-shrink-0" :class="dotClass" />
        </div>
        <p
            class="text-xs text-gray-400 font-medium uppercase tracking-wider mb-1"
        >
            {{ label }}
        </p>
        <p class="text-2xl sm:text-3xl font-bold text-gray-800 leading-tight">
            {{ value }}
        </p>
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

const bgMap = {
    indigo: 'bg-indigo-50',
    purple: 'bg-purple-50',
    green: 'bg-green-50',
    amber: 'bg-amber-50',
    red: 'bg-red-50',
    blue: 'bg-blue-50',
}
const colorMap = {
    indigo: 'text-indigo-600',
    purple: 'text-purple-600',
    green: 'text-green-600',
    amber: 'text-amber-600',
    red: 'text-red-600',
    blue: 'text-blue-600',
}
const dotMap = {
    indigo: 'bg-indigo-400',
    purple: 'bg-purple-400',
    green: 'bg-green-400',
    amber: 'bg-amber-400',
    red: 'bg-red-400',
    blue: 'bg-blue-400',
}
const iconBgClass = computed(() => bgMap[props.iconBg] || 'bg-indigo-50')
const iconColorClass = computed(
    () => colorMap[props.iconBg] || 'text-indigo-600'
)
const dotClass = computed(() => dotMap[props.iconBg] || 'bg-indigo-400')
</script>
