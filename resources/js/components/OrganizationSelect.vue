<template>
    <div ref="rootRef" class="relative">
        <!-- Selected organization display / trigger -->
        <button
            type="button"
            class="w-full text-left px-3 py-2 border border-gray-300 rounded-lg bg-white focus:outline-none transition-colors"
            @click="toggleDropdown"
        >
            <span v-if="modelValue" class="block font-medium text-gray-900">
                {{ modelValue }}
            </span>
            <span v-else class="block text-gray-400">
                {{ placeholder }}
            </span>
            <font-awesome-icon
                :icon="['fas', 'chevron-down']"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 transition-transform"
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
                <!-- Search input -->
                <div class="p-2 border-b border-gray-100">
                    <input
                        v-model="searchQuery"
                        type="text"
                        :placeholder="placeholder"
                        autocomplete="off"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none"
                        @input="onSearchInput"
                    />
                </div>
                <ul class="max-h-[400px] overflow-y-auto py-1">
                    <li v-if="searching" class="px-3 py-2 text-sm text-gray-500 italic">
                        {{ searchingText }}
                    </li>
                    <li
                        v-else-if="suggestions.length === 0"
                        class="px-3 py-2 text-sm text-gray-500 italic"
                    >
                        {{ noResultsText }}
                    </li>
                    <template v-else>
                        <li
                            v-for="suggestion in suggestions"
                            :key="suggestion.ein"
                            class="px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 cursor-pointer transition-colors"
                            @mousedown.prevent="selectSuggestion(suggestion)"
                        >
                            <span class="flex items-center justify-between gap-2">
                                <span class="block font-medium">{{ suggestion.name }}</span>
                                <span
                                    v-if="suggestion.rating !== null"
                                    class="shrink-0 text-xs font-semibold text-amber-500"
                                    :title="`Rating: ${suggestion.rating}`"
                                >
                                    ★ {{ suggestion.rating }}
                                </span>
                            </span>
                            <span
                                v-if="suggestion.city || suggestion.state"
                                class="block text-xs text-gray-500"
                            >
                                {{ [suggestion.city, suggestion.state].filter(Boolean).join(', ') }}
                            </span>
                        </li>
                    </template>
                </ul>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
    import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { charityService } from '~/services/charityService'

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

    const emit = defineEmits(['select', 'update:modelValue'])

    const rootRef = ref(null)
    const dropdownRef = ref(null)
    const isOpen = ref(false)
    const suggestions = ref([])
    const searching = ref(false)
    const searchQuery = ref('')
    const dropdownStyle = ref({})
    const debounceTimer = ref(null)
    const abortController = ref(null)

    const searchingText = t('formula.searchingCharities')
    const noResultsText = t('formula.noCharityResults')

    const positionDropdown = () => {
        const btnEl = rootRef.value?.querySelector('button')
        if (!btnEl) return
        const rect = btnEl.getBoundingClientRect()
        dropdownStyle.value = {
            top: `${rect.bottom + 4}px`,
            left: `${rect.left}px`,
            width: `${rect.width}px`,
        }
    }

    const dismiss = () => {
        isOpen.value = false
        suggestions.value = []
        searchQuery.value = ''
    }

    const toggleDropdown = () => {
        if (isOpen.value) {
            dismiss()
            return
        }
        isOpen.value = true
        searchQuery.value = ''
        suggestions.value = []
        nextTick(() => {
            positionDropdown()
            fetchSuggestions('')
        })
    }

    const onSearchInput = () => {
        clearTimeout(debounceTimer.value)
        debounceTimer.value = setTimeout(() => fetchSuggestions(searchQuery.value), 300)
    }

    const fetchSuggestions = async (query) => {
        abortController.value?.abort()
        abortController.value = new AbortController()
        searching.value = true

        try {
            const response = await charityService.searchCharities(
                query,
                abortController.value.signal,
            )
            if (isOpen.value) {
                suggestions.value = response.data ?? []
            }
        } catch {
            if (isOpen.value) suggestions.value = []
        } finally {
            searching.value = false
        }
    }

    const selectSuggestion = (suggestion) => {
        emit('update:modelValue', suggestion.name)
        emit('select', suggestion)
        dismiss()
    }

    const handleViewportChange = () => {
        if (isOpen.value) dismiss()
    }

    // Outside click: the dropdown lives in body via Teleport, so both the
    // trigger root and the dropdown panel must contain the click to stay open.
    // Mousedown (not click) so a suggestion mousedown-select still wins.
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
