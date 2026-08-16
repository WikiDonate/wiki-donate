<template>
    <main class="w-full mx-auto max-w-4xl px-2 sm:px-4 lg:px-6 py-8">
        <h1
            class="text-3xl sm:text-4xl font-bold text-center bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-purple-600 mb-8"
        >
            Donation Report
        </h1>

        <!-- Filters -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden mb-6">
            <div class="px-6 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white">
                <h2 class="text-lg sm:text-xl font-semibold">Filters</h2>
            </div>
            <div class="p-6 flex flex-col sm:flex-row items-end gap-4">
                <div class="flex-1 w-full sm:w-auto">
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                    <input
                        v-model="fromDate"
                        type="date"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    />
                </div>
                <div class="flex-1 w-full sm:w-auto">
                    <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                    <input
                        v-model="toDate"
                        type="date"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    />
                </div>
                <Button variant="primary" text="Apply" width="auto" @click="applyFilters" />
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
            <LoadingSpinner text="Loading your donation report..." />
        </div>

        <template v-else>
            <!-- Summary Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-5 text-center">
                    <p class="text-3xl font-bold text-indigo-600 mb-1">
                        {{ formatAmount(summary.totalDonated) }}
                    </p>
                    <p class="text-xs sm:text-sm text-gray-500 font-medium">Total Donated</p>
                </div>
                <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-5 text-center">
                    <p class="text-3xl font-bold text-green-600 mb-1">
                        {{ summary.totalDonations }}
                    </p>
                    <p class="text-xs sm:text-sm text-gray-500 font-medium">Completed</p>
                </div>
                <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-5 text-center">
                    <p class="text-3xl font-bold text-amber-500 mb-1">
                        {{ summary.pendingDonations }}
                    </p>
                    <p class="text-xs sm:text-sm text-gray-500 font-medium">Pending</p>
                </div>
                <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-5 text-center">
                    <p class="text-3xl font-bold text-red-500 mb-1">
                        {{ summary.failedDonations }}
                    </p>
                    <p class="text-xs sm:text-sm text-gray-500 font-medium">Failed</p>
                </div>
            </div>

            <!-- Donations Table -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white">
                    <h2 class="text-lg sm:text-xl font-semibold">Donation History</h2>
                </div>

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
            </div>
        </template>
    </main>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { userService } from '~/services/userService'
import { useToastify } from '~/composables/useToastify'

const { notifyError } = useToastify()

useHead({ title: 'Donation Report' })
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
const fromDate = ref('')
const toDate = ref('')

const columns = [
    { key: 'date', label: 'Date' },
    { key: 'source', label: 'Source' },
    { key: 'amount', label: 'Amount' },
    { key: 'status', label: 'Status' },
    { key: 'paymentId', label: 'Payment ID' },
]

const formatAmount = (amount) =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount || 0)

const statusVariant = (status) => {
    if (status === 'completed' || status === 'succeeded') return 'success'
    if (status === 'pending') return 'warning'
    return 'danger'
}

const loadPage = async (page = 1) => {
    loading.value = true
    try {
        const params = { page, per_page: 15 }
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

const applyFilters = () => loadPage(1)

onMounted(() => loadPage())
</script>
