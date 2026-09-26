<template>
    <main>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <AdminPageHeader
                :title="t('admin.transactionLogs')"
                :subtitle="t('admin.transactionLogsSubtitle')"
                :card="true"
            />

            <div class="px-4 sm:px-6 py-3 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row gap-3">
                    <input
                        v-model="query"
                        type="text"
                        :placeholder="t('admin.searchLogsPlaceholder')"
                        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                        @keyup.enter="loadPage(1)"
                    />
                    <select
                        v-model="categoryFilter"
                        class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    >
                        <option value="">{{ t('admin.allCategories') }}</option>
                        <option value="organization">{{ t('admin.categoryOrganization') }}</option>
                        <option value="payout">{{ t('admin.categoryPayout') }}</option>
                        <option value="donation">{{ t('admin.categoryDonation') }}</option>
                    </select>
                    <select
                        v-model="eventFilter"
                        class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    >
                        <option value="">{{ t('admin.allEvents') }}</option>
                        <option value="organization.created">organization.created</option>
                        <option value="organization.updated">organization.updated</option>
                        <option value="organization.verified">organization.verified</option>
                        <option value="organization.verification_reset">
                            organization.verification_reset
                        </option>
                        <option value="payout.created">payout.created</option>
                        <option value="payout.blocked">payout.blocked</option>
                    </select>
                    <Button
                        variant="primary"
                        :text="t('common.apply')"
                        width="auto"
                        @click="loadPage(1)"
                    />
                </div>
            </div>

            <LoadingSpinner v-if="loading" :text="t('admin.loadingLogs')" />
            <template v-else>
                <div class="px-4 sm:px-6 py-4">
                    <AdminTable :columns="columns" :rows="logs" row-key="id">
                        <template #cell-created_at="{ row }">
                            <span class="text-gray-500 text-xs">{{ row.created_at }}</span>
                        </template>
                        <template #cell-event="{ row }">
                            <div>
                                <span
                                    class="text-xs font-mono px-2 py-0.5 rounded bg-gray-100 text-gray-700"
                                >
                                    {{ row.event }}
                                </span>
                                <span class="block text-xs text-gray-400 mt-0.5 capitalize">{{
                                    row.category
                                }}</span>
                            </div>
                        </template>
                        <template #cell-subject="{ row }">
                            <span class="text-xs text-gray-500"
                                >{{ row.subject_type }} #{{ row.subject_id }}</span
                            >
                        </template>
                        <template #cell-actor="{ row }">
                            <span class="text-sm text-gray-600">{{ row.actor || '—' }}</span>
                        </template>
                        <template #cell-changes="{ row }">
                            <div class="text-xs space-y-0.5">
                                <div v-if="row.before" class="text-red-600">
                                    − {{ JSON.stringify(row.before) }}
                                </div>
                                <div v-if="row.after" class="text-green-600">
                                    + {{ JSON.stringify(row.after) }}
                                </div>
                            </div>
                        </template>
                        <template #cell-note="{ row }">
                            <span class="text-xs text-gray-500">{{ row.note || '—' }}</span>
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
    import { useToastify } from '~/composables/useToastify'
    import AdminPageHeader from '~/components/admin/AdminPageHeader.vue'
    import AdminTable from '~/components/admin/AdminTable.vue'
    import LoadingSpinner from '~/components/LoadingSpinner.vue'
    import Pagination from '~/components/Pagination.vue'
    import Button from '~/components/Button.vue'

    const { notifyError } = useToastify()
    const { t } = useI18n()

    useHead({ title: t('admin.transactionLogs') })
    definePageMeta({ layout: 'admin', middleware: ['auth', 'admin'] })

    const loading = ref(true)
    const logs = ref([])
    const meta = ref({ currentPage: 1, lastPage: 1, total: 0 })

    const query = ref('')
    const categoryFilter = ref('')
    const eventFilter = ref('')

    const columns = [
        { key: 'created_at', label: t('admin.date') },
        { key: 'event', label: t('admin.event') },
        { key: 'subject', label: t('admin.subject') },
        { key: 'actor', label: t('admin.actor') },
        { key: 'changes', label: t('admin.changes') },
        { key: 'note', label: t('admin.note') },
    ]

    const loadPage = async (page = 1) => {
        loading.value = true
        try {
            const params = { page, per_page: 20 }
            if (query.value.trim()) params.q = query.value.trim()
            if (categoryFilter.value) params.category = categoryFilter.value
            if (eventFilter.value) params.event = eventFilter.value

            const res = await adminService.getTransactionLogs(params)
            if (res.success) {
                logs.value = res.data.data
                meta.value = res.data
            }
        } catch (error) {
            notifyError(error.errors?.[0] || t('admin.failedToLoadLogs'))
        } finally {
            loading.value = false
        }
    }

    onMounted(() => loadPage(1))
</script>
