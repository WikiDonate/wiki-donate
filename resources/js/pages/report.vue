<template>
    <main class="w-full mx-auto max-w-5xl px-2 sm:px-4 lg:px-6 py-8">
        <TopBarTitle :page-title="'My Donations'" />

        <!-- Filters -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden mb-6">
            <div class="px-6 py-4 bg-linear-to-r from-indigo-600 to-purple-600 text-white">
                <h2 class="text-lg sm:text-xl font-semibold">Filters</h2>
            </div>
            <div class="p-6">
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="relative flex-1">
                        <font-awesome-icon
                            :icon="['fas', 'search']"
                            class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"
                        />
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search by payment ID or email..."
                            class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                        />
                    </div>
                    <select
                        v-model="filterStatus"
                        class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white"
                    >
                        <option value="">All Status</option>
                        <option value="completed">Completed</option>
                        <option value="pending">Pending</option>
                        <option value="failed">Failed</option>
                        <option value="expired">Expired</option>
                    </select>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="flex-1 w-full sm:w-auto">
                            <input
                                v-model="fromDate"
                                type="date"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                            />
                        </div>
                        <div class="flex-1 w-full sm:w-auto">
                            <input
                                v-model="toDate"
                                type="date"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                            />
                        </div>
                        <Button variant="primary" text="Apply" width="auto" @click="loadPage(1)" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-5 text-center">
                <p class="text-3xl font-bold text-indigo-600 mb-1">
                    {{ formatAmount(summary.totalDonated) }}
                </p>
                <p class="text-xs sm:text-sm text-gray-500 font-medium">Total Donated</p>
            </div>
            <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-5 text-center">
                <p class="text-3xl font-bold text-green-600 mb-1">{{ summary.totalDonations }}</p>
                <p class="text-xs sm:text-sm text-gray-500 font-medium">Completed</p>
            </div>
            <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-5 text-center">
                <p class="text-3xl font-bold text-amber-500 mb-1">{{ summary.pendingDonations }}</p>
                <p class="text-xs sm:text-sm text-gray-500 font-medium">Pending</p>
            </div>
            <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-5 text-center">
                <p class="text-3xl font-bold text-red-500 mb-1">{{ summary.failedDonations }}</p>
                <p class="text-xs sm:text-sm text-gray-500 font-medium">Failed</p>
            </div>
        </div>

        <!-- Donations Table -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 bg-linear-to-r from-indigo-600 to-purple-600 text-white">
                <h2 class="text-lg sm:text-xl font-semibold">Donation History</h2>
            </div>

            <LoadingSpinner v-if="loading" text="Loading your donations..." />
            <template v-else>
                <AdminTable
                    :columns="columns"
                    :rows="donations"
                    row-key="id"
                    empty-text="No donations found"
                >
                    <template #cell-date="{ row }">
                        <span class="text-gray-600 text-xs">{{ row.date }}</span>
                    </template>
                    <template #cell-source="{ row }">
                        <AdminBadge
                            :variant="row.source === 'paypal' ? 'info' : 'success'"
                            :text="row.source === 'paypal' ? 'PayPal' : 'Stripe'"
                        />
                    </template>
                    <template #cell-amount="{ row }">
                        {{ (row.currency || 'USD').toUpperCase() }}
                        {{ Number(row.amount).toFixed(2) }}
                    </template>
                    <template #cell-status="{ row }">
                        <AdminBadge :variant="statusVariant(row.status)" :text="row.status" />
                    </template>
                    <template #cell-paymentId="{ row }">
                        <span class="text-xs text-gray-400 font-mono">{{
                            row.payment_id || '—'
                        }}</span>
                    </template>
                    <template #cell-article="{ row }">
                        <template v-if="row.article">
                            <NuxtLink
                                :to="row.formula_url || `/article?title=${encodeURIComponent(row.article.slug)}`"
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
                        Page {{ meta.currentPage }} of {{ meta.lastPage }} ({{ meta.total }} total)
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
import { userService } from '~/services/userService'
import { useToastify } from '~/composables/useToastify'
import DonationDetailModal from '~/components/admin/DonationDetailModal.vue'

const { notifyError } = useToastify()

useHead({ title: 'My Donations' })
definePageMeta({ middleware: 'auth' })

const loading = ref(true)
const donations = ref([])
const summary = ref({
    totalDonated: 0,
    totalDonations: 0,
    pendingDonations: 0,
    failedDonations: 0,
    bySource: [],
})
const meta = ref({ currentPage: 1, lastPage: 1, total: 0 })
const searchQuery = ref('')
const filterStatus = ref('')
const fromDate = ref('')
const toDate = ref('')

const selectedDonation = ref(null)
const selectedFormulaUrl = ref('')
const showDetail = ref(false)

const columns = [
    { key: 'date', label: 'Date' },
    { key: 'source', label: 'Source' },
    { key: 'amount', label: 'Amount' },
    { key: 'status', label: 'Status' },
    { key: 'paymentId', label: 'Payment ID' },
    { key: 'article', label: 'Article' },
    { key: 'action', label: 'Action' },
]

const usdFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
})

const formatAmount = (amount) => usdFormatter.format(amount || 0)

const statusVariants = {
    completed: 'success',
    succeeded: 'success',
    pending: 'warning',
}

const statusVariant = (status) => statusVariants[status] || 'danger'

function openDetail(row) {
    selectedDonation.value = {
        ...row,
        user: 'You',
        email: row.donor_email,
    }
    selectedFormulaUrl.value = row.formula_url || ''
    showDetail.value = true
}

const loadPage = async (page = 1) => {
    loading.value = true
    try {
        const params = { page, per_page: 15 }
        if (searchQuery.value.trim()) params.search = searchQuery.value.trim()
        if (filterStatus.value) params.status = filterStatus.value
        if (fromDate.value) params.from = fromDate.value
        if (toDate.value) params.to = toDate.value

        const res = await userService.getDonationReport(params)
        if (res.success) {
            donations.value = res.data.donations
            summary.value = res.data.summary
            meta.value = res.data.meta
        }
    } catch (error) {
        notifyError(error.errors?.[0] || 'Failed to load donation report')
    } finally {
        loading.value = false
    }
}

let searchTimeout = null
watch([searchQuery, filterStatus], () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => loadPage(1), 400)
})

onMounted(() => loadPage())
</script>