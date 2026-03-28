<template>
    <Modal
        :model-value="modelValue"
        :title="modalTitle"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div class="space-y-4">
            <div class="border-t border-gray-100 pt-4">
                <div
                    class="hidden sm:grid grid-cols-12 gap-4 font-bold text-gray-700 border-b pb-2"
                >
                    <div class="col-span-7 text-sm">Organization</div>
                    <div class="col-span-4 text-sm text-center">
                        Percentage (%)
                    </div>
                    <div class="col-span-1" />
                </div>

                <div class="max-h-[40vh] overflow-y-auto space-y-3 pr-2 mt-2">
                    <div
                        v-for="(row, index) in rows"
                        :key="index"
                        class="grid grid-cols-1 sm:grid-cols-12 gap-3 sm:gap-4 items-start sm:items-center"
                    >
                        <div class="col-span-12 sm:col-span-7">
                            <label
                                class="block sm:hidden text-xs font-medium text-gray-600 mb-1"
                            >
                                Organization
                            </label>
                            <SearchableSelect
                                v-model="row.organization"
                                :options="organizations"
                                placeholder="Search or type organization..."
                            />
                        </div>
                        <div class="col-span-10 sm:col-span-4">
                            <label
                                class="block sm:hidden text-xs font-medium text-gray-600 mb-1"
                            >
                                Percentage (%)
                            </label>
                            <FormInput
                                v-model.number="row.percentage"
                                type="number"
                                placeholder="0"
                                min="0"
                                max="100"
                            />
                        </div>
                        <div
                            class="col-span-2 sm:col-span-1 flex justify-center sm:block"
                        >
                            <button
                                type="button"
                                class="text-red-500 hover:text-red-700 transition-all duration-200 p-2"
                                title="Delete Row"
                                @click="deleteRow(index)"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'trash-alt']"
                                />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-start">
                    <button
                        type="button"
                        class="flex items-center gap-2 text-indigo-600 hover:text-indigo-800 font-semibold transition-colors py-2 text-sm"
                        @click="addRow"
                    >
                        <font-awesome-icon :icon="['fas', 'plus-circle']" />
                        <span>Add Charity</span>
                    </button>
                </div>
            </div>

            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                <div
                    class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2"
                >
                    <span
                        :class="[
                            'font-bold text-base sm:text-lg',
                            totalPercentage === 100
                                ? 'text-green-600'
                                : 'text-red-600',
                        ]"
                    >
                        Total Allocation: {{ totalPercentage }}%
                    </span>
                    <div class="flex items-center gap-2">
                        <font-awesome-icon
                            v-if="totalPercentage === 100"
                            :icon="['fas', 'check-circle']"
                            class="text-green-600"
                        />
                        <font-awesome-icon
                            v-else
                            :icon="['fas', 'exclamation-triangle']"
                            class="text-red-500"
                        />
                        <span
                            v-if="totalPercentage !== 100"
                            class="text-xs text-red-500"
                        >
                            Must equal 100%
                        </span>
                    </div>
                </div>
                <p class="text-xs text-gray-600 mt-2 italic">
                    * Select from list or type custom. Total must be 100%.
                </p>
            </div>
        </div>

        <template #footer>
            <Button
                variant="secondary"
                text="Cancel"
                width="auto"
                class="px-6"
                @click="$emit('update:modelValue', false)"
            />
            <Button
                variant="primary"
                :text="
                    localIsSaving || props.isSaving
                        ? 'Saving...'
                        : props.isEdit
                          ? 'Update'
                          : 'Save'
                "
                width="auto"
                class="px-6"
                :disabled="!isValid || localIsSaving || props.isSaving"
                @click="handleSave"
            />
        </template>
    </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useOrganizations } from '~/composables/useOrganizations'

const { organizations } = useOrganizations()

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    initialData: {
        type: Object,
        default: () => ({ formula: [] }),
    },
    isSaving: {
        type: Boolean,
        default: false,
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue', 'save'])
const rows = ref([])
const localIsSaving = ref(false)

const modalTitle = computed(() => {
    return props.isEdit ? 'Edit Donation Formula' : 'Create Donation Formula'
})

// Reset or initialize rows when modal becomes visible
watch(
    () => props.modelValue,
    (val) => {
        if (val) {
            // Always reset when opening
            if (
                props.initialData &&
                props.initialData.formula &&
                props.initialData.formula.length > 0
            ) {
                // Clone initial data to avoid direct mutation
                rows.value = JSON.parse(
                    JSON.stringify(props.initialData.formula)
                )
            } else {
                // Reset to default empty state
                rows.value = [{ organization: '', percentage: 0 }]
            }
        }
    },
    { immediate: true }
)

// Computed property to calculate the current sum of percentages
const totalPercentage = computed(() => {
    return rows.value.reduce((sum, row) => {
        const val = parseFloat(row.percentage)
        return sum + (isNaN(val) ? 0 : val)
    }, 0)
})

/**
 * Checks if the entire form is valid:
 * 1. Total percentage is exactly 100
 * 2. Every row has a non-empty organization name
 * 3. Every row has a percentage greater than 0
 */
const isValid = computed(() => {
    if (totalPercentage.value !== 100) return false
    if (rows.value.length === 0) return false

    return rows.value.every((row) => {
        const orgValid = row.organization && row.organization.trim() !== ''
        const percValid = row.percentage && parseFloat(row.percentage) > 0
        return orgValid && percValid
    })
})

/**
 * Adds a new empty row to the charity list
 */
const addRow = () => {
    rows.value.push({ organization: '', percentage: 0 })
}

/**
 * Removes a charity row at the specified index
 */
const deleteRow = (index) => {
    rows.value.splice(index, 1)
}

/**
 * Validates and emits the saved donation formula data
 */
const handleSave = async () => {
    // Double check validity before proceeding
    if (!isValid.value || props.isSaving || localIsSaving.value) return

    localIsSaving.value = true
    try {
        // Sanitize data: trim strings and ensure percentages are numeric
        const sanitizedRows = rows.value.map((row) => ({
            organization: row.organization.trim(),
            percentage: parseFloat(row.percentage),
        }))

        // Emit the save event with data
        emit('save', {
            formula: sanitizedRows,
        })
    } finally {
        localIsSaving.value = false
    }
}
</script>
