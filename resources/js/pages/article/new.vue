<!-- new article page -->
<template>
    <main class="w-full">
        <!-- Top bar -->
        <TopBarTitle :page-title="t('article.newTitle')" />

        <!-- Alert message -->
        <div class="mt-2">
            <AlertMessage
                v-if="showAlert"
                :variant="alertVariant"
                :message="alertMessage"
                @close="showAlert = false"
            />
        </div>

        <!-- New article form -->
        <section class="bg-white rounded-2xl shadow-md mt-4 p-4 sm:p-6">
            <div v-if="authStore.isAuthenticated" class="space-y-6">
                <!-- Title input -->
                <div>
                    <label for="article-title" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ t('article.title') }}
                    </label>
                    <FormInput
                        id="article-title"
                        v-model="articleTitle"
                        :placeholder="t('article.titlePlaceholder')"
                    />
                </div>

                <!-- Save button -->
                <div class="flex justify-end">
                    <Button
                        :text="t('article.save')"
                        variant="primary"
                        width="auto"
                        :disabled="!articleTitle.trim() || isSaving"
                        @click="showConfirmModal = true"
                    />
                </div>
            </div>

            <!-- Not authenticated message -->
            <div v-else class="text-center py-8">
                <p class="text-gray-700 text-sm sm:text-base mb-4">
                    {{ t('article.pleaseLogin') }}
                </p>
                <NuxtLink
                    to="/login"
                    class="inline-block bg-linear-to-r from-indigo-600 to-purple-600 text-white font-bold py-2 px-6 rounded-md hover:from-indigo-500 hover:to-purple-500 transition-all"
                >
                    {{ t('article.login') }}
                </NuxtLink>
            </div>
        </section>

        <!-- Confirmation Modal -->
        <ConfirmModal
            v-model="showConfirmModal"
            :title="t('article.saveArticle')"
            :message-title="t('article.confirmSave')"
            :message="`Title: ${articleTitle}`"
            :confirm-text="t('article.save')"
            :cancel-text="t('common.cancel')"
            :is-loading="isSaving"
            @confirm="handleSave"
        />
    </main>
</template>

<script setup>
    import { ref } from 'vue'
    import { articleService } from '~/services/articleService'

    const { t } = useI18n()

    useHead({
        title: t('article.newArticleTitle'),
    })

    const route = useRoute()
    const router = useRouter()
    const authStore = useAuthStore()

    const articleTitle = ref(decodeURIComponent(route.query.title || ''))
    const showConfirmModal = ref(false)
    const isSaving = ref(false)
    const showAlert = ref(false)
    const alertVariant = ref('success')
    const alertMessage = ref('')

    const handleSave = async () => {
        if (isSaving.value) return

        isSaving.value = true
        showAlert.value = false

        try {
            const response = await articleService.saveArticle({
                title: articleTitle.value,
                content: '',
            })

            if (response.success) {
                alertVariant.value = 'success'
                alertMessage.value = response.message || t('article.savedSuccess')
                showAlert.value = true

                setTimeout(() => {
                    router.push(`/article?title=${encodeURIComponent(articleTitle.value)}`)
                }, 1500)
            } else {
                alertVariant.value = 'error'
                alertMessage.value = response.errors?.[0] || t('article.saveFailed')
                showAlert.value = true
            }
        } catch (error) {
            alertVariant.value = 'error'
            alertMessage.value = error.errors?.[0] || t('article.saveError')
            showAlert.value = true
        } finally {
            isSaving.value = false
            showConfirmModal.value = false
        }
    }
</script>
