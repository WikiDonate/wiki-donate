<!-- eslint-disable vue/html-self-closing -->
<!-- eslint-disable vue/no-v-html -->
<template>
    <main class="w-full">
        <!-- Top bar -->
        <TopBarTitle :page-title="`Hello, ${title}!`" />
        <TopBar
            :left-menu-items="[
                {
                    name: 'User Page',
                    link: `/user/page?username=${title}`,
                    isAuthenticated: false,
                },
                {
                    name: 'Talk',
                    link: `/user/talk?username=${title}`,
                    isAuthenticated: false,
                },
            ]"
            :right-menu-items="[
                {
                    name: 'Edit Source',
                    link: `/user/page/edit-source?username=${title}`,
                    isAuthenticated: true,
                },
                {
                    name: 'View History',
                    link: `/user/page/view-history?username=${title}`,
                    isAuthenticated: false,
                },
            ]"
        />

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
            <LoadingSpinner text="Loading Article" />
        </div>

        <!-- Sections -->
        <section v-else class="bg-white p-2">
            <div v-if="sections.length === 0 && authStore.isAuthenticated">
                <div>
                    <QuillEditor v-model:content="editorContent" />
                </div>
                <div class="w-40 mt-4">
                    <FormSubmitButton
                        :text="submitting ? 'Saving...' : 'Save Article'"
                        :disabled="submitting"
                        @click="handleSubmit"
                    />
                </div>
            </div>
            <div v-else>
                <div
                    v-for="(item, index) in sections"
                    :key="`section-${index}`"
                >
                    <div class="flex justify-between items-start">
                        <div
                            class="font-semibold text-lg mb-2 break-words break-all text-gray-900"
                            v-html="item.title"
                        />
                        <NuxtLink
                            v-if="canEdit"
                            :to="`/user/page/edit-section?username=${title}&uuid=${index}`"
                            exact
                            class="flex items-center gap-1 text-xs sm:text-sm bg-gradient-to-r from-indigo-600 to-purple-600 transition-all duration-300 text-white font-semibold py-1 px-2 rounded-lg hover:from-indigo-500 hover:to-purple-500 shadow-sm whitespace-nowrap"
                        >
                            <font-awesome-icon
                                :icon="['fas', 'pen']"
                                class="w-3 h-3"
                            />
                            <span class="hidden sm:inline">Edit</span></NuxtLink
                        >
                    </div>
                    <div
                        class="text-gray-700 leading-relaxed break-words break-all"
                        v-html="item.content"
                    />
                    <div
                        v-if="index < sections.length - 1"
                        class="border-t border-indigo-300 mt-6 pt-6"
                    />
                </div>
            </div>
        </section>
    </main>
</template>

<script setup>
import { articleService } from '~/services/articleService'

useHead({
    title: 'User Page',
})

const articleStore = useArticleStore()
const authStore = useAuthStore()
const route = useRoute()

const title = ref(decodeURIComponent(route.query.username))
const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const editorContent = ref('')
const articleTitle = ref('')
const sections = ref({})
const editable = ref(false)
const loading = ref(false)
const submitting = ref(false)
const accessType = ref(null)

// check if current user is owner
const isOwner = computed(() => {
    return (
        authStore.user &&
        authStore.user?.uuid === articleStore.article?.user?.uuid
    )
})

// computed: can user edit?
const canEdit = computed(() => {
    if (!authStore.isAuthenticated || !editable.value) return false

    if (accessType.value === 'public') return true
    if (accessType.value === 'private' && isOwner.value) return true

    return false
})

const handleSubmit = async () => {
    if (submitting.value) return
    submitting.value = true
    showAlert.value = false

    try {
        if (!editorContent.value) {
            throw new Error('Please enter some content')
        }

        // Save article
        const params = {
            title: title.value,
            content: editorContent.value,
            type: 'userPage',
        }

        const response = await articleService.saveArticle(params)
        if (!response.success) {
            throw new Error(response.errors?.[0] || 'Failed to save article')
        }

        alertVariant.value = 'success'
        alertMessage.value = response.message
        setTimeout(() => {
            showAlert.value = true
        }, 0)

        await loadArticle(response.data.slug)
    } catch (error) {
        console.error(error)
        alertVariant.value = 'error'
        alertMessage.value =
            error.errors[0] || error.message || 'Unexpected error'
        setTimeout(() => {
            showAlert.value = true
        }, 0)
    } finally {
        submitting.value = false
    }
}

const loadArticle = async (slug) => {
    loading.value = true
    try {
        const response = await articleService.getArticle(slug)
        if (response.success) {
            articleTitle.value = response.data.title
            sections.value = JSON.parse(response.data.sections)
            editable.value = response.data.editable
            accessType.value = response.data.accessType
            articleStore.addArticle(response.data)
        } else {
            sections.value = []
            editable.value = false
            accessType.value = 'public'
            articleStore.clearArticle()
        }
    } catch (error) {
        sections.value = []
        editable.value = false
        accessType.value = 'public'
        articleStore.clearArticle()
        console.error(error)
    } finally {
        loading.value = false
    }
}

onMounted(async () => {
    await loadArticle(title.value)
})
</script>
