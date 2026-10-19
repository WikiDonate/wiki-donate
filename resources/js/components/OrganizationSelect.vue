<template>
    <div ref="rootRef" class="relative">
        <!-- Writable combobox input: type to filter, pick from the dropdown -->
        <input
            ref="textInputRef"
            v-model="text"
            type="text"
            :placeholder="placeholder"
            autocomplete="off"
            class="w-full px-3 py-2 pr-16 border border-gray-300 rounded-lg bg-white text-gray-900 placeholder-gray-400 focus:outline-none transition-colors"
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
                            v-for="(suggestion, suggestionIndex) in suggestions"
                            :key="suggestion.ein"
                            class="px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 cursor-pointer transition-colors"
                            :class="{
                                'bg-indigo-50 text-indigo-700':
                                    suggestionIndex === highlightedIndex,
                            }"
                            @mousedown.prevent="selectSuggestion(suggestion)"
                            @mousemove="highlightedIndex = suggestionIndex"
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
                            <a
                                v-if="suggestion.website"
                                :href="suggestion.website"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="block text-xs text-indigo-600 hover:text-indigo-800 hover:underline truncate"
                                @mousedown.stop
                            >
                                {{ suggestion.website }}
                            </a>
                        </li>
                    </template>
                </ul>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
    import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { adminService } from '~/services/adminService'
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
        clearable: {
            type: Boolean,
            default: false,
        },
        /**
         * Data source: `directory` (verified charities API — formula modal)
         * or `local` (admin organization registry — dashboard filters,
         * includes legacy free-text rows).
         */
        source: {
            type: String,
            default: 'directory',
        },
    })

    const emit = defineEmits(['select', 'clear', 'update:modelValue'])

    const rootRef = ref(null)
    const dropdownRef = ref(null)
    const textInputRef = ref(null)
    const isOpen = ref(false)
    const suggestions = ref([])
    const searching = ref(false)
    const highlightedIndex = ref(-1)
    // Last selected suggestion name — text diverging from it clears the EIN.
    const selectedName = ref(props.modelValue || '')
    const dropdownStyle = ref({})
    const debounceTimer = ref(null)
    const abortController = ref(null)

    const text = computed({
        get: () => props.modelValue ?? '',
        set: (value) => emit('update:modelValue', value),
    })

    const searchingText = t('formula.searchingCharities')
    const noResultsText = t('formula.noCharityResults')

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
        // Text no longer matches a verified selection — drop its EIN so a
        // stale verified identity can never stick to edited text.
        if (text.value !== selectedName.value) {
            emit('select', { name: text.value, ein: null, website: null })
        }
        openDropdown()
        clearTimeout(debounceTimer.value)
        debounceTimer.value = setTimeout(() => fetchSuggestions(text.value.trim()), 300)
    }

    const fetchSuggestions = async (query) => {
        abortController.value?.abort()
        abortController.value = new AbortController()
        searching.value = true
        highlightedIndex.value = -1

        try {
            const rows =
                props.source === 'local'
                    ? await fetchLocalOrganizations(query, abortController.value.signal)
                    : await fetchDirectoryCharities(query, abortController.value.signal)
            if (isOpen.value) {
                suggestions.value = rows.slice(0, 10)
            }
        } catch {
            if (isOpen.value) suggestions.value = []
        } finally {
            searching.value = false
        }
    }

    const fetchDirectoryCharities = async (query, signal) => {
        const response = await charityService.searchCharities(query, signal)

        return response.data ?? []
    }

    const fetchLocalOrganizations = async (query, signal) => {
        const response = await adminService.getOrganizations(
            { search: query, per_page: 10 },
            signal,
        )
        const rows = response.data?.data ?? response.data ?? []

        return rows.map((org) => ({
            name: org.name,
            ein: org.ein ?? null,
            city: org.city ?? null,
            state: org.state ?? null,
            website: org.website ?? null,
            rating: null,
            // Registry identity + live verification state, so a picked row
            // can carry organization_id and render the correct badge.
            organization_id: org.id ?? null,
            payout_status: org.payout_status ?? 'unverified',
            is_verified: org.payout_status === 'verified',
        }))
    }

    const selectSuggestion = (suggestion) => {
        if (!suggestion) return
        selectedName.value = suggestion.name
        emit('update:modelValue', suggestion.name)
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
        selectedName.value = ''
        emit('update:modelValue', '')
        emit('select', { name: '', ein: null, website: null })
        emit('clear')
        dismiss()
        textInputRef.value?.focus()
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
