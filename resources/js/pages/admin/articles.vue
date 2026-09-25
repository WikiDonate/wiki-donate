<template>
    <main>
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <AdminPageHeader
                :title="t('admin.articles')"
                :subtitle="t('admin.articlesSubtitle')"
                :card="true"
            />

            <div class="px-4 py-3 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="relative flex-1">
                        <font-awesome-icon
                            :icon="['fas', 'search']"
                            class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"
                        />
                        <input
                            v-model="searchQuery"
                            type="text"
                            :placeholder="t('admin.searchArticlesPlaceholder')"
                            class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                        />
                    </div>
                    <div class="flex gap-3 sm:w-auto w-full">
                        <select
                            v-model="filterType"
                            class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white"
                        >
                            <option value="">{{ t('admin.allTypes') }}</option>
                            <option value="article">{{ t('admin.article') }}</option>
                            <option value="user page">{{ t('admin.userPage') }}</option>
                        </select>
                        <select
                            v-model="filterAccess"
                            class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white"
                        >
                            <option value="">{{ t('admin.allAccess') }}</option>
                            <option value="public">{{ t('admin.public') }}</option>
                            <option value="private">{{ t('admin.private') }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <LoadingSpinner v-if="loading" :text="t('admin.loadingArticles')" />
            <template v-else>
                <AdminTable
                    :columns="columns"
                    :rows="articles"
                    row-key="uuid"
                    :empty-text="t('admin.noArticlesFound')"
                >
                    <template #cell-title="{ row }">
                        <span class="font-medium max-w-xs truncate block">{{ row.title }}</span>
                    </template>
                    <template #cell-author="{ row }">
                        <span class="text-gray-600">{{ row.user?.username || '—' }}</span>
                    </template>
                    <template #cell-type="{ row }">
                        <AdminBadge
                            :variant="row.type === 'user page' ? 'purple' : 'info'"
                            :text="row.type || 'article'"
                        />
                    </template>
                    <template #cell-accessType="{ row }">
                        <AdminBadge
                            :variant="row.accessType === 'public' ? 'success' : 'amber'"
                            :text="row.accessType"
                        />
                    </template>
                    <template #cell-updatedAt="{ row }">
                        <span class="text-gray-500">{{ row.updatedAt }}</span>
                    </template>
                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <NuxtLink
                                :to="'/article?title=' + encodeURIComponent(row.slug)"
                                target="_blank"
                                class="p-1.5 text-gray-400 hover:text-indigo-600 rounded-md hover:bg-indigo-50 transition-colors"
                                :title="t('admin.viewArticle')"
                            >
                                <font-awesome-icon :icon="['fas', 'eye']" class="w-4 h-4" />
                            </NuxtLink>
                            <button
                                class="p-1.5 text-gray-400 hover:text-red-600 rounded-md hover:bg-red-50 transition-colors"
                                :title="t('admin.deleteArticle')"
                                @click="confirmDelete(row)"
                            >
                                <font-awesome-icon :icon="['fas', 'trash']" class="w-4 h-4" />
                            </button>
                        </div>
                    </template>
                </AdminTable>

                <div
                    v-if="meta.lastPage > 1"
                    class="px-4 py-3 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-2"
                >
                    <span class="text-sm text-gray-500 order-2 sm:order-1">
                        Page {{ meta.currentPage }} of {{ meta.lastPage }} ({{ meta.total }}
                        total)
                    </span>
                    <Pagination
                        :current-page="meta.currentPage"
                        :total-pages="meta.lastPage"
                        @page-change="loadPage"
                    />
                </div>
            </template>
        </div>

        <ConfirmModal
            v-model="showDeleteModal"
            :title="t('admin.deleteArticleTitle')"
            :message-title="t('admin.areYouSure')"
            :message="t('admin.deleteArticleMsg', { title: deleteTarget?.title })"
            :confirm-text="t('common.delete')"
            :cancel-text="t('common.cancel')"
            :is-loading="deleting"
            @confirm="handleDelete"
        />
    </main>
</template>

<script setup>
    import { ref, onMounted, watch } from 'vue'
    import { useI18n } from 'vue-i18n'
    import { adminService } from '~/services/adminService'
    import { useToastify } from '~/composables/useToastify'

    const { notifySuccess, notifyError } = useToastify()

    const { t } = useI18n()

    useHead({ title: t('admin.articles') })
    definePageMeta({ layout: 'admin', middleware: ['auth', 'admin'] })

    const articles = ref([])
    const meta = ref({ currentPage: 1, lastPage: 1, total: 0, perPage: 15 })
    const loading = ref(true)
    const showDeleteModal = ref(false)
    const deleteTarget = ref(null)
    const deleting = ref(false)
    const searchQuery = ref('')
    const filterType = ref('')
    const filterAccess = ref('')
    let searchTimeout = null

    const columns = [
        { key: 'title', label: t('admin.title') },
        { key: 'author', label: t('admin.author') },
        { key: 'type', label: t('admin.type') },
        { key: 'accessType', label: t('admin.access') },
        { key: 'updatedAt', label: t('admin.updated') },
        {
            key: 'actions',
            label: t('admin.actions'),
            thClass: 'text-right',
            tdClass: 'text-right',
        },
    ]

    const loadPage = async (page = 1) => {
        loading.value = true
        try {
            const params = { page }
            if (searchQuery.value.trim()) {
                params.search = searchQuery.value.trim()
            }
            if (filterType.value) {
                params.type = filterType.value
            }
            if (filterAccess.value) {
                params.access_type = filterAccess.value
            }
            const res = await adminService.getArticles(params)
            if (res.success) {
                articles.value = res.data
                meta.value = res.meta
            }
        } catch (error) {
            notifyError(error.errors?.[0] || t('admin.failedToLoadArticles'))
        } finally {
            loading.value = false
        }
    }

    const confirmDelete = (article) => {
        deleteTarget.value = article
        showDeleteModal.value = true
    }

    const handleDelete = async () => {
        if (deleting.value) return
        deleting.value = true
        try {
            const res = await adminService.deleteArticle(deleteTarget.value.slug)
            if (res.success) {
                notifySuccess(t('admin.articleDeleted'))
                showDeleteModal.value = false
                deleteTarget.value = null
                await loadPage(meta.value.currentPage)
            }
        } catch (error) {
            notifyError(error.errors?.[0] || t('admin.failedToDeleteArticle'))
        } finally {
            deleting.value = false
        }
    }

    watch([searchQuery, filterType, filterAccess], () => {
        clearTimeout(searchTimeout)
        searchTimeout = setTimeout(
            () => {
                loadPage(1)
            },
            searchQuery.value !== undefined ? 400 : 0,
        )
    })

    onMounted(() => loadPage())
</script>
