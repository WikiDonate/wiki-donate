<template>
    <div
        v-if="modelValue"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
    >
        <div
            class="bg-white rounded-xl shadow-xl w-full p-6 space-y-4 max-h-[80vh] overflow-y-auto"
            :class="size === 'lg' ? 'max-w-lg' : 'max-w-md'"
        >
            <div class="flex items-start justify-between gap-3">
                <h3 class="text-lg font-bold text-gray-800">{{ title }}</h3>
                <button
                    type="button"
                    class="p-1 text-gray-400 hover:text-gray-600 transition-colors shrink-0"
                    :title="t('common.close')"
                    @click="close"
                >
                    <font-awesome-icon :icon="['fas', 'times']" class="w-4 h-4" />
                </button>
            </div>
            <slot />
            <div v-if="$slots.footer" class="flex justify-end gap-2 pt-2">
                <slot name="footer" />
            </div>
        </div>
    </div>
</template>

<script setup>
    import { useI18n } from 'vue-i18n'

    const { t } = useI18n()

    defineProps({
        modelValue: {
            type: Boolean,
            default: false,
        },
        title: {
            type: String,
            default: '',
        },
        size: {
            type: String,
            default: 'md',
            validator: (v) => ['md', 'lg'].includes(v),
        },
    })

    const emit = defineEmits(['update:modelValue', 'close'])

    const close = () => {
        emit('update:modelValue', false)
        emit('close')
    }
</script>
