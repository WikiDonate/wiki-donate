<!-- eslint-disable vue/html-self-closing -->
<template>
    <div class="formula-sort-wrapper relative">
        <button
            class="flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors"
            @click.stop="isOpen = !isOpen"
        >
            <font-awesome-icon
                :icon="['fas', 'arrow-down-wide-short']"
                class="w-3.5 h-3.5 text-gray-400"
            />
            <span>{{ t(options.find((option) => option.value === modelValue)?.labelKey) }}</span>
            <font-awesome-icon
                :icon="['fas', 'chevron-down']"
                class="w-3 h-3 text-gray-400 transition-transform"
                :class="{ 'rotate-180': isOpen }"
            />
        </button>

        <ul
            v-if="isOpen"
            class="absolute top-full mt-1 right-0 bg-white border border-gray-200 rounded-lg shadow-lg z-50 py-1 min-w-[180px]"
        >
            <li
                v-for="option in options"
                :key="option.value"
                class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 cursor-pointer transition-colors"
                :class="{
                    'bg-indigo-50 text-indigo-700 font-semibold': option.value === modelValue,
                }"
                @click="select(option.value)"
            >
                <font-awesome-icon :icon="option.icon" class="w-3.5 h-3.5" />
                <span>{{ t(option.labelKey) }}</span>
            </li>
        </ul>
    </div>
</template>

<script setup>
    import { onMounted, onUnmounted, ref } from 'vue'
    import { useI18n } from 'vue-i18n'

    defineProps({
        modelValue: {
            type: String,
            required: true,
        },
        options: {
            type: Array,
            required: true,
        },
    })

    const emit = defineEmits(['update:modelValue'])
    const { t } = useI18n()
    const isOpen = ref(false)

    const select = (value) => {
        isOpen.value = false
        emit('update:modelValue', value)
    }

    const close = (event) => {
        if (!event.target.closest('.formula-sort-wrapper')) {
            isOpen.value = false
        }
    }

    onMounted(() => document.addEventListener('click', close))
    onUnmounted(() => document.removeEventListener('click', close))
</script>
