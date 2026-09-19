<template>
    <main>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <AdminPageHeader
                :title="t('admin.dashboard')"
                :subtitle="t('admin.dashboardSubtitle')"
                :card="true"
            />

            <LoadingSpinner v-if="loading" :text="t('admin.loadingDashboard')" />

            <template v-else>
                <!-- Stat Cards -->
                <div class="px-4 sm:px-6 py-4">
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <StatCard
                            :icon="['fas', 'users']"
                            icon-bg="indigo"
                            :label="t('admin.totalUsers')"
                            :value="stats.totalUsers"
                        />
                        <StatCard
                            :icon="['fas', 'newspaper']"
                            icon-bg="purple"
                            :label="t('admin.totalArticles')"
                            :value="stats.totalArticles"
                        />
                        <StatCard
                            :icon="['fas', 'coins']"
                            icon-bg="green"
                            :label="t('admin.totalDonations')"
                            :value="stats.monthlyStats.donations.length"
                        />
                        <StatCard
                            :icon="['fas', 'user-plus']"
                            icon-bg="amber"
                            :label="t('admin.newThisMonth')"
                            :value="recentRegistrationsCount"
                        />
                    </div>
                </div>

                <!-- Monthly Trends -->
                <div
                    class="mx-4 sm:mx-6 my-4 bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-6"
                >
                    <h3
                        class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2 mb-4"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'chart-line']"
                            class="w-4 h-4 text-indigo-600"
                        />
                        {{ t('admin.monthlyTrends') }}
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h4
                                class="text-xs font-semibold text-gray-500 mb-3 flex items-center gap-2"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'coins']"
                                    class="w-3 h-3 text-green-500"
                                />
                                {{ t('admin.donations') }}
                            </h4>
                            <div class="space-y-2">
                                <div
                                    v-for="(row, i) in stats.monthlyStats.donations"
                                    :key="i"
                                    class="flex items-center gap-3"
                                >
                                    <span class="text-xs text-gray-500 w-14 text-right">{{
                                        row.month
                                    }}</span>
                                    <div class="flex-1 bg-gray-100 rounded-full h-2">
                                        <div
                                            class="bg-linear-to-r from-indigo-500 to-purple-500 h-2 rounded-full"
                                            :style="{
                                                width: donationBarWidth(row),
                                            }"
                                        />
                                    </div>
                                    <span
                                        class="text-xs font-semibold text-gray-700 w-14 text-right"
                                        >{{ row.currency || 'USD' }}
                                        {{ Number(row.total).toFixed(0) }}</span
                                    >
                                </div>
                                <p
                                    v-if="stats.monthlyStats.donations.length === 0"
                                    class="text-xs text-gray-400 text-center py-3"
                                >
                                    {{ t('admin.noDonationData') }}
                                </p>
                            </div>
                        </div>
                        <div>
                            <h4
                                class="text-xs font-semibold text-gray-500 mb-3 flex items-center gap-2"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'user-plus']"
                                    class="w-3 h-3 text-indigo-500"
                                />
                                {{ t('admin.newUsers') }}
                            </h4>
                            <div class="space-y-2">
                                <div
                                    v-for="(row, i) in stats.monthlyStats.registrations"
                                    :key="i"
                                    class="flex items-center gap-3"
                                >
                                    <span class="text-xs text-gray-500 w-14 text-right">{{
                                        row.month
                                    }}</span>
                                    <div class="flex-1 bg-gray-100 rounded-full h-2">
                                        <div
                                            class="bg-linear-to-r from-teal-400 to-emerald-500 h-2 rounded-full"
                                            :style="{
                                                width: registrationBarWidth(row),
                                            }"
                                        />
                                    </div>
                                    <span
                                        class="text-xs font-semibold text-gray-700 w-14 text-right"
                                        >{{ row.total }}</span
                                    >
                                </div>
                                <p
                                    v-if="stats.monthlyStats.registrations.length === 0"
                                    class="text-xs text-gray-400 text-center py-3"
                                >
                                    {{ t('admin.noRegistrationData') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 px-4 sm:px-6 py-4">
                    <div
                        class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden"
                    >
                        <div
                            class="px-4 py-3 border-b border-gray-100 flex items-center justify-between"
                        >
                            <h3
                                class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'hand-holding-heart']"
                                    class="w-3.5 h-3.5 text-indigo-600"
                                />
                                {{ t('admin.recentDonations') }}
                            </h3>
                            <NuxtLink
                                to="/admin/donations"
                                class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                                >{{ t('admin.viewAll') }}</NuxtLink
                            >
                        </div>
                        <AdminTable
                            :columns="donationColumns"
                            :rows="stats.recentDonations"
                            empty-text="admin.noDonationsYet"
                        >
                            <template #cell-source>
                                <AdminBadge variant="info" :text="t('admin.checkout')" />
                            </template>
                            <template #cell-amount="{ row }"
                                >{{ row.currency }} {{ Number(row.amount).toFixed(2) }}</template
                            >
                            <template #cell-status="{ row }">
                                <AdminBadge
                                    :variant="statusVariant(row.status)"
                                    :text="row.status"
                                />
                            </template>
                            <template #cell-date="{ row }"
                                ><span class="text-gray-500 text-xs">{{ row.date }}</span></template
                            >
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
                                    @click="openDonationDetail(row)"
                                >
                                    {{ t('admin.view') }}
                                </button>
                            </template>
                        </AdminTable>
                    </div>
                    <div
                        class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden"
                    >
                        <div class="px-4 py-3 border-b border-gray-100">
                            <h3
                                class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'user-plus']"
                                    class="w-3.5 h-3.5 text-indigo-600"
                                />
                                {{ t('admin.recentUsers') }}
                            </h3>
                        </div>
                        <AdminTable
                            :columns="userColumns"
                            :rows="stats.recentUsers"
                            empty-text="admin.noUsersYet"
                        >
                            <template #cell-email="{ row }">{{ row.email || '—' }}</template>
                            <template #cell-joinedAt="{ row }"
                                ><span class="text-gray-500 text-xs">{{
                                    row.joinedAt
                                }}</span></template
                            >
                        </AdminTable>
                    </div>
                </div>
            </template>
        </div>

        <DonationDetailModal
            v-model="showDonationDetail"
            :donation="selectedDonation"
            :formula-url="selectedFormulaUrl"
        />
    </main>
</template>

<script setup>
    import { ref, onMounted, computed } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { adminService } from '~/services/adminService'
    import { useToastify } from '~/composables/useToastify'
    import DonationDetailModal from '~/components/admin/DonationDetailModal.vue'
    import StatCard from '~/components/admin/StatCard.vue'
    import AdminPageHeader from '~/components/admin/AdminPageHeader.vue'
    import AdminTable from '~/components/admin/AdminTable.vue'

    const { t } = useI18n()
    const { notifyError } = useToastify()

    const selectedDonation = ref(null)
    const selectedFormulaUrl = ref('')
    const showDonationDetail = ref(false)

    function statusVariant(status) {
        if (status === 'completed' || status === 'succeeded') return 'success'
        if (status === 'pending') return 'warning'
        return 'danger'
    }

    function openDonationDetail(row) {
        selectedDonation.value = row
        selectedFormulaUrl.value = row.formula_url || ''
        showDonationDetail.value = true
    }

    useHead({ title: t('admin.dashboard') })
    definePageMeta({ layout: 'admin', middleware: ['auth', 'admin'] })

    const loading = ref(true)
    const stats = ref({
        totalUsers: 0,
        totalArticles: 0,
        recentDonations: [],
        recentUsers: [],
        monthlyStats: { donations: [], registrations: [] },
    })

    const donationColumns = [
        { key: 'date', label: t('admin.date') },
        { key: 'user', label: t('admin.donor') },
        { key: 'source', label: t('admin.source') },
        { key: 'amount', label: t('admin.amount') },
        { key: 'status', label: t('admin.status') },
        { key: 'article', label: t('admin.article') },
        { key: 'action', label: t('admin.action') },
    ]

    const recentRegistrationsCount = computed(() => {
        const regs = stats.value.monthlyStats.registrations
        return regs.length > 0 ? Number(regs[0].total) : 0
    })

    const userColumns = [
        { key: 'username', label: t('admin.username'), tdClass: 'font-medium' },
        { key: 'email', label: t('admin.email') },
        { key: 'joinedAt', label: t('admin.joined') },
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
            notifyError(t('admin.failedToLoadDashboard'))
        } finally {
            loading.value = false
        }
    })
</script>
