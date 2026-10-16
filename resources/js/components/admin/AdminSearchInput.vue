<template>
    <div class="relative flex-1">
        <font-awesome-icon
            :icon="['fas', 'search']"
            class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
        />
        <input
            v-model="text"
            type="text"
            :placeholder="placeholder"
            class="w-full pl-9 pr-9 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
            @keyup.enter="$emit('enter')"
        />
        <button
            v-if="text"
            type="button"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
            :title="t('common.clear')"
            @click="clear"
        >
            <font-awesome-icon :icon="['fas', 'times']" class="w-3.5 h-3.5" />
        </button>
    </div>
</template>

<script setup>
    import { computed } from 'vue'
    import { useI18n } from 'vue-i18n'

    const { t } = useI18n()

    const props = defineProps({
        modelValue: {
            type: String,
            default: '',
        },
        placeholder: {
            type: String,
            default: '',
        },
    })

    const emit = defineEmits(['update:modelValue', 'clear', 'enter'])

    const text = computed({
        get: () => props.modelValue ?? '',
        set: (value) => emit('update:modelValue', value),
    })

    const clear = () => {
        emit('update:modelValue', '')
        emit('clear')
    }
</script>
