<template>
    <main class="w-full mt-6">
        <LoadingSpinner v-if="loading" text="Loading dashboard..." />

        <template v-else>
            <AdminPageHeader
                title="Admin Dashboard"
                subtitle="Overview of your WikiDonate platform"
            />

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <StatCard
                    :icon="['fas', 'users']"
                    icon-bg="indigo"
                    label="Total Users"
                    :value="stats.totalUsers"
                />
                <StatCard
                    :icon="['fas', 'newspaper']"
                    icon-bg="purple"
                    label="Total Articles"
                    :value="stats.totalArticles"
                />
                <StatCard
                    :icon="['fas', 'leaf']"
                    icon-bg="green"
                    label="Total Causes"
                    :value="stats.totalCauses"
                />
                <StatCard
                    :icon="['fas', 'building']"
                    icon-bg="amber"
                    label="Total NGOs"
                    :value="stats.totalNgos"
                />
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div
                    class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden"
                >
                    <div
                        class="bg-indigo-50 px-5 py-3 border-b border-gray-200"
                    >
                        <h3
                            class="font-semibold text-gray-800 flex items-center gap-2"
                        >
                            <font-awesome-icon
                                :icon="['fas', 'hand-holding-heart']"
                                class="w-4 h-4 text-indigo-600"
                            />
                            Recent Donations
                        </h3>
                    </div>
                    <AdminTable
                        :columns="donationColumns"
                        :rows="stats.recentDonations"
                        empty-text="No donations yet"
                    >
                        <template #cell-amount="{ row }">
                            {{ row.currency }}
                            {{ Number(row.amount).toFixed(2) }}
                        </template>
                        <template #cell-status="{ row }">
                            <AdminBadge
                                :variant="
                                    row.status === 'succeeded'
                                        ? 'success'
                                        : row.status === 'pending'
                                          ? 'warning'
                                          : 'danger'
                                "
                                :text="row.status"
                            />
                        </template>
                        <template #cell-date="{ row }">
                            <span class="text-gray-500">{{ row.date }}</span>
                        </template>
                    </AdminTable>
                </div>

                <div
                    class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden"
                >
                    <div
                        class="bg-indigo-50 px-5 py-3 border-b border-gray-200"
                    >
                        <h3
                            class="font-semibold text-gray-800 flex items-center gap-2"
                        >
                            <font-awesome-icon
                                :icon="['fas', 'user-plus']"
                                class="w-4 h-4 text-indigo-600"
                            />
                            Recent Users
                        </h3>
                    </div>
                    <AdminTable
                        :columns="userColumns"
                        :rows="stats.recentUsers"
                        empty-text="No users yet"
                    >
                        <template #cell-email="{ row }">
                            {{ row.email || '—' }}
                        </template>
                        <template #cell-joinedAt="{ row }">
                            <span class="text-gray-500">{{
                                row.joinedAt
                            }}</span>
                        </template>
                    </AdminTable>
                </div>
            </div>

            <div
                class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden mb-6"
            >
                <div class="bg-indigo-50 px-5 py-3 border-b border-gray-200">
                    <h3
                        class="font-semibold text-gray-800 flex items-center gap-2"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'chart-line']"
                            class="w-4 h-4 text-indigo-600"
                        />
                        Monthly Trends (Last 6 Months)
                    </h3>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-4 sm:p-6">
                    <div>
                        <h4
                            class="text-sm font-semibold text-gray-600 mb-3 flex items-center gap-2"
                        >
                            <font-awesome-icon
                                :icon="['fas', 'circle-dollar']"
                                class="w-3.5 h-3.5 text-green-500"
                            />
                            Donations
                        </h4>
                        <div class="space-y-2">
                            <div
                                v-for="(row, i) in stats.monthlyStats.donations"
                                :key="i"
                                class="flex items-center gap-3"
                            >
                                <span class="text-sm text-gray-500 w-16">{{
                                    row.month
                                }}</span>
                                <div
                                    class="flex-1 bg-gray-100 rounded-full h-2"
                                >
                                    <div
                                        class="bg-gradient-to-r from-indigo-500 to-purple-500 h-2 rounded-full"
                                        :style="{
                                            width: donationBarWidth(row),
                                        }"
                                    ></div>
                                </div>
                                <span
                                    class="text-sm font-semibold text-gray-700 w-20 text-right"
                                >
                                    {{ row.currency || 'USD' }}
                                    {{ Number(row.total).toFixed(0) }}
                                </span>
                            </div>
                            <p
                                v-if="stats.monthlyStats.donations.length === 0"
                                class="text-sm text-gray-400 text-center py-4"
                            >
                                No donation data yet
                            </p>
                        </div>
                    </div>

                    <div>
                        <h4
                            class="text-sm font-semibold text-gray-600 mb-3 flex items-center gap-2"
                        >
                            <font-awesome-icon
                                :icon="['fas', 'user-plus']"
                                class="w-3.5 h-3.5 text-indigo-500"
                            />
                            New Users
                        </h4>
                        <div class="space-y-2">
                            <div
                                v-for="(row, i) in stats.monthlyStats
                                    .registrations"
                                :key="i"
                                class="flex items-center gap-3"
                            >
                                <span class="text-sm text-gray-500 w-16">{{
                                    row.month
                                }}</span>
                                <div
                                    class="flex-1 bg-gray-100 rounded-full h-2"
                                >
                                    <div
                                        class="bg-gradient-to-r from-teal-400 to-emerald-500 h-2 rounded-full"
                                        :style="{
                                            width: registrationBarWidth(row),
                                        }"
                                    ></div>
                                </div>
                                <span
                                    class="text-sm font-semibold text-gray-700 w-20 text-right"
                                >
                                    {{ row.total }}
                                </span>
                            </div>
                            <p
                                v-if="
                                    stats.monthlyStats.registrations.length ===
                                    0
                                "
                                class="text-sm text-gray-400 text-center py-4"
                            >
                                No registration data yet
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </main>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { adminService } from '~/services/adminService'
import { useToastify } from '~/composables/useToastify'

const { notifyError } = useToastify()

useHead({ title: 'Admin Dashboard' })
definePageMeta({ layout: 'admin', middleware: ['auth', 'admin'] })

const loading = ref(true)
const stats = ref({
    totalUsers: 0,
    totalArticles: 0,
    totalCauses: 0,
    totalNgos: 0,
    recentDonations: [],
    recentUsers: [],
    monthlyStats: { donations: [], registrations: [] },
})

const donationColumns = [
    { key: 'user', label: 'User' },
    { key: 'amount', label: 'Amount' },
    { key: 'status', label: 'Status' },
    { key: 'date', label: 'Date' },
]

const userColumns = [
    { key: 'username', label: 'Username', tdClass: 'font-medium' },
    { key: 'email', label: 'Email' },
    { key: 'joinedAt', label: 'Joined' },
]

const donationBarWidth = (row) => {
    const all = stats.value.monthlyStats.donations
    const max = Math.max(...all.map((r) => Number(r.total)), 1)
    return `${(Number(row.total) / max) * 100}%`
}

const registrationBarWidth = (row) => {
    const all = stats.value.monthlyStats.registrations
    const max = Math.max(...all.map((r) => Number(r.total)), 1)
    return `${(Number(row.total) / max) * 100}%`
}

onMounted(async () => {
    try {
        const response = await adminService.getDashboard()
        if (response.success) {
            stats.value = response.data
        }
    } catch {
        notifyError('Failed to load dashboard data')
    } finally {
        loading.value = false
    }
})
</script>
