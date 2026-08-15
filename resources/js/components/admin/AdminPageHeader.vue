<template>
    <div
        class="bg-gradient-to-r from-indigo-600 to-purple-600"
        :class="containerClasses"
    >
        <div
            class="flex flex-col sm:flex-row sm:items-center justify-between gap-3"
        >
            <div class="min-w-0">
                <h2
                    class="font-bold text-xl sm:text-2xl tracking-wide text-white truncate"
                >
                    {{ title }}
                </h2>
                <p v-if="subtitle" class="text-indigo-100 text-sm mt-1">
                    {{ subtitle }}
                </p>
            </div>
            <div v-if="$slots.actions" class="flex-shrink-0">
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: '',
    },
    card: {
        type: Boolean,
        default: false,
    },
})

const containerClasses = computed(() =>
    props.card ? 'px-4 sm:px-6 py-4' : 'rounded-xl px-4 sm:px-6 py-5 mb-6'
)
</script>
