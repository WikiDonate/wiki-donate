<template>
    <main>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <AdminPageHeader
                :title="t('admin.orgPayoutsTitle')"
                :subtitle="t('admin.orgPayoutsSubtitle')"
                :card="true"
            />

            <LoadingSpinner v-if="loading" :text="t('admin.loadingAllocations')" />
            <template v-else>
                <!-- Payable allocations -->
                <AdminTable
                    :columns="columns"
                    :rows="allocations"
                    :row-key="rowKey"
                    :empty-text="t('admin.noAllocations')"
                >
                    <template #cell-organization="{ row }">
                        <div>
                            <span class="font-medium text-sm">{{ row.organization_name }}</span>
                            <span class="block text-xs text-gray-400">
                                {{ row.percentage }}% · {{ row.formula_name || 'Formula' }}
                            </span>
                            <span v-if="row.article" class="block text-xs text-gray-400">
                                {{ row.article.title }}
                            </span>
                        </div>
                    </template>
                    <template #cell-amounts="{ row }">
                        <div class="text-sm space-y-0.5">
                            <div>
                                {{ t('admin.owed') }}
                                <span class="font-medium">{{ fmt(row.currency, row.owed) }}</span>
                            </div>
                            <div>
                                {{ t('admin.paid') }}
                                <span class="text-gray-500">{{ fmt(row.currency, row.paid) }}</span>
                            </div>
                            <div>
                                {{ t('admin.balance') }}
                                <span
                                    class="font-semibold"
                                    :class="row.balance > 0 ? 'text-green-600' : 'text-gray-400'"
                                >
                                    {{ fmt(row.currency, row.balance) }}
                                </span>
                            </div>
                        </div>
                    </template>
                    <template #cell-actions="{ row }">
                        <div class="flex items-center gap-2">
                            <button
                                class="px-3 py-1.5 text-xs font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                :disabled="row.balance <= 0"
                                @click="openPay(row)"
                            >
                                {{ t('admin.pay') }}
                            </button>
                            <button
                                class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors"
                                @click="openHistory(row)"
                            >
                                {{ t('admin.history') }}
                            </button>
                        </div>
                    </template>
                </AdminTable>
            </template>
        </div>

        <!-- Pay modal -->
        <div
            v-if="payTarget"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="payTarget = null"
        >
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-800">
                    {{ t('admin.payTitle', { org: payTarget.organization_name }) }}
                </h3>
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
                    </label>
                    <input
                        v-model.number="payAmount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    />
                    <p v-if="payError" class="text-xs text-red-600 mt-1">
                        {{ payError }}
                    </p>
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
                <div class="flex justify-end gap-2 pt-2">
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
                </div>
            </div>
        </div>

        <!-- History modal -->
        <div
            v-if="historyTarget"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="historyTarget = null"
        >
            <div
                class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6 space-y-3 max-h-[80vh] overflow-y-auto"
            >
                <h3 class="text-lg font-bold text-gray-800">
                    {{ t('admin.payoutHistory', { org: historyTarget.organization_name }) }}
                </h3>
                <p v-if="historyError" class="text-xs text-red-600">{{ historyError }}</p>
                <LoadingSpinner v-if="historyLoading" :text="t('admin.loadingHistory')" />
                <div
                    v-else-if="history.length === 0"
                    class="text-sm text-gray-400 text-center py-6"
                >
                    {{ t('admin.noPayouts') }}
                </div>
                <ul v-else class="divide-y divide-gray-100 text-sm">
                    <li v-for="h in history" :key="h.uuid" class="py-2 flex justify-between gap-3">
                        <div>
                            <div class="font-medium">
                                {{ fmt(h.currency, h.amount) }}
                                <span
                                    class="ml-1 text-[10px] uppercase px-1.5 py-0.5 rounded"
                                    :class="
                                        h.type === 'full'
                                            ? 'bg-green-50 text-green-700'
                                            : 'bg-amber-50 text-amber-700'
                                    "
                                >
                                    {{ h.type }}
                                </span>
                            </div>
                            <div class="text-xs text-gray-400">
                                {{ h.paid_at }} · by {{ h.actor?.username || '—' }}
                            </div>
                            <div v-if="h.note" class="text-xs text-gray-500">{{ h.note }}</div>
                        </div>
                    </li>
                </ul>
                <div class="flex justify-end">
                    <button
                        class="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50"
                        @click="historyTarget = null"
                    >
                        {{ t('admin.close') }}
                    </button>
                </div>
            </div>
        </div>
    </main>
</template>

<script setup>
    import { ref } from 'vue'
    import { adminService } from '@/services/adminService'

    const loading = ref(true)
    const allocations = ref([])

    const payTarget = ref(null)
    const payAmount = ref(0)
    const payNote = ref('')
    const payError = ref('')
    const paying = ref(false)

    const historyTarget = ref(null)
    const history = ref([])
    const historyLoading = ref(false)
    const historyError = ref('')

    const { t } = useI18n()

    const columns = [
        { key: 'organization', label: t('admin.organizationAllocation') },
        { key: 'amounts', label: t('admin.owedPaidBalance') },
        { key: 'actions', label: '', tdClass: 'text-right', thClass: 'text-right' },
    ]

    const rowKey = (row) => `${row.formula_id}:${row.organization_key}`

    const fmt = (currency, value) =>
        `${(currency || 'usd').toUpperCase()} ${Number(value || 0).toFixed(2)}`

    const fetchAllocations = async () => {
        loading.value = true
        try {
            allocations.value = (await adminService.getPayoutAllocations()).data ?? []
        } catch (err) {
            console.error('Failed to load payout allocations', err)
            allocations.value = []
        } finally {
            loading.value = false
        }
    }

    const openPay = (row) => {
        payTarget.value = row
        payAmount.value = Number(row.balance)
        payNote.value = ''
        payError.value = ''
    }

    const submitPay = async () => {
        payError.value = ''
        paying.value = true
        try {
            await adminService.createPayout({
                donation_formula_id: payTarget.value.formula_id,
                organization_name: payTarget.value.organization_name,
                amount: payAmount.value,
                currency: payTarget.value.currency,
                note: payNote.value || null,
            })
            payTarget.value = null
            await fetchAllocations()
        } catch (err) {
            payError.value = err.response?.data?.message || t('admin.failedToRecord')
        } finally {
            paying.value = false
        }
    }

    const openHistory = async (row) => {
        historyTarget.value = row
        history.value = []
        historyError.value = ''
        historyLoading.value = true
        try {
            const res = await adminService.getPayoutHistory(row.formula_id, row.organization_name)
            history.value = res.data ?? []
        } catch (err) {
            historyError.value = err.response?.data?.message || t('admin.failedToLoadHistory')
        } finally {
            historyLoading.value = false
        }
    }

    fetchAllocations()
</script>
