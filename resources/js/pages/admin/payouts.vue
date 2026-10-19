<template>
    <main>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <AdminPageHeader
                :title="t('admin.orgPayoutsTitle')"
                :subtitle="t('admin.orgPayoutsSubtitle')"
                :card="true"
            />

            <div class="px-4 sm:px-6 py-4">
                <div class="flex flex-col sm:flex-row gap-3 mb-3">
                    <AdminSearchInput
                        v-model="searchQuery"
                        :placeholder="t('admin.searchPayoutsPlaceholder')"
                    />
                    <button
                        class="px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed sm:flex-initial"
                        :disabled="syncing"
                        @click="syncStatuses"
                    >
                        {{ syncing ? t('admin.syncing') : t('admin.syncStatuses') }}
                    </button>
                </div>
                <p v-if="syncMessage" class="text-xs text-green-600 mb-2">{{ syncMessage }}</p>
                <p v-if="syncError" class="text-xs text-red-600 mb-2">{{ syncError }}</p>
                <LoadingSpinner v-if="loading" text="Loading allocations..." />
                <template v-else>
                    <!-- Payable allocations, grouped by organization.
                        Money stays per-formula (each row pays one formula);
                        only the display is merged. Totals are never summed
                        across rows because formulas can differ in currency. -->
                    <div
                        v-if="groupedAllocations.length === 0"
                        class="text-center text-sm text-gray-400 py-8"
                    >
                        No payable allocations yet
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="group in groupedAllocations"
                            :key="group.organization_key"
                            class="border border-gray-200 rounded-xl overflow-hidden"
                        >
                            <button
                                class="w-full flex items-center justify-between gap-3 px-4 py-3 bg-gray-50 hover:bg-gray-100 transition-colors text-left"
                                @click="toggleGroup(group.organization_key)"
                            >
                                <span class="flex items-center gap-2 min-w-0">
                                    <span class="font-medium text-sm truncate">
                                        {{ group.organization_name }}
                                    </span>
                                    <AdminBadge
                                        :variant="group.is_verified ? 'success' : 'danger'"
                                        :text="
                                            group.is_verified
                                                ? t('admin.verified')
                                                : t('admin.unverified')
                                        "
                                    />
                                    <span class="text-xs text-gray-400 shrink-0">
                                        {{
                                            t('admin.payoutFormulaCount', {
                                                count: group.rows.length,
                                            })
                                        }}
                                    </span>
                                </span>
                                <font-awesome-icon
                                    :icon="['fas', 'chevron-down']"
                                    class="w-3.5 h-3.5 text-gray-400 transition-transform shrink-0"
                                    :class="{ 'rotate-180': !collapsed[group.organization_key] }"
                                />
                            </button>
                            <ul
                                v-show="!collapsed[group.organization_key]"
                                class="divide-y divide-gray-100"
                            >
                                <li
                                    v-for="row in group.rows"
                                    :key="rowKey(row)"
                                    class="px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4"
                                >
                                    <div class="flex-1 min-w-0">
                                        <span class="block text-xs text-gray-500">
                                            {{ row.percentage }}% ·
                                            {{ row.formula_name || 'Formula' }}
                                        </span>
                                        <span
                                            v-if="row.article"
                                            class="block text-xs text-gray-400 truncate"
                                        >
                                            {{ row.article.title }}
                                        </span>
                                    </div>
                                    <div class="text-sm sm:text-right shrink-0">
                                        <span class="text-gray-500">Balance:</span>
                                        <span
                                            class="font-semibold"
                                            :class="
                                                row.balance > 0 ? 'text-green-600' : 'text-gray-400'
                                            "
                                        >
                                            {{ fmt(row.currency, row.balance) }}
                                        </span>
                                        <span class="block text-xs text-gray-400">
                                            Owed {{ fmt(row.currency, row.owed) }} · Paid
                                            {{ fmt(row.currency, row.paid) }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button
                                            class="px-3 py-1.5 text-xs font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                            :disabled="row.balance <= 0 || !row.is_verified"
                                            :title="
                                                !row.is_verified
                                                    ? t('admin.payDisabledUnverified')
                                                    : ''
                                            "
                                            @click="openPay(row)"
                                        >
                                            Pay
                                        </button>
                                        <button
                                            class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors"
                                            @click="openHistory(row)"
                                        >
                                            History
                                        </button>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Pay modal -->
        <AdminModal
            :model-value="!!payTarget"
            :title="t('admin.payTitle', { org: payTarget?.organization_name ?? '' })"
            @close="payTarget = null"
        >
            <template v-if="payTarget">
                <p class="text-sm text-gray-500">
                    {{ t('admin.liveBalance') }}
                    <span class="font-semibold text-gray-700">
                        {{ fmt(payTarget.currency, payTarget.balance) }}
                    </span>
                    · Formula: {{ payTarget.formula_name || '—' }}
                </p>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        {{ t('admin.amountLabel', { currency: payTarget.currency }) }}
                        <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model.number="payAmount"
                        v-bind="payAmountProps"
                        type="number"
                        step="0.01"
                        min="0.01"
                        :class="[
                            'w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none',
                            payFieldErrors.amount
                                ? 'border-red-500 focus:ring-red-500 focus:border-red-500'
                                : 'border-gray-300',
                        ]"
                    />
                    <p v-if="payFieldErrors.amount || payError" class="text-xs text-red-600 mt-1">
                        {{ payFieldErrors.amount || payError }}
                    </p>
                </div>
                <div v-if="payoutMethods.length">
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        {{ t('donate.paymentMethod') }}
                    </label>
                    <select
                        v-model="payMethod"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    >
                        <option
                            v-for="method in payoutMethods"
                            :key="method.code"
                            :value="method.code"
                        >
                            {{ method.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">
                        {{ t('admin.noteLabel') }}
                    </label>
                    <input
                        v-model="payNote"
                        type="text"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    />
                </div>
            </template>
            <template #footer>
                <button
                    class="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50"
                    @click="payTarget = null"
                >
                    {{ t('admin.cancel') }}
                </button>
                <button
                    class="px-4 py-2 text-sm rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-40"
                    :disabled="paying"
                    @click="submitPay"
                >
                    {{ paying ? t('admin.paying') : t('admin.recordPayout') }}
                </button>
            </template>
        </AdminModal>

        <!-- History modal -->
        <AdminModal
            :model-value="!!historyTarget"
            :title="t('admin.payoutHistory', { org: historyTarget?.organization_name ?? '' })"
            size="lg"
            @close="historyTarget = null"
        >
            <p v-if="historyError" class="text-xs text-red-600">{{ historyError }}</p>
            <LoadingSpinner v-if="historyLoading" :text="t('admin.loadingHistory')" />
            <div v-else-if="history.length === 0" class="text-sm text-gray-400 text-center py-6">
                {{ t('admin.noPayouts') }}
            </div>
            <ul v-else class="divide-y divide-gray-100 text-sm">
                <li v-for="h in history" :key="h.uuid" class="py-2 flex justify-between gap-3">
                    <div>
                        <div class="font-medium">
                            {{ fmt(h.currency, h.amount) }}
                            <AdminBadge
                                class="ml-1 uppercase"
                                :variant="h.type === 'full' ? 'success' : 'amber'"
                                :text="h.type"
                            />
                        </div>
                        <div class="text-xs text-gray-400">
                            {{ formatDateUTC(h.paid_at) || t('admin.unpaid') }} · by
                            {{ h.actor?.username || '—' }}
                        </div>
                        <div class="text-xs flex items-center gap-1.5 mt-0.5">
                            <AdminBadge
                                v-if="h.payment_method"
                                :variant="methodVariant(h.payment_method)"
                                :text="methodLabel(h.payment_method)"
                            />
                            <AdminBadge
                                :variant="statusVariant(h.status)"
                                :text="statusLabel(h.status)"
                            />
                            <span
                                v-if="h.status === 'failed' && h.failure_reason"
                                class="text-red-500"
                            >
                                {{ h.failure_reason }}
                            </span>
                        </div>
                        <div v-if="h.note" class="text-xs text-gray-500">{{ h.note }}</div>
                    </div>
                </li>
            </ul>
            <template #footer>
                <button
                    class="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50"
                    @click="historyTarget = null"
                >
                    {{ t('admin.close') }}
                </button>
            </template>
        </AdminModal>
    </main>
</template>

<script setup>
    import { adminService } from '@/services/adminService'
    import { formatDateUTC } from '~/utils/dateFormatUTC'
    import { computed, ref } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { useForm } from 'vee-validate'
    import * as yup from 'yup'
    import AdminBadge from '~/components/admin/AdminBadge.vue'
    import AdminModal from '~/components/admin/AdminModal.vue'
    import AdminSearchInput from '~/components/admin/AdminSearchInput.vue'
    import { usePaymentMethods } from '~/composables/usePaymentMethods'
    import { useToastify } from '~/composables/useToastify'

    const loading = ref(true)
    const allocations = ref([])
    const searchQuery = ref('')

    const payTarget = ref(null)
    const payNote = ref('')
    const payError = ref('')
    const paying = ref(false)

    // Payout rail is data-driven from the registry's payout-capable methods.
    const { payoutMethods, loadPaymentMethods, methodLabel, methodVariant } = usePaymentMethods()
    const payMethod = ref('')

    const syncing = ref(false)
    const syncMessage = ref('')
    const syncError = ref('')

    const historyTarget = ref(null)
    const history = ref([])
    const historyLoading = ref(false)
    const historyError = ref('')

    const { t } = useI18n()
    const { notifyError } = useToastify()

    // Same vee-validate + yup stack as the rest of the app (login,
    // formula modal, ...). Invalid fields get a red border + message.
    const paySchema = yup.object({
        amount: yup
            .number()
            .typeError(t('admin.payoutAmountRequired'))
            .required(t('admin.payoutAmountRequired'))
            .min(0.01, t('admin.payoutAmountPositive')),
    })

    const {
        handleSubmit: handlePaySubmit,
        defineField: definePayField,
        errors: payFieldErrors,
        resetForm: resetPayForm,
    } = useForm({ validationSchema: paySchema })

    const [payAmount, payAmountProps] = definePayField('amount')

    const rowKey = (row) => `${row.formula_id}:${row.organization_key}`

    const collapsed = ref({})

    const toggleGroup = (key) => {
        collapsed.value[key] = !collapsed.value[key]
    }

    const groupedAllocations = computed(() => {
        const q = searchQuery.value.trim().toLowerCase()
        const rows = !q
            ? allocations.value
            : allocations.value.filter((row) =>
                  [row.organization_name, row.formula_name, row.article?.title]
                      .filter(Boolean)
                      .some((v) => String(v).toLowerCase().includes(q)),
              )
        const groups = new Map()
        for (const row of rows) {
            if (!groups.has(row.organization_key)) {
                groups.set(row.organization_key, {
                    organization_key: row.organization_key,
                    organization_name: row.organization_name,
                    organization_id: row.organization_id,
                    payout_status: row.payout_status,
                    is_verified: row.is_verified,
                    rows: [],
                })
            }
            const group = groups.get(row.organization_key)
            // Same registry org across formulas shares verification state;
            // keep the strictest (unverified wins if ever mixed).
            if (!row.is_verified) {
                group.is_verified = false
                group.payout_status = row.payout_status
            }
            group.rows.push(row)
        }
        return [...groups.values()].sort((a, b) =>
            a.organization_name.localeCompare(b.organization_name),
        )
    })

    // apiConfig unwraps res.data and rejects with the response BODY
    // ({message, errors}), not an axios error — so read err.message /
    // err.errors directly instead of err.response.data.
    const apiErrorMessage = (err, fallback) => {
        if (typeof err?.errors === 'object' && err.errors !== null) {
            const first = Object.values(err.errors).flat().find(Boolean)
            if (first) return String(first)
        }
        if (Array.isArray(err?.errors) && err.errors.length > 0) {
            return String(err.errors[0])
        }
        return err?.message || fallback
    }

    const fmt = (currency, value) =>
        `${(currency || 'usd').toUpperCase()} ${Number(value || 0).toFixed(2)}`

    const fetchAllocations = async () => {
        loading.value = true
        try {
            allocations.value = (await adminService.getPayoutAllocations()).data ?? []
        } catch (err) {
            notifyError(apiErrorMessage(err, t('admin.failedToLoadPayouts')))
            allocations.value = []
        } finally {
            loading.value = false
        }
    }

    const openPay = (row) => {
        if (!row.is_verified) {
            return
        }
        payTarget.value = row
        // Default to the first payout-capable registry method (PayPal when
        // the registry has not loaded yet, to preserve legacy behaviour).
        payMethod.value = payoutMethods.value[0]?.code || 'paypal'
        resetPayForm({ values: { amount: Number(row.balance) } })
        payNote.value = ''
        payError.value = ''
    }

    const statusLabel = (status) => {
        if (status === 'paid') return t('admin.payoutStatusPaid')
        if (status === 'pending') return t('admin.payoutStatusPending')
        if (status === 'failed') return t('admin.payoutStatusFailed')
        return status || '—'
    }

    const statusVariant = (status) => {
        if (status === 'paid') return 'success'
        if (status === 'pending') return 'amber'
        if (status === 'failed') return 'danger'
        return 'info'
    }

    const syncStatuses = async () => {
        syncError.value = ''
        syncMessage.value = ''
        syncing.value = true
        try {
            const res = await adminService.syncPayouts()
            const count = (res.data ?? []).length
            syncMessage.value = count > 0 ? `${count} payout(s) updated` : t('admin.noPayouts')
            await fetchAllocations()
        } catch (err) {
            syncError.value = apiErrorMessage(err, t('admin.failedToSync'))
        } finally {
            syncing.value = false
        }
    }

    const submitPay = handlePaySubmit(async (values) => {
        payError.value = ''
        const amount = Number(values.amount)
        if (amount > payTarget.value.balance + 0.009) {
            payError.value = t('admin.payoutAmountExceeds', {
                balance: fmt(payTarget.value.currency, payTarget.value.balance),
            })
            return
        }
        paying.value = true
        try {
            await adminService.createPayout({
                donation_formula_id: payTarget.value.formula_id,
                organization_name: payTarget.value.organization_name,
                amount,
                currency: payTarget.value.currency,
                payment_method: payMethod.value || undefined,
                note: payNote.value || null,
            })
            payTarget.value = null
            await fetchAllocations()
        } catch (err) {
            payError.value = apiErrorMessage(err, t('admin.failedToRecord'))
        } finally {
            paying.value = false
        }
    })

    const openHistory = async (row) => {
        historyTarget.value = row
        history.value = []
        historyError.value = ''
        historyLoading.value = true
        try {
            const res = await adminService.getPayoutHistory(row.formula_id, row.organization_name)
            history.value = res.data ?? []
        } catch (err) {
            historyError.value = apiErrorMessage(err, t('admin.failedToLoadHistory'))
        } finally {
            historyLoading.value = false
        }
    }

    fetchAllocations()
    loadPaymentMethods()
</script>
