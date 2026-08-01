<template>
    <main>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <AdminPageHeader
                title="All Donations"
                subtitle="View and search all donation records"
                :card="true"
            />

            <!-- Filters -->
            <div class="px-4 sm:px-6 py-3 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="relative flex-1">
                        <font-awesome-icon :icon="['fas', 'search']" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search by donor, email, or payment ID..."
                            class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                        />
                    </div>
                    <div class="flex gap-3 sm:w-auto w-full">
                        <select v-model="filterStatus" class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white">
                            <option value="">All Status</option>
                            <option value="completed">Completed</option>
                            <option value="expired">Expired</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>
                </div>
            </div>

            <LoadingSpinner v-if="loading" text="Loading donations..." />
            <template v-else>
                <AdminTable :columns="columns" :rows="donations" row-key="id" empty-text="No donations found">
                    <template #cell-source>
                        <AdminBadge variant="info" text="Checkout" />
                    </template>
                    <template #cell-donor="{ row }">
                        <div>
                            <span class="font-medium text-sm">{{ row.user }}</span>
                            <span v-if="row.email" class="block text-xs text-gray-400">{{ row.email }}</span>
                        </div>
                    </template>
                    <template #cell-amount="{ row }">{{ row.currency || 'USD' }} {{ Number(row.amount).toFixed(2) }}</template>
                    <template #cell-status="{ row }">
                        <AdminBadge :variant="statusVariant(row.status)" :text="row.status" />
                    </template>
                    <template #cell-date="{ row }"><span class="text-gray-500 text-xs">{{ row.date }}</span></template>
                    <template #cell-paymentId="{ row }">
                        <span class="text-xs text-gray-400 font-mono">{{ row.payment_id || row.stripe_session_id || '—' }}</span>
                    </template>
                    <template #cell-action="{ row }">
                        <button class="text-indigo-600 hover:text-indigo-800 font-medium text-xs" @click="openDetail(row)">View</button>
                    </template>
                </AdminTable>

                <div v-if="meta.lastPage > 1" class="px-4 py-3 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-2">
                    <span class="text-sm text-gray-500 order-2 sm:order-1">
                        Page {{ meta.currentPage }} of {{ meta.lastPage }} ({{ meta.total }} total)
                    </span>
                    <Pagination :current-page="meta.currentPage" :total-pages="meta.lastPage" @page-change="loadPage" />
                </div>
            </template>
        </div>

        <DonationDetailModal
            v-model="showDetail"
            :donation="selectedDonation"
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
const showDetail = ref(false)

function openDetail(row) {
    selectedDonation.value = row
    showDetail.value = true
}

useHead({ title: 'Admin - Donations' })
definePageMeta({ layout: 'admin', middleware: ['auth', 'admin'] })

const donations = ref([])
const meta = ref({ currentPage: 1, lastPage: 1, total: 0 })
const loading = ref(true)
const searchQuery = ref('')
const filterStatus = ref('')
let searchTimeout = null

const columns = [
    { key: 'date', label: 'Date' },
    { key: 'donor', label: 'Donor' },
    { key: 'source', label: 'Source' },
    { key: 'amount', label: 'Amount' },
    { key: 'status', label: 'Status' },
    { key: 'paymentId', label: 'Payment ID' },
    { key: 'action', label: 'Action' },
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
        notifyError(error.errors?.[0] || 'Failed to load donations')
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
