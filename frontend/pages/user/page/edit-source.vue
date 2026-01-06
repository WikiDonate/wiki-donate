<!-- edit source page -->
<template>
    <main class="w-full">
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

        <!-- edit source section -->
        <!-- v-else-if="editorContent && articleStore.article.editable" -->
        <section v-else-if="canEdit" class="bg-white p-2 mt-4">
            <div>
                <QuillEditor
                    v-if="editorContent"
                    v-model:content="editorContent"
                    :initial-content="editorContent"
                />
            </div>
            <div class="w-40 mt-4">
                <FormSubmitButton
                    :text="submitting ? 'Updating...' : 'Update Article'"
                    :disabled="submitting"
                    @click="handleSubmit"
                />
            </div>
        </section>
        <p v-else class="text-center mt-5 text-gray-600">
            <span v-if="!authStore.isAuthenticated">
                You must
                <NuxtLink
                    to="/login"
                    class="text-green-600 underline hover:text-green-700"
                    >log in</NuxtLink
                >
                to edit this article.
            </span>
            <span v-else-if="!currentArticle || !currentArticle.uuid">
                This article does not exist yet. Please create it first before
                editing.
            </span>
            <span v-else-if="!editorContent">
                This article has no content to edit.
            </span>
            <span v-else>
                You don't have permission to edit this article.
            </span>
        </p>
    </main>
</template>

<script setup>
import { articleService } from '~/services/articleService'

useHead({
    title: 'Edit Source',
})

const articleStore = useArticleStore()
const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()

const currentUser = computed(() => authStore.user)
const currentArticle = computed(() => articleStore.article)

const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const title = decodeURIComponent(route.query.username)
const articleTitle = ref('')
const article = ref({})
const editorContent = ref('')
const loading = ref(false)
const submitting = ref(false)
const accessType = ref(null)

// check if current user is owner
const isOwner = computed(() => {
    return (
        currentUser.value &&
        currentUser.value?.uuid === currentArticle.value?.user?.uuid
    )
})

// computed: can user edit?
const canEdit = computed(() => {
    if (!authStore.isAuthenticated) return false
    if (!editorContent.value) return false
    if (!currentArticle.value?.editable) return false

    if (accessType.value === 'public') return true
    if (accessType.value === 'private' && isOwner.value) return true

    return false
})

const loadArticle = async (slug) => {
    loading.value = true
    try {
        const response = await articleService.getArticle(slug)
        if (response.success) {
            articleTitle.value = response.data.title
            article.value = JSON.parse(response.data.sections)

            let articleString = ''
            article.value.forEach((item) => {
                if (typeof item === 'string') {
                    articleString += item
                } else {
                    if (item.title) articleString += item.title
                    if (item.content) articleString += item.content
                }
            })

            editorContent.value = articleString

            articleStore.addArticle(response.data)
            accessType.value = response.data.accessType || 'public'
        } else {
            article.value = []
            articleStore.clearArticle()
        }
    } catch (error) {
        console.error(error)
        article.value = []
        articleStore.clearArticle()
    } finally {
        loading.value = false
    }
}

const handleSubmit = async () => {
    if (submitting.value) return
    submitting.value = true
    showAlert.value = false

    try {
        if (!editorContent.value) {
            throw new Error('Please enter some content')
        }

        // Prepare params
        const params = {
            title: articleStore.article.title,
            slug: articleStore.article.slug,
            content: editorContent.value,
        }

        const response = await articleService.updateArticle(params)
        if (!response.success) {
            throw new Error(response.errors?.[0] || 'Failed to update article')
        }

        router.push(
            `/user/page?username=${encodeURIComponent(articleStore.article.slug)}`
        )
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

onMounted(async () => {
    await loadArticle(title)
})
</script>
