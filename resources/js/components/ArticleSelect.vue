<template>
    <div ref="rootRef" class="relative">
        <!-- Writable combobox input: type to filter local articles, pick one -->
        <input
            ref="textInputRef"
            v-model="text"
            type="text"
            :placeholder="placeholder"
            autocomplete="off"
            class="w-full px-3 py-2 pr-16 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 placeholder-gray-400 focus:outline-none transition-colors"
            @input="onTextInput"
            @focus="openDropdown"
            @keydown.escape="dismiss"
            @keydown.down.prevent="highlightNext"
            @keydown.up.prevent="highlightPrev"
            @keydown.enter.prevent="selectHighlighted"
        />
        <button
            v-if="clearable && text"
            type="button"
            class="absolute right-8 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
            :title="t('common.clear')"
            @click.stop="clearSelection"
        >
            <font-awesome-icon :icon="['fas', 'times']" class="w-3.5 h-3.5" />
        </button>
        <button
            type="button"
            tabindex="-1"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
            @click="toggleDropdown"
        >
            <font-awesome-icon
                :icon="['fas', 'chevron-down']"
                class="w-3.5 h-3.5 transition-transform"
                :class="{ 'rotate-180': isOpen }"
            />
        </button>

        <!-- Dropdown -->
        <Teleport to="body">
            <div
                v-if="isOpen"
                ref="dropdownRef"
                :style="dropdownStyle"
                class="fixed bg-white border border-gray-200 rounded-lg shadow-xl z-[100] w-full"
            >
                <ul class="max-h-[400px] overflow-y-auto py-1">
                    <li v-if="searching" class="px-3 py-2 text-sm text-gray-500 italic">
                        {{ t('common.searching') }}
                    </li>
                    <li
                        v-else-if="suggestions.length === 0"
                        class="px-3 py-2 text-sm text-gray-500 italic"
                    >
                        {{ t('common.noResults') }}
                    </li>
                    <template v-else>
                        <li
                            v-for="(suggestion, suggestionIndex) in suggestions"
                            :key="suggestion.slug"
                            class="px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 cursor-pointer transition-colors"
                            :class="{
                                'bg-indigo-50 text-indigo-700':
                                    suggestionIndex === highlightedIndex,
                            }"
                            @mousedown.prevent="selectSuggestion(suggestion)"
                            @mousemove="highlightedIndex = suggestionIndex"
                        >
                            <span class="block font-medium">{{ suggestion.title }}</span>
                            <span class="block text-xs text-gray-500">{{ suggestion.slug }}</span>
                        </li>
                    </template>
                </ul>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
    import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { articleService } from '~/services/articleService'

    const { t } = useI18n()

    const props = defineProps({
        modelValue: {
            type: String,
            default: '',
        },
        displayTitle: {
            type: String,
            default: '',
        },
        placeholder: {
            type: String,
            default: '',
        },
        clearable: {
            type: Boolean,
            default: false,
        },
    })

    const emit = defineEmits(['select', 'clear', 'update:modelValue', 'update:displayTitle'])

    const rootRef = ref(null)
    const dropdownRef = ref(null)
    const textInputRef = ref(null)
    const isOpen = ref(false)
    const suggestions = ref([])
    const searching = ref(false)
    const highlightedIndex = ref(-1)
    // Display text: the selected title, or free-typed filter text.
    const text = ref(props.displayTitle || props.modelValue || '')
    const dropdownStyle = ref({})
    const debounceTimer = ref(null)
    const abortController = ref(null)

    watch(
        () => [props.displayTitle, props.modelValue],
        ([title, slug]) => {
            if (document.activeElement !== textInputRef.value) {
                text.value = title || slug || ''
            }
        },
    )

    const positionDropdown = () => {
        const inputEl = textInputRef.value
        if (!inputEl) return
        const rect = inputEl.getBoundingClientRect()
        dropdownStyle.value = {
            top: `${rect.bottom + 4}px`,
            left: `${rect.left}px`,
            width: `${rect.width}px`,
        }
    }

    const dismiss = () => {
        isOpen.value = false
        suggestions.value = []
        highlightedIndex.value = -1
    }

    const openDropdown = () => {
        if (isOpen.value) {
            positionDropdown()
            return
        }
        isOpen.value = true
        suggestions.value = []
        highlightedIndex.value = -1
        nextTick(() => {
            positionDropdown()
            fetchSuggestions(text.value.trim())
        })
    }

    const toggleDropdown = () => {
        if (isOpen.value) {
            dismiss()
            return
        }
        textInputRef.value?.focus()
        openDropdown()
    }

    const onTextInput = () => {
        // Free text filters by slug substring; it clears any prior selection.
        emit('update:modelValue', text.value)
        emit('update:displayTitle', text.value)
        openDropdown()
        clearTimeout(debounceTimer.value)
        debounceTimer.value = setTimeout(() => fetchSuggestions(text.value.trim()), 300)
    }

    const fetchSuggestions = async (query) => {
        abortController.value?.abort()
        abortController.value = new AbortController()
        searching.value = true
        highlightedIndex.value = -1

        // Backend requires a non-empty query — don't fire on empty open.
        if (!query || query.length < 2) {
            suggestions.value = []
            searching.value = false
            return
        }

        try {
            const response = await articleService.searchArticles(query)
            if (isOpen.value) {
                suggestions.value = (response.data ?? []).slice(0, 10)
            }
        } catch {
            if (isOpen.value) suggestions.value = []
        } finally {
            searching.value = false
        }
    }

    const selectSuggestion = (suggestion) => {
        if (!suggestion) return
        text.value = suggestion.title
        emit('update:modelValue', suggestion.slug)
        emit('update:displayTitle', suggestion.title)
        emit('select', suggestion)
        dismiss()
        textInputRef.value?.blur()
    }

    const highlightNext = () => {
        if (!isOpen.value) {
            openDropdown()
            return
        }
        if (suggestions.value.length === 0) return
        highlightedIndex.value = (highlightedIndex.value + 1) % suggestions.value.length
    }

    const highlightPrev = () => {
        if (!isOpen.value) {
            openDropdown()
            return
        }
        if (suggestions.value.length === 0) return
        highlightedIndex.value =
            (highlightedIndex.value - 1 + suggestions.value.length) % suggestions.value.length
    }

    const selectHighlighted = () => {
        if (highlightedIndex.value >= 0 && suggestions.value[highlightedIndex.value]) {
            selectSuggestion(suggestions.value[highlightedIndex.value])
        }
    }

    const clearSelection = () => {
        text.value = ''
        emit('update:modelValue', '')
        emit('update:displayTitle', '')
        emit('clear')
        dismiss()
        textInputRef.value?.focus()
    }

    const handleViewportChange = () => {
        if (isOpen.value) dismiss()
    }

    const handleOutsideMousedown = (event) => {
        if (!isOpen.value) return
        const target = event.target
        const insideTrigger = rootRef.value?.contains(target)
        const insideDropdown = dropdownRef.value?.contains(target)
        if (!insideTrigger && !insideDropdown) dismiss()
    }

    onMounted(() => {
        window.addEventListener('scroll', handleViewportChange, true)
        window.addEventListener('resize', handleViewportChange)
        document.addEventListener('mousedown', handleOutsideMousedown)
    })

    onBeforeUnmount(() => {
        clearTimeout(debounceTimer.value)
        abortController.value?.abort()
        window.removeEventListener('scroll', handleViewportChange, true)
        window.removeEventListener('resize', handleViewportChange)
        document.removeEventListener('mousedown', handleOutsideMousedown)
    })
</script>
