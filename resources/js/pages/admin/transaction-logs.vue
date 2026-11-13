<template>
    <main>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <AdminPageHeader
                :title="t('admin.transactionLogs')"
                :subtitle="t('admin.transactionLogsSubtitle')"
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

            <div class="px-4 sm:px-6 py-3 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row gap-3">
                    <AdminSearchInput
                        v-model="query"
                        :placeholder="t('admin.searchLogsPlaceholder')"
                        @enter="loadPage(1)"
                        @clear="loadPage(1)"
                    />
                    <select
                        v-model="categoryFilter"
                        class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    >
                        <option value="">{{ t('admin.allCategories') }}</option>
                        <option v-for="cat in categories" :key="cat" :value="cat">
                            {{ categoryLabel(cat) }}
                        </option>
                    </select>
                    <select
                        v-model="eventFilter"
                        class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                    >
                        <option value="">{{ t('admin.allEvents') }}</option>
                        <option v-for="ev in events" :key="ev" :value="ev">{{ ev }}</option>
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
                            <span class="text-gray-500 text-xs">{{
                                formatDateUTC(row.created_at)
                            }}</span>
                        </template>
                        <template #cell-event="{ row }">
                            <span
                                class="text-xs font-mono px-2 py-0.5 rounded bg-gray-100 text-gray-700"
                            >
                                {{ row.event }}
                            </span>
                        </template>
                        <template #cell-message="{ row }">
                            <span class="text-xs text-gray-600">{{ row.message || '—' }}</span>
                        </template>
                        <template #cell-subject="{ row }">
                            <span class="text-xs text-gray-500"
                                >{{ row.subject_type }} #{{ row.subject_id }}</span
                            >
                        </template>
                        <template #cell-actor="{ row }">
                            <span class="text-sm text-gray-600">{{
                                row.actor?.username || row.actor_role || '—'
                            }}</span>
                        </template>
                        <template #cell-changes="{ row }">
                            <button
                                v-if="row.before || row.after"
                                class="text-xs font-medium text-indigo-600 hover:underline"
                                @click="openChanges(row)"
                            >
                                {{ t('common.view') }}
                            </button>
                            <span v-else class="text-xs text-gray-300">—</span>
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

        <AdminModal
            :model-value="!!changesTarget"
            :title="changesTarget?.event ?? t('admin.changes')"
            size="lg"
            @close="changesTarget = null"
        >
            <div v-if="changesTarget" class="space-y-4">
                <div v-if="changesTarget.before">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-xs font-semibold text-red-600">− {{ t('admin.before') }}</p>
                        <button
                            class="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-indigo-600"
                            @click="copyChanges('before', changesTarget.before)"
                        >
                            <font-awesome-icon :icon="['fas', 'copy']" class="w-3 h-3" />
                            {{ copiedKey === 'before' ? t('common.copied') : t('common.copy') }}
                        </button>
                    </div>
                    <pre
                        class="text-xs bg-gray-50 border border-gray-200 rounded-lg p-3 max-h-64 overflow-auto whitespace-pre-wrap break-words"
                        >{{ pretty(changesTarget.before) }}</pre>
                </div>
                <div v-if="changesTarget.after">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-xs font-semibold text-green-600">+ {{ t('admin.after') }}</p>
                        <button
                            class="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-indigo-600"
                            @click="copyChanges('after', changesTarget.after)"
                        >
                            <font-awesome-icon :icon="['fas', 'copy']" class="w-3 h-3" />
                            {{ copiedKey === 'after' ? t('common.copied') : t('common.copy') }}
                        </button>
                    </div>
                    <pre
                        class="text-xs bg-gray-50 border border-gray-200 rounded-lg p-3 max-h-64 overflow-auto whitespace-pre-wrap break-words"
                        >{{ pretty(changesTarget.after) }}</pre>
                </div>
            </div>
            <template #footer>
                <button
                    class="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50"
                    @click="changesTarget = null"
                >
                    {{ t('admin.close') }}
                </button>
            </template>
        </AdminModal>
    </main>
</template>

<script setup>
    import { ref, onMounted } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { adminService } from '~/services/adminService'
    import { useToastify } from '~/composables/useToastify'
    import { formatDateUTC } from '~/utils/dateFormatUTC'
    import api from '~/config/apiConfig'
    import AdminPageHeader from '~/components/admin/AdminPageHeader.vue'
    import AdminTable from '~/components/admin/AdminTable.vue'
    import AdminSearchInput from '~/components/admin/AdminSearchInput.vue'
    import AdminModal from '~/components/admin/AdminModal.vue'
    import LoadingSpinner from '~/components/ui/LoadingSpinner.vue'
    import Pagination from '~/components/ui/Pagination.vue'
    import Button from '~/components/ui/Button.vue'

    const { notifyError, notifySuccess } = useToastify()
    const { t, te } = useI18n()
    definePageMeta({ layout: 'admin', middleware: ['auth', 'admin'] })

    const loading = ref(true)
    const exporting = ref(false)
    const logs = ref([])
    const meta = ref({ currentPage: 1, lastPage: 1, total: 0 })
    const events = ref([])
    const categories = ref([])

    const query = ref('')
    const categoryFilter = ref('')
    const eventFilter = ref('')
    const changesTarget = ref(null)
    const copiedKey = ref(null)

    const openChanges = (row) => {
        changesTarget.value = row
        copiedKey.value = null
    }

    const pretty = (value) => JSON.stringify(value, null, 2)

    const copyChanges = async (key, value) => {
        try {
            await navigator.clipboard.writeText(pretty(value))
            copiedKey.value = key
            notifySuccess(t('common.copied'))
            setTimeout(() => {
                if (copiedKey.value === key) {
                    copiedKey.value = null
                }
            }, 1500)
        } catch {
            notifyError(t('admin.copyFailed'))
        }
    }

    // Translated label when a category key exists; raw value otherwise (new
    // event namespaces appear without a frontend change).
    const categoryLabel = (value) => {
        const key = `admin.category${value.charAt(0).toUpperCase()}${value.slice(1)}`
        return te(key) ? t(key) : value
    }

    const columns = [
        {
            key: 'created_at',
            label: t('admin.date'),
            thClass: 'whitespace-nowrap',
            tdClass: 'whitespace-nowrap',
        },
        { key: 'event', label: t('admin.event') },
        { key: 'message', label: t('admin.message') },
        { key: 'subject', label: t('admin.subject') },
        { key: 'actor', label: t('admin.actor') },
        { key: 'changes', label: t('admin.changes') },
    ]

    const filterParams = () => {
        const params = {}
        if (query.value.trim()) params.search = query.value.trim()
        if (categoryFilter.value) params.category = categoryFilter.value
        if (eventFilter.value) params.event = eventFilter.value
        return params
    }

    const loadPage = async (page = 1) => {
        loading.value = true
        try {
            const res = await adminService.getTransactionLogs({
                page,
                per_page: 15,
                ...filterParams(),
            })
            if (res.success) {
                logs.value = res.data
                meta.value = res.meta
            }
        } catch (error) {
            notifyError(error.errors?.[0] || t('admin.failedToLoadLogs'))
        } finally {
            loading.value = false
        }
    }

    const loadFilters = async () => {
        try {
            const res = await api.get('/admin/transaction-logs/filters')
            if (res.success) {
                events.value = res.data.events ?? []
                categories.value = res.data.categories ?? []
            }
        } catch {
            // Filters are a convenience; the list still works without them.
        }
    }

    const exportCsv = async () => {
        exporting.value = true
        try {
            const res = await api.get('/admin/transaction-logs/export', {
                params: filterParams(),
                responseType: 'blob',
            })
            const blob = res instanceof Blob ? res : new Blob([res], { type: 'text/csv' })
            const url = URL.createObjectURL(blob)
            const a = document.createElement('a')
            a.href = url
            a.download = `transaction-log-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '')}.csv`
            document.body.appendChild(a)
            a.click()
            a.remove()
            URL.revokeObjectURL(url)
        } catch (err) {
            notifyError(err?.message || t('admin.exportFailed'))
        } finally {
            exporting.value = false
        }
    }

    onMounted(() => {
        loadFilters()
        loadPage(1)
    })
</script>
