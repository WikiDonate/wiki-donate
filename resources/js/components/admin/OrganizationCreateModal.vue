<template>
    <AdminModal
        :model-value="modelValue"
        :title="t('admin.createOrganization')"
        size="lg"
        @close="close"
    >
        <!-- Formula-style auto-search: registry orgs first, external directory
             hits are labelled by source so admins know what they picked. -->
        <div class="relative">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                {{ t('admin.searchRegistryPlaceholder') }}
            </label>
            <input
                v-model="searchQuery"
                type="text"
                autocomplete="off"
                :placeholder="t('admin.searchRegistryPlaceholder')"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                @input="onSearchInput"
                @focus="onSearchFocus"
                @keydown.escape="dismissSuggestions"
            />
            <p class="text-xs text-gray-500 mt-1">
                {{ t('admin.manualEntryHint') }}
            </p>

            <ul
                v-if="isSearchOpen"
                class="absolute z-20 left-0 right-0 mt-1 max-h-64 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-xl py-1"
            >
                <li v-if="searching" class="px-3 py-2 text-sm text-gray-500 italic">
                    {{ t('admin.searchingRegistry') }}
                </li>
                <li
                    v-else-if="suggestions.length === 0"
                    class="px-3 py-2 text-sm text-gray-500 italic"
                >
                    {{ t('admin.noRegistryResults') }}
                </li>
                <template v-else>
                    <li
                        v-for="(suggestion, index) in suggestions"
                        :key="`${suggestion.source}-${suggestion.ein || suggestion.name}-${index}`"
                        class="px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50 cursor-pointer transition-colors"
                        @mousedown.prevent="applySuggestion(suggestion)"
                    >
                        <span class="flex items-center justify-between gap-2">
                            <span class="font-medium">{{ suggestion.name }}</span>
                            <AdminBadge
                                :variant="suggestion.source === 'local' ? 'success' : 'info'"
                                :text="sourceLabel(suggestion.source)"
                            />
                        </span>
                        <span class="block text-xs text-gray-500">
                            {{
                                [
                                    suggestion.ein ? `EIN: ${suggestion.ein}` : null,
                                    [suggestion.city, suggestion.state]
                                        .filter(Boolean)
                                        .join(', ') || null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </span>
                        <span
                            v-if="suggestion.rating !== null && suggestion.rating !== undefined"
                            class="text-xs text-amber-500"
                        >
                            ★ {{ suggestion.rating }}
                        </span>
                    </li>
                </template>
            </ul>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    {{ t('admin.organizationNameLabel') }}
                    <span class="text-red-500">*</span>
                </label>
                <input
                    v-model="name"
                    v-bind="nameProps"
                    type="text"
                    :class="[
                        'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                        fieldErrors.name ? 'border-red-500' : 'border-gray-300',
                    ]"
                />
                <p v-if="fieldErrors.name" class="text-xs text-red-600 mt-1">
                    {{ fieldErrors.name }}
                </p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    {{ t('admin.einLabel') }}
                </label>
                <input
                    v-model="ein"
                    v-bind="einProps"
                    type="text"
                    placeholder="12-3456789"
                    :class="[
                        'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                        fieldErrors.ein ? 'border-red-500' : 'border-gray-300',
                    ]"
                />
                <p v-if="fieldErrors.ein" class="text-xs text-red-600 mt-1">
                    {{ fieldErrors.ein }}
                </p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    {{ t('admin.websiteLabel') }}
                </label>
                <input
                    v-model="website"
                    v-bind="websiteProps"
                    type="text"
                    :class="[
                        'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                        fieldErrors.website ? 'border-red-500' : 'border-gray-300',
                    ]"
                />
                <p v-if="fieldErrors.website" class="text-xs text-red-600 mt-1">
                    {{ fieldErrors.website }}
                </p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    {{ t('admin.cityLabel') }}
                </label>
                <input
                    v-model="city"
                    v-bind="cityProps"
                    type="text"
                    :class="[
                        'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                        fieldErrors.city ? 'border-red-500' : 'border-gray-300',
                    ]"
                />
                <p v-if="fieldErrors.city" class="text-xs text-red-600 mt-1">
                    {{ fieldErrors.city }}
                </p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    {{ t('admin.stateLabel') }}
                </label>
                <input
                    v-model="state"
                    v-bind="stateProps"
                    type="text"
                    :class="[
                        'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                        fieldErrors.state ? 'border-red-500' : 'border-gray-300',
                    ]"
                />
                <p v-if="fieldErrors.state" class="text-xs text-red-600 mt-1">
                    {{ fieldErrors.state }}
                </p>
            </div>

            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                    {{ t('admin.paypalEmailLabel') }}
                </label>
                <input
                    v-model="paypalEmail"
                    v-bind="paypalEmailProps"
                    type="email"
                    :class="[
                        'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                        fieldErrors.paypal_email ? 'border-red-500' : 'border-gray-300',
                    ]"
                />
                <p v-if="fieldErrors.paypal_email" class="text-xs text-red-600 mt-1">
                    {{ fieldErrors.paypal_email }}
                </p>
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                {{ t('admin.payoutMethodsLabel') }}
                <span class="text-red-500">*</span>
            </label>
            <div v-if="payoutMethods.length === 0" class="text-xs text-gray-500 italic">
                {{ t('admin.noPayoutMethods') }}
            </div>
            <div v-else class="flex flex-wrap gap-3">
                <label
                    v-for="method in payoutMethods"
                    :key="method.code"
                    class="inline-flex items-center gap-2 px-3 py-2 border rounded-lg text-sm cursor-pointer"
                    :class="
                        selectedPayoutMethods.includes(method.code)
                            ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                            : 'border-gray-300 text-gray-700'
                    "
                >
                    <input
                        v-model="selectedPayoutMethods"
                        type="checkbox"
                        :value="method.code"
                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    {{ method.label }}
                </label>
            </div>
            <p v-if="fieldErrors.payout_methods" class="text-xs text-red-600 mt-1">
                {{ fieldErrors.payout_methods }}
            </p>
        </div>

        <p v-if="formError" class="text-xs text-red-600">{{ formError }}</p>

        <template #footer>
            <button
                class="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50"
                @click="close"
            >
                {{ t('common.cancel') }}
            </button>
            <button
                class="px-4 py-2 text-sm rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-40"
                :disabled="saving || payoutMethods.length === 0"
                @click="submit"
            >
                {{ saving ? t('common.saving') : t('common.create') }}
            </button>
        </template>
    </AdminModal>
</template>

<script setup>
    import { onMounted, ref, watch } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { useForm } from 'vee-validate'
    import * as yup from 'yup'
    import AdminModal from '~/components/admin/AdminModal.vue'
    import AdminBadge from '~/components/admin/AdminBadge.vue'
    import { adminService } from '~/services/adminService'
    import { charityService } from '~/services/charityService'
    import { usePaymentMethods } from '~/composables/usePaymentMethods'
    import { useToastify } from '~/composables/useToastify'

    const props = defineProps({
        modelValue: {
            type: Boolean,
            default: false,
        },
    })

    const emit = defineEmits(['update:modelValue', 'created'])

    const { t } = useI18n()
    const { notifySuccess, notifyError } = useToastify()
    const { payoutMethods, loadPaymentMethods } = usePaymentMethods()

    // EIN accepts the US forms 12-3456789 / 123456789; the server strips
    // separators before the uniqueness check.
    const schema = yup.object({
        name: yup
            .string()
            .trim()
            .required(t('admin.nameRequired'))
            .max(255, t('admin.fieldTooLong')),
        ein: yup
            .string()
            .nullable()
            .transform((v) => (v === '' ? null : v))
            .matches(/^\d{2}-?\d{7}$/, {
                message: t('admin.einInvalid'),
                excludeEmptyString: true,
            }),
        website: yup.string().nullable().max(255, t('admin.fieldTooLong')),
        city: yup.string().nullable().max(255, t('admin.fieldTooLong')),
        state: yup.string().nullable().max(255, t('admin.fieldTooLong')),
        paypal_email: yup
            .string()
            .nullable()
            .transform((v) => (v === '' ? null : v))
            .email(t('auth.emailInvalid'))
            .max(255, t('admin.fieldTooLong')),
    })

    const {
        handleSubmit,
        defineField,
        errors: fieldErrors,
        setErrors,
        resetForm,
    } = useForm({ validationSchema: schema })

    const [name, nameProps] = defineField('name')
    const [ein, einProps] = defineField('ein')
    const [website, websiteProps] = defineField('website')
    const [city, cityProps] = defineField('city')
    const [state, stateProps] = defineField('state')
    const [paypalEmail, paypalEmailProps] = defineField('paypal_email')

    const selectedPayoutMethods = ref(['paypal'])
    const saving = ref(false)
    const formError = ref('')

    // --- Auto-search (same debounce/abort pattern as the formula modal) ---
    const searchQuery = ref('')
    const suggestions = ref([])
    const searching = ref(false)
    const isSearchOpen = ref(false)
    let debounceTimer = null
    let abortController = null

    const sourceLabel = (source) =>
        source === 'local' ? t('admin.sourceRegistry') : t('admin.sourceExternal')

    const dismissSuggestions = () => {
        isSearchOpen.value = false
        suggestions.value = []
    }

    const onSearchFocus = () => {
        if (searchQuery.value.trim().length >= 2) {
            isSearchOpen.value = true
        }
    }

    const onSearchInput = () => {
        clearTimeout(debounceTimer)
        const query = searchQuery.value.trim()
        if (query.length < 2) {
            suggestions.value = []
            isSearchOpen.value = false
            return
        }
        isSearchOpen.value = true
        debounceTimer = setTimeout(() => fetchSuggestions(query), 300)
    }

    const fetchSuggestions = async (query) => {
        abortController?.abort()
        abortController = new AbortController()
        searching.value = true
        try {
            const response = await charityService.searchCharities(query, abortController.signal)
            suggestions.value = (response.data ?? []).slice(0, 10)
        } catch {
            suggestions.value = []
        } finally {
            searching.value = false
        }
    }

    const applySuggestion = (suggestion) => {
        name.value = suggestion.name || ''
        ein.value = suggestion.ein || ''
        city.value = suggestion.city || ''
        state.value = suggestion.state || ''
        website.value = suggestion.website || ''
        dismissSuggestions()
    }

    // --- Submit ---
    const submit = handleSubmit(async (values) => {
        formError.value = ''
        if (selectedPayoutMethods.value.length === 0) {
            setErrors({ payout_methods: t('admin.payoutMethodsRequired') })
            return
        }
        // registry-only target: a payout rail without a payee address can never
        // be paid out, so require the matching email before submitting.
        if (selectedPayoutMethods.value.includes('paypal') && !values.paypal_email) {
            setErrors({ paypal_email: t('admin.paypalEmailRequired') })
            return
        }
        saving.value = true
        try {
            const response = await adminService.createOrganization({
                name: values.name,
                ein: values.ein || null,
                website: values.website || null,
                city: values.city || null,
                state: values.state || null,
                country: 'US',
                paypal_email: values.paypal_email || null,
                payout_methods: [...selectedPayoutMethods.value],
            })
            if (response.success) {
                notifySuccess(t('admin.organizationCreated'))
                emit('created', response.data)
                close()
            }
        } catch (error) {
            const serverErrors = error.field_errors || {}
            if (Object.keys(serverErrors).length > 0) {
                const mapped = {}
                for (const [field, messages] of Object.entries(serverErrors)) {
                    mapped[field] = Array.isArray(messages) ? messages[0] : String(messages)
                }
                setErrors(mapped)
            }
            formError.value =
                error.message || error.errors?.[0] || t('admin.failedToCreateOrganization')
        } finally {
            saving.value = false
        }
    })

    const close = () => {
        emit('update:modelValue', false)
    }

    watch(
        () => props.modelValue,
        (open) => {
            if (!open) return
            resetForm()
            searchQuery.value = ''
            dismissSuggestions()
            formError.value = ''
            selectedPayoutMethods.value = ['paypal']
            loadPaymentMethods()
        },
    )

    // Keep the selection valid against the active registry: drop codes that
    // are not payout-capable and fall back to the first available rail.
    watch(payoutMethods, (rows) => {
        const codes = rows.map((row) => row.code)
        selectedPayoutMethods.value = selectedPayoutMethods.value.filter((code) =>
            codes.includes(code),
        )
        if (rows.length && selectedPayoutMethods.value.length === 0) {
            selectedPayoutMethods.value = [rows[0].code]
        }
    })

    onMounted(() => loadPaymentMethods())
</script>
