<!-- edit source page -->
<template>
    <main class="w-full">
        <TopBarTitle :page-title="`Hello, ${authStore.user.username}!`" />
        <TopBar
            :left-menu-items="[
                {
                    name: 'User Page',
                    link: `/user/page?username=${authStore.user.username}`,
                    isAuthenticated: authStore.isAuthenticated,
                },
                {
                    name: 'Talk',
                    link: '/user/talk',
                    isAuthenticated: authStore.isAuthenticated,
                },
            ]"
            :right-menu-items="[
                {
                    name: 'Edit Source',
                    link: '/user/page/edit-source',
                    isAuthenticated: authStore.isAuthenticated,
                },
                {
                    name: 'View History',
                    link: '/user/page/view-history',
                    isAuthenticated: authStore.isAuthenticated,
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

        <!-- edit source section -->
        <section
            v-if="editorContent && articleStore.article.editable"
            class="bg-white p-2 mt-4"
        >
            <div>
                <QuillEditor
                    v-model:content="editorContent"
                    :initial-content="editorContent"
                />
            </div>
            <div class="w-40 mt-4">
                <FormSubmitButton text="Update" @click="handleSubmit" />
            </div>
        </section>
        <p v-else class="text-center">
            You don't have permission to edit this page.
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
const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const title = decodeURIComponent(route.query.title)
const articleTitle = ref('')
const article = ref({})
const editorContent = ref('')

const loadArticle = async (slug) => {
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
        } else {
            article.value = []
            articleStore.clearArticle()
        }
    } catch (error) {
        sections.value = []
        articleStore.clearArticle()
        console.error(error)
    }
}

const handleSubmit = async () => {
    showAlert.value = false

    try {
        if (!editorContent.value) {
            alertVariant.value = 'error'
            alertMessage.value = 'Please enter some content'
            setTimeout(() => {
                showAlert.value = true
            }, 0)
            return
        }

        // Prepare params
        const params = {
            title: articleStore.article.title,
            slug: articleStore.article.slug,
            content: editorContent.value,
        }

        const response = await articleService.updateArticle(params)
        if (!response.success) {
            alertVariant.value = 'error'
            alertMessage.value = response.errors[0]
            setTimeout(() => {
                showAlert.value = true
            }, 0)
            return
        }

        router.push(
            `/article?title=${encodeURIComponent(articleStore.article.slug)}`
        )
    } catch (error) {
        console.error(error)
        alertVariant.value = 'error'
        alertMessage.value = error.errors[0]
        setTimeout(() => {
            showAlert.value = true
        }, 0)
    }
}

onMounted(async () => {
    await loadArticle(title)
})
</script>
