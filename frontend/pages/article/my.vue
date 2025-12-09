<!-- eslint-disable vue/html-self-closing -->
<!-- eslint-disable vue/no-v-html -->
<template>
    <main class="w-full">
        <!-- Top bar -->
        <TopBarTitle :page-title="`My Articles`" />

        <section class="bg-white p-4 sm:p-6 rounded-2xl shadow-md mt-4">
            <!-- Message -->
            <div class="mt-2">
                <AlertMessage
                    v-if="showAlert"
                    :variant="alertVariant"
                    :message="alertMessage"
                    @close="showAlert = false"
                />
            </div>

            <!-- loader -->
            <div v-if="loading" class="flex items-center justify-center py-12">
                <LoadingSpinner text="Loading Articles" />
            </div>

            <!-- Articles list -->
            <div v-else>
                <div v-if="articles.length > 0" class="grid gap-4 md:gap-6">
                    <div
                        v-for="article in articles"
                        :key="article.uuid"
                        class="border rounded-xl p-3 sm:p-4 hover:shadow-md transition"
                    >
                        <!-- Article Title & Meta -->
                        <div
                            class="flex flex-col md:flex-row md:items-center md:justify-between gap-3"
                        >
                            <div>
                                <h3
                                    class="text-lg sm:text-xl font-bold text-gray-800 mb-1 capitalize flex items-center gap-2"
                                >
                                    <!-- Badge -->
                                    <span
                                        v-if="article.accessType"
                                        class="px-2 py-0.5 text-xs font-semibold rounded-full"
                                        :class="
                                            article.accessType === 'public'
                                                ? 'bg-green-100 text-green-700'
                                                : 'bg-gray-200 text-gray-700'
                                        "
                                    >
                                        {{ article.accessType }}
                                    </span>
                                    {{ article.title }}
                                </h3>
                                <p class="text-sm text-gray-500">
                                    By
                                    <NuxtLink
                                        :to="`/user/page?username=${article.user?.username}`"
                                        class="font-semibold text-indigo-600 hover:text-purple-600"
                                        >{{ article.user?.username }}</NuxtLink
                                    >
                                    ·
                                    {{ formatDateUTC(article.updatedAt) }}
                                </p>
                            </div>

                            <!-- View Button (desktop only) -->
                            <NuxtLink
                                :to="`/article?title=${article.slug}`"
                                class="hidden md:flex items-center gap-2 px-3 py-1 rounded-full text-white font-semibold bg-gradient-to-r from-indigo-600 to-purple-600 transition-all duration-300 hover:from-indigo-500 hover:to-purple-500"
                                :aria-label="`View ${article.title}`"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'eye']"
                                    class="w-4 h-4"
                                />
                                View
                            </NuxtLink>
                        </div>

                        <!-- View Button (mobile only, shown below card) -->
                        <div class="mt-4 md:hidden">
                            <NuxtLink
                                :to="`/article?title=${article.slug}`"
                                class="flex items-center justify-center gap-2 w-full px-3 py-1 rounded-full text-white font-semibold bg-gradient-to-r from-indigo-600 to-purple-600 transition-all duration-300 hover:from-indigo-500 hover:to-purple-500"
                                :aria-label="`View ${article.title}`"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'eye']"
                                    class="w-4 h-4"
                                />
                                View
                            </NuxtLink>
                        </div>
                    </div>
                </div>

                <!-- No Articles -->
                <div v-else class="text-center text-gray-500 py-12">
                    <font-awesome-icon
                        :icon="['fas', 'file-alt']"
                        class="w-12 h-12 text-gray-300 mb-4"
                    />
                    <p class="text-base sm:text-lg">
                        You have not created any articles yet.
                    </p>
                </div>
            </div>
            <!-- Pagination -->
            <div
                v-if="
                    !loading &&
                    articles.length > 0 &&
                    articlesMeta?.lastPage > 1
                "
                class="flex items-center justify-center mt-8"
            >
                <Pagination
                    :current-page="currentPage"
                    :total-pages="articlesMeta.lastPage"
                    @page-change="goToPage"
                />
            </div>
        </section>
    </main>
</template>

<script setup>
import { ref } from 'vue'
import { articleService } from '~/services/articleService'
import { formatDateUTC } from '~/utils/dateFormatUTC'

definePageMeta({
    middleware: 'auth',
})

useHead({
    title: 'My Articles',
})

const articleStore = useArticleStore()

const articles = computed(() => articleStore.articles || [])
const articlesMeta = computed(() => articleStore.articlesMeta || {})

// Alert state
const showAlert = ref(false)
const alertVariant = ref('success')
const alertMessage = ref('')

// Loader state
const loading = ref(false)

const currentPage = ref(1)

// Function to show alert
const showAlertMessage = (message, variant = 'error') => {
    alertMessage.value = message
    alertVariant.value = variant
    showAlert.value = true
    setTimeout(() => {
        showAlert.value = false
    }, 5000)
}

// Function to load my articles
const loadMyArticles = async (page = 1) => {
    loading.value = true
    try {
        const response = await articleService.getMyArticles(page)
        if (response.success) {
            articleStore.addArticles({
                articles: response.data,
                articlesMeta: response.meta,
            })
            currentPage.value = response.meta.currentPage
        } else {
            articleStore.clearArticles()
            showAlertMessage(response.message || 'Failed to load articles')
        }
    } catch (error) {
        console.error('Error loading articles:', error)
        articleStore.clearArticles()
        showAlertMessage(
            error.message || 'An error occurred while loading articles'
        )
    } finally {
        loading.value = false
    }
}

const goToPage = (page) => {
    if (page >= 1 && page <= articlesMeta.value.lastPage) {
        loadMyArticles(page)
        // Scroll to top when changing pages
        window.scrollTo({ top: 0, behavior: 'smooth' })
    }
}

onMounted(() => {
    loadMyArticles()
})
</script>
