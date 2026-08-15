<template>
    <main>
        <div
            class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden"
        >
            <AdminPageHeader
                title="Articles"
                subtitle="Manage all articles on the platform"
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
                            placeholder="Search by title, slug, or author..."
                            class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                        />
                    </div>
                    <div class="flex gap-3 sm:w-auto w-full">
                        <select
                            v-model="filterType"
                            class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white"
                        >
                            <option value="">All Types</option>
                            <option value="article">Article</option>
                            <option value="user page">User Page</option>
                        </select>
                        <select
                            v-model="filterAccess"
                            class="flex-1 sm:flex-initial px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white"
                        >
                            <option value="">All Access</option>
                            <option value="public">Public</option>
                            <option value="private">Private</option>
                        </select>
                    </div>
                </div>
            </div>

            <LoadingSpinner v-if="loading" text="Loading articles..." />
            <template v-else>
                <AdminTable
                    :columns="columns"
                    :rows="articles"
                    row-key="uuid"
                    empty-text="No articles found"
                >
                    <template #cell-title="{ row }">
                        <span class="font-medium max-w-xs truncate block">{{
                            row.title
                        }}</span>
                    </template>
                    <template #cell-author="{ row }">
                        <span class="text-gray-600">{{
                            row.user?.username || '—'
                        }}</span>
                    </template>
                    <template #cell-type="{ row }">
                        <AdminBadge
                            :variant="
                                row.type === 'user page' ? 'purple' : 'info'
                            "
                            :text="row.type || 'article'"
                        />
                    </template>
                    <template #cell-accessType="{ row }">
                        <AdminBadge
                            :variant="
                                row.accessType === 'public'
                                    ? 'success'
                                    : 'amber'
                            "
                            :text="row.accessType"
                        />
                    </template>
                    <template #cell-updatedAt="{ row }">
                        <span class="text-gray-500">{{ row.updatedAt }}</span>
                    </template>
                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <NuxtLink
                                :to="
                                    '/article?title=' +
                                    encodeURIComponent(row.slug)
                                "
                                target="_blank"
                                class="p-1.5 text-gray-400 hover:text-indigo-600 rounded-md hover:bg-indigo-50 transition-colors"
                                title="View article"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'eye']"
                                    class="w-4 h-4"
                                />
                            </NuxtLink>
                            <button
                                class="p-1.5 text-gray-400 hover:text-red-600 rounded-md hover:bg-red-50 transition-colors"
                                title="Delete article"
                                @click="confirmDelete(row)"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'trash']"
                                    class="w-4 h-4"
                                />
                            </button>
                        </div>
                    </template>
                </AdminTable>

                <div
                    v-if="meta.lastPage > 1"
                    class="px-4 py-3 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-2"
                >
                    <span class="text-sm text-gray-500 order-2 sm:order-1">
                        Page {{ meta.currentPage }} of {{ meta.lastPage }} ({{
                            meta.total
                        }}
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
            title="Delete Article"
            message-title="Are you sure?"
            :message="`Delete '${deleteTarget?.title}'? This action cannot be undone.`"
            confirm-text="Delete"
            cancel-text="Cancel"
            :is-loading="deleting"
            @confirm="handleDelete"
        />
    </main>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { adminService } from '~/services/adminService'
import { useToastify } from '~/composables/useToastify'

const { notifySuccess, notifyError } = useToastify()

useHead({ title: 'Admin - Articles' })
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
    { key: 'title', label: 'Title' },
    { key: 'author', label: 'Author' },
    { key: 'type', label: 'Type' },
    { key: 'accessType', label: 'Access' },
    { key: 'updatedAt', label: 'Updated' },
    {
        key: 'actions',
        label: 'Actions',
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
        notifyError(error.errors?.[0] || 'Failed to load articles')
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
            notifySuccess('Article deleted successfully')
            showDeleteModal.value = false
            deleteTarget.value = null
            await loadPage(meta.value.currentPage)
        }
    } catch (error) {
        notifyError(error.errors?.[0] || 'Failed to delete article')
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
        searchQuery.value !== undefined ? 400 : 0
    )
})

onMounted(() => loadPage())
</script>
