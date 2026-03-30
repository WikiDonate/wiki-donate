<!-- eslint-disable vue/html-self-closing -->
<template>
    <div ref="containerRef" class="relative" @click="focusInput">
        <div class="relative">
            <input
                ref="inputRef"
                :value="displayValue"
                type="text"
                :placeholder="placeholder"
                :disabled="disabled"
                class="border border-gray-300 rounded py-2 px-3 w-full focus:outline-none bg-white"
                @input="handleInput"
                @focus="handleFocus"
                @blur="handleBlur"
                @keydown="handleKeydown"
            />
            <div
                class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none"
            >
                <font-awesome-icon
                    :icon="['fas', 'chevron-down']"
                    class="w-4 h-4 text-gray-400 transition-transform duration-200"
                    :class="{ 'rotate-180': isOpen }"
                />
            </div>
        </div>

        <teleport to="body">
            <transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition duration-100 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div
                    v-if="isOpen && !disabled"
                    ref="dropdownRef"
                    :style="dropdownStyle"
                    class="fixed w-full max-h-60 bg-white border border-gray-200 rounded-lg shadow-xl overflow-y-auto"
                    :class="dropdownZIndex"
                >
                    <div
                        v-if="filteredOptions.length === 0"
                        class="px-4 py-3 text-sm text-gray-500"
                    >
                        No organizations found
                    </div>
                    <ul class="py-1">
                        <li
                            v-for="(option, index) in filteredOptions"
                            :key="option.value"
                            :ref="(el) => setOptionRef(el, index)"
                            class="px-4 py-2 text-sm cursor-pointer transition-colors"
                            :class="[
                                highlightedIndex === index
                                    ? 'bg-indigo-50 text-indigo-700'
                                    : 'text-gray-700 hover:bg-gray-50',
                                option.isCustom ? 'italic' : '',
                            ]"
                            @mousedown.prevent="selectOption(option)"
                            @mouseenter="highlightedIndex = index"
                        >
                            <div class="flex items-center justify-between">
                                <span>{{ option.label }}</span>
                                <span
                                    v-if="option.isCustom"
                                    class="text-xs text-gray-400"
                                >
                                    (Custom)
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>
            </transition>
        </teleport>
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    options: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: 'Search or select...',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    allowCustom: {
        type: Boolean,
        default: true,
    },
})

const emit = defineEmits(['update:modelValue'])

const isOpen = ref(false)
const searchQuery = ref('')
const highlightedIndex = ref(-1)
const containerRef = ref(null)
const inputRef = ref(null)
const dropdownRef = ref(null)
const optionRefs = ref([])
const dropdownStyle = ref({})
const dropdownZIndex = ref('z-[9999]')

const focusInput = () => {
    if (inputRef.value && !isOpen.value) {
        inputRef.value.focus()
    }
}

const updateDropdownPosition = () => {
    if (!containerRef.value) return

    const rect = containerRef.value.getBoundingClientRect()

    const top = rect.bottom + 4
    const left = rect.left
    const width = rect.width

    dropdownStyle.value = {
        top: `${top}px`,
        left: `${left}px`,
        width: `${width}px`,
    }
}

watch(isOpen, async (open) => {
    if (open) {
        await nextTick()
        updateDropdownPosition()
    }
})

const displayValue = computed(() => {
    if (!searchQuery.value && props.modelValue) {
        const found = props.options.find((o) => o.value === props.modelValue)
        return found ? found.label : props.modelValue
    }
    return searchQuery.value
})

const filteredOptions = computed(() => {
    const query = searchQuery.value.toLowerCase().trim()
    const filtered = props.options.filter((option) =>
        option.label.toLowerCase().includes(query)
    )

    if (
        props.allowCustom &&
        query &&
        !props.options.some((o) => o.label.toLowerCase() === query)
    ) {
        return [...filtered, { label: query, value: query, isCustom: true }]
    }

    return filtered
})

const handleInput = (event) => {
    searchQuery.value = event.target.value
    highlightedIndex.value = -1
    isOpen.value = true
    emit('update:modelValue', event.target.value)
}

const handleFocus = () => {
    if (!props.disabled) {
        isOpen.value = true
        highlightedIndex.value = -1
    }
}

const handleBlur = () => {
    setTimeout(() => {
        isOpen.value = false
        highlightedIndex.value = -1
    }, 150)
}

const handleKeydown = (event) => {
    if (!isOpen.value) {
        if (event.key === 'ArrowDown' || event.key === 'Enter') {
            isOpen.value = true
        }
        return
    }

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault()
            highlightedIndex.value = Math.min(
                highlightedIndex.value + 1,
                filteredOptions.value.length - 1
            )
            scrollToHighlighted()
            break
        case 'ArrowUp':
            event.preventDefault()
            highlightedIndex.value = Math.max(highlightedIndex.value - 1, -1)
            scrollToHighlighted()
            break
        case 'Enter':
            event.preventDefault()
            if (
                highlightedIndex.value >= 0 &&
                filteredOptions.value[highlightedIndex.value]
            ) {
                selectOption(filteredOptions.value[highlightedIndex.value])
            } else if (searchQuery.value && props.allowCustom) {
                selectOption({
                    label: searchQuery.value,
                    value: searchQuery.value,
                    isCustom: true,
                })
            }
            break
        case 'Escape':
            isOpen.value = false
            highlightedIndex.value = -1
            break
        case 'Tab':
            isOpen.value = false
            break
    }
}

const selectOption = (option) => {
    searchQuery.value = option.label
    emit('update:modelValue', option.value)
    isOpen.value = false
    highlightedIndex.value = -1
}

const setOptionRef = (el, index) => {
    if (el) {
        optionRefs.value[index] = el
    }
}

const scrollToHighlighted = () => {
    if (
        highlightedIndex.value >= 0 &&
        optionRefs.value[highlightedIndex.value]
    ) {
        optionRefs.value[highlightedIndex.value].scrollIntoView({
            block: 'nearest',
            behavior: 'smooth',
        })
    }
}

watch(
    () => props.modelValue,
    (newVal) => {
        if (newVal !== searchQuery.value) {
            const found = props.options.find((o) => o.value === newVal)
            searchQuery.value = found ? found.label : newVal || ''
        }
    }
)

const handleClickOutside = (event) => {
    if (containerRef.value && !containerRef.value.contains(event.target)) {
        isOpen.value = false
    }
}

const handleScroll = () => {
    if (isOpen.value) {
        updateDropdownPosition()
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside)
    window.addEventListener('resize', handleScroll)
    window.addEventListener('scroll', handleScroll, true)
})

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside)
    window.removeEventListener('resize', handleScroll)
    window.removeEventListener('scroll', handleScroll, true)
})
</script>
