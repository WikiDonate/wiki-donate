<template>
    <Modal
        :model-value="modelValue"
        title="Donation"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div class="space-y-4">
            <!-- Header for the list -->
            <div
                class="grid grid-cols-12 gap-4 font-bold text-gray-700 border-b pb-2"
            >
                <div class="col-span-7">Organization</div>
                <div class="col-span-4">Percentage (%)</div>
                <div class="col-span-1" />
            </div>

            <!-- Rows of charities -->
            <div class="max-h-[60vh] overflow-y-auto space-y-3 pr-2">
                <div
                    v-for="(row, index) in rows"
                    :key="index"
                    class="grid grid-cols-12 gap-4 items-center"
                >
                    <div class="col-span-7">
                        <FormInput
                            v-model="row.organization"
                            placeholder="e.g. Red Cross"
                        />
                    </div>
                    <div class="col-span-4">
                        <FormInput
                            v-model.number="row.percentage"
                            type="number"
                            placeholder="0"
                            min="0"
                            max="100"
                        />
                    </div>
                    <div class="col-span-1 text-center">
                        <button
                            type="button"
                            class="text-red-500 hover:text-red-700 transition-all duration-200 p-2"
                            title="Delete Row"
                            @click="deleteRow(index)"
                        >
                            <font-awesome-icon :icon="['fas', 'trash-alt']" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Add Button Row -->
            <div class="flex justify-start">
                <button
                    type="button"
                    class="flex items-center gap-2 text-indigo-600 hover:text-indigo-800 font-semibold transition-colors py-2"
                    @click="addRow"
                >
                    <font-awesome-icon :icon="['fas', 'plus-circle']" />
                    <span>Add Charity</span>
                </button>
            </div>

            <!-- Total display and Footer Note -->
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                <div
                    class="flex justify-between items-center font-bold text-lg"
                >
                    <span
                        :class="
                            totalPercentage === 100
                                ? 'text-green-600'
                                : 'text-red-600'
                        "
                    >
                        Total Allocation: {{ totalPercentage }}%
                    </span>
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
                </div>
                <p class="text-xs text-gray-600 mt-2 italic">
                    * Total must be 100%. All organizations and percentages ( >
                    0) are required.
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
                text="Save"
                width="auto"
                class="px-6"
                :disabled="!isValid || isSaving"
                @click="handleSave"
            />
        </template>
    </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    initialData: {
        type: Array,
        default: () => [],
    },
})

const emit = defineEmits(['update:modelValue', 'save'])
const rows = ref([])
const isSaving = ref(false)

// Reset or initialize rows when modal becomes visible
watch(
    () => props.modelValue,
    (val) => {
        if (val) {
            // Always reset when opening
            if (props.initialData && props.initialData.length > 0) {
                // Clone initial data to avoid direct mutation
                rows.value = JSON.parse(JSON.stringify(props.initialData))
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
    if (!isValid.value) return

    isSaving.value = true
    try {
        // Sanitize data: trim strings and ensure percentages are numeric
        const sanitizedRows = rows.value.map((row) => ({
            organization: row.organization.trim(),
            percentage: parseFloat(row.percentage),
        }))

        // Emit the save event with data and close modal
        emit('save', sanitizedRows)
        emit('update:modelValue', false)
    } finally {
        isSaving.value = false
    }
}
</script>
