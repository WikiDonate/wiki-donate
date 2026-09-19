<template>
    <main>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <AdminPageHeader
                :title="t('admin.allDonations')"
                :subtitle="t('admin.allDonationsSubtitle')"
                :card="true"
            />

            <!-- Filters -->
            <div class="px-4 sm:px-6 py-3 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="relative flex-1">
                        <font-awesome-icon
                            :icon="['fas', 'search']"
                            class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"
                        />
                        <input
                            v-model="searchQuery"
                            type="text"
                            :placeholder="t('admin.searchDonorPlaceholder')"
                            class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                        />
                    </div>
                    <div class="flex gap-3 sm:w-auto w-full">
                        <select
                            v-model="filterStatus"
                            class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white"
                        >
                            <option value="">{{ t('admin.allStatus') }}</option>
                            <option value="completed">{{ t('admin.completed') }}</option>
                            <option value="expired">{{ t('admin.expired') }}</option>
                            <option value="failed">{{ t('admin.failed') }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <LoadingSpinner v-if="loading" :text="t('admin.loadingDonations')" />
            <template v-else>
                <AdminTable
                    :columns="columns"
                    :rows="donations"
                    row-key="id"
                    :empty-text="t('admin.noDonations')"
                >
                    <template #cell-source>
                        <AdminBadge variant="info" text="Checkout" />
                    </template>
                    <template #cell-donor="{ row }">
                        <div>
                            <span class="font-medium text-sm">{{ row.user }}</span>
                            <span v-if="row.email" class="block text-xs text-gray-400">{{
                                row.email
                            }}</span>
                        </div>
                    </template>
                    <template #cell-amount="{ row }"
                        >{{ row.currency || 'USD' }} {{ Number(row.amount).toFixed(2) }}</template
                    >
                    <template #cell-status="{ row }">
                        <AdminBadge :variant="statusVariant(row.status)" :text="row.status" />
                    </template>
                    <template #cell-date="{ row }"
                        ><span class="text-gray-500 text-xs">{{ row.date }}</span></template
                    >
                    <template #cell-paymentId="{ row }">
                        <span class="text-xs text-gray-400 font-mono">{{
                            row.payment_id || row.stripe_session_id || '—'
                        }}</span>
                    </template>
                    <template #cell-article="{ row }">
                        <template v-if="row.article">
                            <NuxtLink
                                :to="
                                    row.formula_url ||
                                    `/article?title=${encodeURIComponent(row.article.slug)}`
                                "
                                class="text-indigo-600 hover:text-indigo-800 font-medium text-sm underline"
                            >
                                {{ row.article.title }}
                            </NuxtLink>
                        </template>
                        <span v-else class="text-xs text-gray-400">—</span>
                    </template>
                    <template #cell-action="{ row }">
                        <button
                            class="text-indigo-600 hover:text-indigo-800 font-medium text-xs"
                            @click="openDetail(row)"
                        >
                            View
                        </button>
                    </template>
                </AdminTable>

                <div
                    v-if="meta.lastPage > 1"
                    class="px-4 py-3 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-2"
                >
                    <span class="text-sm text-gray-500 order-2 sm:order-1">
                        {{ t('report.pageOf', { current: meta.currentPage, last: meta.lastPage,
                            total: meta.total }) }}
                    </span>
                    <Pagination
                        :current-page="meta.currentPage"
                        :total-pages="meta.lastPage"
                        @page-change="loadPage"
                    />
                </div>
            </template>
        </div>

        <DonationDetailModal
            v-model="showDetail"
            :donation="selectedDonation"
            :formula-url="selectedFormulaUrl"
        />
    </main>
</template>

<script setup>
    import { ref, onMounted, watch } from 'vue'
    import { adminService } from '~/services/adminService'
    import { useToastify } from '~/composables/useToastify'
    import DonationDetailModal from '~/components/admin/DonationDetailModal.vue'

    const { notifyError } = useToastify()

    const selectedDonation = ref(null)
    const selectedFormulaUrl = ref('')
    const showDetail = ref(false)

    function openDetail(row) {
        selectedDonation.value = row
        selectedFormulaUrl.value = row.formula_url || ''
        showDetail.value = true
    }

    const { t } = useI18n()

    useHead({ title: t('admin.allDonations') })
    definePageMeta({ layout: 'admin', middleware: ['auth', 'admin'] })

    const donations = ref([])
    const meta = ref({ currentPage: 1, lastPage: 1, total: 0 })
    const loading = ref(true)
    const searchQuery = ref('')
    const filterStatus = ref('')
    let searchTimeout = null

    const columns = [
        { key: 'date', label: t('admin.date') },
        { key: 'donor', label: t('admin.donor') },
        { key: 'source', label: t('admin.source') },
        { key: 'amount', label: t('admin.amount') },
        { key: 'status', label: t('admin.status') },
        { key: 'paymentId', label: t('admin.paymentId') },
        { key: 'article', label: t('admin.article') },
        { key: 'action', label: t('admin.action') },
    ]

    function statusVariant(status) {
        if (status === 'completed' || status === 'succeeded') return 'success'
        if (status === 'pending') return 'warning'
        return 'danger'
    }

    const loadPage = async (page = 1) => {
        loading.value = true
        try {
            const params = { page, per_page: 15 }
            if (searchQuery.value.trim()) params.search = searchQuery.value.trim()
            if (filterStatus.value) params.status = filterStatus.value

            const res = await adminService.getDonations(params)
            if (res.success) {
                donations.value = res.data
                meta.value = res.meta
            }
        } catch (error) {
            notifyError(error.errors?.[0] || t('admin.failedToLoadDonations'))
        } finally {
            loading.value = false
        }
    }

    watch([searchQuery, filterStatus], () => {
        clearTimeout(searchTimeout)
        searchTimeout = setTimeout(() => loadPage(1), 400)
    })

    onMounted(() => loadPage())
</script>
