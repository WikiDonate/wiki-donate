<template>
    <Modal
        :model-value="modelValue"
        :title="modalTitle"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"> Name </label>
                <FormInput
                    v-model="name"
                    v-bind="nameProps"
                    placeholder="Enter a name for this formula"
                    :error-message="errors.name"
                />
            </div>

            <div class="border-t border-gray-100 pt-4">
                <div
                    class="hidden sm:grid grid-cols-12 gap-4 font-bold text-gray-700 border-b pb-2"
                >
                    <div class="col-span-7 text-sm">Organization</div>
                    <div class="col-span-4 text-sm text-center">Percentage (%)</div>
                    <div class="col-span-1" />
                </div>

                <div class="max-h-[40vh] overflow-y-auto space-y-3 pr-2 mt-2">
                    <div
                        v-for="(field, index) in fields"
                        :key="field.key"
                        class="grid grid-cols-1 sm:grid-cols-12 gap-3 sm:gap-4 items-start sm:items-center"
                    >
                        <div class="col-span-12 sm:col-span-7">
                            <label class="block sm:hidden text-xs font-medium text-gray-600 mb-1">
                                Organization
                            </label>
                            <FormInput
                                v-model="field.value.organization"
                                placeholder="Organization name"
                            />
                        </div>
                        <div class="col-span-10 sm:col-span-4">
                            <label class="block sm:hidden text-xs font-medium text-gray-600 mb-1">
                                Percentage (%)
                            </label>
                            <FormInput
                                v-model.number="field.value.percentage"
                                type="number"
                                placeholder="0"
                                min="0"
                                max="100"
                            />
                        </div>
                        <div class="col-span-2 sm:col-span-1 flex justify-center sm:block">
                            <button
                                type="button"
                                class="text-red-500 hover:text-red-700 transition-all duration-200 p-2"
                                title="Delete Row"
                                @click="remove(index)"
                            >
                                <font-awesome-icon :icon="['fas', 'trash-alt']" />
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
                            totalPercentage === 100 ? 'text-green-600' : 'text-red-600',
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
                        <span v-if="totalPercentage !== 100" class="text-xs text-red-500">
                            Must equal 100%
                        </span>
                    </div>
                </div>
                <p v-if="errors.formula" class="text-xs text-red-500 mt-1">
                    {{ errors.formula }}
                </p>
                <p class="text-xs text-gray-600 mt-2 italic">
                    * Enter organization names. Total must be 100%.
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"> Details </label>
                <FormTextarea
                    v-model="details"
                    v-bind="detailsProps"
                    rows="3"
                    placeholder="Add any additional details..."
                    :error-message="errors.details"
                />
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
                    localIsSaving || props.isSaving ? 'Saving...' : props.isEdit ? 'Update' : 'Save'
                "
                width="auto"
                class="px-6"
                :disabled="!canSubmit || localIsSaving || props.isSaving"
                @click="handleSave"
            />
        </template>
    </Modal>

    <ConfirmModal
        v-model="showSaveConfirm"
        :title="props.isEdit ? 'Update Donation Formula' : 'Save Donation Formula'"
        message-title="Confirm Your Action"
        :message="
            props.isEdit
                ? 'Are you sure you want to update this donation formula?'
                : 'Are you sure you want to save this donation formula?'
        "
        :confirm-text="props.isEdit ? 'Update' : 'Save'"
        :is-loading="localIsSaving || props.isSaving"
        @confirm="confirmSave"
    />
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useFieldArray, useForm } from 'vee-validate'
import * as yup from 'yup'
import FormTextarea from '~/components/FormTextarea.vue'
import ConfirmModal from '~/components/ConfirmModal.vue'

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
    errorMessage: {
        type: String,
        default: '',
    },
})

const emit = defineEmits(['update:modelValue', 'save'])

const validationSchema = yup.object({
    name: yup
        .string()
        .required('Name is required')
        .max(255, 'Name must be 255 characters or fewer.'),
    details: yup.string().nullable(),
    formula: yup
        .array()
        .min(1, 'Add at least one charity')
        .of(
            yup.object({
                organization: yup.string().required('Organization is required'),
                percentage: yup
                    .number()
                    .typeError('Percentage must be a number')
                    .required('Percentage is required')
                    .min(0.01, 'Percentage must be greater than 0')
                    .max(100, 'Percentage must be at most 100'),
            })
        )
        .test('total-100', 'Total allocation must equal 100%', (arr) => {
            const total = (arr ?? []).reduce((sum, row) => sum + Number(row.percentage || 0), 0)
            return Math.abs(total - 100) < 0.01
        }),
})

const { handleSubmit, defineField, setFieldError, errors, resetForm } = useForm({
    validationSchema,
    initialValues: {
        name: '',
        details: '',
        formula: [],
    },
})

const [name, nameProps] = defineField('name')
const [details, detailsProps] = defineField('details')

const { fields, push, remove } = useFieldArray('formula')

const localIsSaving = ref(false)
const showSaveConfirm = ref(false)
const pendingSaveData = ref(null)

const modalTitle = computed(() => {
    return props.isEdit ? 'Edit Donation Formula' : 'Create Donation Formula'
})

// Reset or initialize fields when modal becomes visible
watch(
    () => props.modelValue,
    (val) => {
        if (val) {
            // Reset saving states when modal opens
            localIsSaving.value = false
            showSaveConfirm.value = false
            pendingSaveData.value = null

            const formula =
                props.initialData?.formula && props.initialData.formula.length > 0
                    ? JSON.parse(JSON.stringify(props.initialData.formula))
                    : [{ organization: '', percentage: 0 }]

            resetForm({
                values: {
                    name: props.initialData?.name || '',
                    details: props.initialData?.details || '',
                    formula,
                },
            })
        }
    },
    { immediate: true }
)

// Reset local saving state when parent isSaving becomes false
watch(
    () => props.isSaving,
    (val) => {
        if (val === false) {
            localIsSaving.value = false
            showSaveConfirm.value = false
            pendingSaveData.value = null
        }
    }
)

// Surface server-side validation errors (e.g. duplicate name) inline
watch(
    () => props.errorMessage,
    (val) => {
        if (val) {
            setFieldError('name', val)
        }
    }
)

// Computed property to calculate the current sum of percentages
const totalPercentage = computed(() => {
    return fields.value.reduce((sum, field) => {
        const val = parseFloat(field.value.percentage)
        return sum + (isNaN(val) ? 0 : val)
    }, 0)
})

// Whether the form passes all validation rules (schema-driven, not manual)
const canSubmit = computed(() => {
    try {
        validationSchema.validateSync({
            name: name.value,
            details: details.value,
            formula: fields.value.map((field) => field.value),
        })
        return true
    } catch {
        return false
    }
})

/**
 * Adds a new empty row to the charity list
 */
const addRow = () => {
    push({ organization: '', percentage: 0 })
}

const handleSave = handleSubmit((values) => {
    if (props.isSaving || localIsSaving.value) return

    const sanitizedRows = values.formula.map((row) => ({
        organization: String(row.organization).trim(),
        percentage: parseFloat(row.percentage),
    }))

    pendingSaveData.value = {
        name: values.name.trim(),
        formula: sanitizedRows,
        details: values.details || null,
    }
    showSaveConfirm.value = true
})

const confirmSave = () => {
    if (!pendingSaveData.value) return
    localIsSaving.value = true
    emit('save', pendingSaveData.value)
    showSaveConfirm.value = false
    pendingSaveData.value = null
}
</script>
