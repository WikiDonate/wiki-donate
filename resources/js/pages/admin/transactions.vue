<template>
    <main>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <AdminPageHeader
                :title="t('admin.transactions')"
                :subtitle="t('admin.transactionsSubtitle')"
                :card="true"
            >
                <template #actions>
                    <button
                        class="inline-flex items-center gap-2 px-3 py-2 bg-white/20 hover:bg-white/30 text-white text-sm font-medium rounded-lg transition-colors"
                        :disabled="exporting"
                        @click="exportCsv"
                    >
                        <font-awesome-icon :icon="['fas', 'file-invoice-dollar']" class="w-4 h-4" />
                        {{ t('admin.exportCsv') }}
                    </button>
                </template>
            </AdminPageHeader>

            <!-- Summary cards -->
            <div
                class="grid grid-cols-2 lg:grid-cols-4 gap-3 px-4 sm:px-6 py-4 border-b border-gray-100"
            >
                <StatCard
                    icon="['fas', 'donate']"
                    icon-bg="green"
                    :label="t('admin.totalIncome')"
                    :value="formatAmount(summary.totalIncome)"
                />
                <StatCard
                    icon="['fas', 'credit-card']"
                    icon-bg="red"
                    :label="t('admin.totalPayouts')"
                    :value="formatAmount(summary.totalPayouts)"
                />
                <StatCard
                    icon="['fas', 'chart-line']"
                    icon-bg="amber"
                    :label="t('admin.remainingPayable')"
                    :value="formatAmount(summary.remainingPayable)"
                />
                <StatCard
                    icon="['fas', 'coins']"
                    icon-bg="indigo"
                    :label="t('admin.netInHand')"
                    :value="formatAmount(summary.netInHand)"
                />
            </div>

            <!-- Filters -->
            <div class="px-4 sm:px-6 py-3 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row gap-3">
                    <select
                        v-model="filterType"
                        class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    >
                        <option value="">{{ t('admin.allTypes') }}</option>
                        <option value="income">{{ t('admin.income') }}</option>
                        <option value="expense">{{ t('admin.expense') }}</option>
                    </select>
                    <select
                        v-model="filterMethod"
                        class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    >
                        <option value="">{{ t('admin.allMethods') }}</option>
                        <option value="stripe">{{ t('admin.stripe') }}</option>
                        <option value="paypal">{{ t('admin.paypal') }}</option>
                    </select>
                    <input
                        v-model="filterOrg"
                        type="text"
                        :placeholder="t('admin.organizationPlaceholder')"
                        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    />
                    <input
                        v-model="filterArticle"
                        type="text"
                        :placeholder="t('admin.articleSlugPlaceholder')"
                        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    />
                    <input
                        v-model="fromDate"
                        type="date"
                        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    />
                    <input
                        v-model="toDate"
                        type="date"
                        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    />
                    <Button
                        variant="primary"
                        :text="t('common.apply')"
                        width="auto"
                        @click="loadPage(1)"
                    />
                </div>
            </div>

            <LoadingSpinner v-if="loading" :text="t('admin.loadingTransactions')" />
            <template v-else>
                <div class="px-4 sm:px-6 py-4">
                    <AdminTable :columns="columns" :rows="transactions" row-key="id">
                        <template #cell-date="{ row }">
                            <span class="text-gray-500 text-xs">{{ row.date }}</span>
                        </template>
                        <template #cell-description="{ row }">
                            <div>
                                <p class="text-sm font-medium text-gray-800">
                                    {{ row.description }}
                                </p>
                                <p
                                    v-if="row.type === 'income' && row.article"
                                    class="text-xs text-gray-400"
                                >
                                    {{ t('admin.articleLabel', { title: row.article.title }) }}
                                </p>
                                <p v-if="row.type === 'expense'" class="text-xs text-gray-400">
                                    {{ row.context }}
                                </p>
                            </div>
                        </template>
                        <template #cell-type="{ row }">
                            <AdminBadge
                                :variant="row.type === 'income' ? 'success' : 'danger'"
                                :text="
                                    row.type === 'income' ? t('admin.income') : t('admin.expense')
                                "
                            />
                        </template>
                        <template #cell-amount="{ row }">
                            <span
                                class="font-semibold text-sm"
                                :class="row.type === 'income' ? 'text-green-600' : 'text-red-600'"
                            >
                                {{ row.type === 'income' ? '+' : '−'
                                }}{{ formatAmount(row.amount) }}
                                <span class="text-[10px] uppercase text-gray-400">{{
                                    row.currency
                                }}</span>
                            </span>
                        </template>
                        <template #cell-method="{ row }">
                            <span v-if="row.type === 'income'" class="text-xs capitalize">{{
                                row.method
                            }}</span>
                            <template v-else>
                                <AdminBadge
                                    :variant="row.payout_type === 'full' ? 'info' : 'amber'"
                                    :text="
                                        row.payout_type === 'full'
                                            ? t('admin.full')
                                            : t('admin.partial')
                                    "
                                />
                            </template>
                        </template>
                        <template #cell-status="{ row }">
                            <span class="text-xs text-gray-500">{{ row.status || '—' }}</span>
                        </template>
                        <template #cell-context="{ row }">
                            <RouterLink
                                v-if="row.type === 'income' && row.article"
                                :to="`/article?title=${encodeURIComponent(row.article.slug)}`"
                                class="text-indigo-600 hover:text-indigo-800 text-xs underline"
                            >
                                {{ t('common.view') }}
                            </RouterLink>
                            <span v-else class="text-xs text-gray-400">—</span>
                        </template>
                    </AdminTable>

                    <div
                        v-if="meta.lastPage > 1"
                        class="px-4 py-3 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-2"
                    >
                        <span class="text-sm text-gray-500 order-2 sm:order-1">
                            {{
                                t('report.pageOf', {
                                    current: meta.currentPage,
                                    last: meta.lastPage,
                                    total: meta.total,
                                })
                            }}
                        </span>
                        <Pagination
                            :current-page="meta.currentPage"
                            :total-pages="meta.lastPage"
                            @page-change="loadPage"
                        />
                    </div>
                </div>
            </template>
        </div>
    </main>
</template>

<script setup>
    import { ref, onMounted } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { adminService } from '~/services/adminService'
    import api from '~/config/apiConfig'
    import { useToastify } from '~/composables/useToastify'
    import AdminPageHeader from '~/components/admin/AdminPageHeader.vue'
    import AdminTable from '~/components/admin/AdminTable.vue'
    import AdminBadge from '~/components/admin/AdminBadge.vue'
    import StatCard from '~/components/admin/StatCard.vue'
    import LoadingSpinner from '~/components/LoadingSpinner.vue'
    import Pagination from '~/components/Pagination.vue'
    import Button from '~/components/Button.vue'

    const { notifyError } = useToastify()

    const { t } = useI18n()

    useHead({ title: t('admin.transactions') })
    definePageMeta({ layout: 'admin', middleware: ['auth', 'admin'] })

    const transactions = ref([])
    const meta = ref({ currentPage: 1, lastPage: 1, total: 0 })
    const summary = ref({ totalIncome: 0, totalPayouts: 0, remainingPayable: 0, netInHand: 0 })
    const loading = ref(true)
    const exporting = ref(false)

    const filterType = ref('')
    const filterMethod = ref('')
    const filterOrg = ref('')
    const filterArticle = ref('')
    const fromDate = ref('')
    const toDate = ref('')

    const columns = [
        { key: 'date', label: t('admin.date') },
        { key: 'description', label: t('admin.description') },
        { key: 'type', label: t('admin.type') },
        { key: 'amount', label: t('admin.amount') },
        { key: 'method', label: t('admin.method') },
        { key: 'status', label: t('admin.status') },
        { key: 'context', label: t('admin.context') },
    ]

    function formatAmount(value) {
        const n = Number(value ?? 0)
        return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    }

    function buildParams(page) {
        const params = { page, per_page: 20 }
        if (filterType.value) params.type = filterType.value
        if (filterMethod.value) params.method = filterMethod.value
        if (filterOrg.value.trim()) params.org = filterOrg.value.trim()
        if (filterArticle.value.trim()) params.article = filterArticle.value.trim()
        if (fromDate.value) params.from = fromDate.value
        if (toDate.value) params.to = toDate.value
        return params
    }

    const loadPage = async (page = 1) => {
        loading.value = true
        try {
            const params = buildParams(page)
            const [txRes, sumRes] = await Promise.all([
                adminService.getTransactions(params),
                adminService.getTransactionSummary(params),
            ])
            if (txRes.success) {
                transactions.value = txRes.data
                meta.value = txRes.meta
            }
            if (sumRes.success) {
                summary.value = sumRes.data
            }
        } catch (error) {
            notifyError(error.errors?.[0] || t('admin.failedToLoadTransactions'))
        } finally {
            loading.value = false
        }
    }

    async function exportCsv() {
        const params = buildParams(1)
        delete params.page
        delete params.per_page
        exporting.value = true
        try {
            // apiConfig response interceptor unwraps axios responses to the body,
            // so with responseType 'blob' the resolved value IS the Blob.
            const res = await api.get('/admin/transactions/export', {
                params,
                responseType: 'blob',
            })
            const blob = res instanceof Blob ? res : new Blob([res], { type: 'text/csv' })
            const url = URL.createObjectURL(blob)
            const a = document.createElement('a')
            a.href = url
            a.download = `transactions-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '')}.csv`
            document.body.appendChild(a)
            a.click()
            a.remove()
            URL.revokeObjectURL(url)
        } catch (e) {
            const payload = e?.response?.data ?? e?.message
            let message = t('admin.exportFailed')
            if (payload instanceof Blob) {
                try {
                    message = JSON.parse(await payload.text()).message || message
                } catch {
                    // keep generic message
                }
            } else if (typeof payload === 'string' && payload) {
                try {
                    message = JSON.parse(payload).message || message
                } catch {
                    message = payload
                }
            } else if (payload?.message) {
                message = payload.message
            }
            notifyError(message)
        } finally {
            exporting.value = false
        }
    }

    onMounted(loadPage)
</script>
