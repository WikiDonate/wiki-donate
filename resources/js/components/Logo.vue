<template>
    <div class="flex items-center" :class="containerClass">
        <div
            class="bg-indigo-600 rounded-xl flex items-center justify-center shadow-md"
            :class="iconContainerSize"
        >
            <font-awesome-icon
                :icon="['fas', 'hands-praying']"
                class="text-white"
                :class="iconSize"
            />
        </div>
        <h2
            class="font-bold bg-clip-text text-transparent bg-linear-to-r from-indigo-600 to-purple-600"
            :class="textClass"
        >
            WikiDonate
        </h2>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
})

const sizes = {
    sm: {
        iconContainer: 'w-8 h-8 sm:w-10 sm:h-10',
        icon: 'h-5 w-5 sm:h-6 sm:w-6',
        text: 'text-lg sm:text-2xl',
        container: '',
    },
    md: {
        iconContainer: 'w-10 h-10',
        icon: 'h-6 w-6',
        text: 'text-xl',
        container: '',
    },
    lg: {
        iconContainer: 'w-12 h-12 lg:w-16 lg:h-16',
        icon: 'h-8 w-8 lg:h-10 lg:w-10',
        text: 'text-2xl sm:text-3xl lg:text-5xl',
        container: '',
    },
}

const iconContainerSize = computed(() => sizes[props.size].iconContainer)
const iconSize = computed(() => sizes[props.size].icon)
const textClass = computed(() => sizes[props.size].text)
const containerClass = computed(() => sizes[props.size].container)
</script>
