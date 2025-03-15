<template>
    <main class="w-full">
        <!-- Top bar -->
        <TopBarTitle :page-title="`Hello, ${authStore.user.username}!`" />
        <TopBar
            :left-menu-items="[
                {
                    name: 'User Page',
                    link: '/user/page',
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
        <section class="bg-white p-2">
            <div>
                <QuillEditor v-model:content="editorContent" />
            </div>
            <div class="w-40 mt-4">
                <FormSubmitButton @click="handleSubmit" />
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
const title = ref(decodeURIComponent(route.query.title))
const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const editorContent = ref('')
const articleTitle = ref('')
const sections = ref({})
const editable = ref(false)

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

        // Save article
        const params = {
            title: title.value,
            content: editorContent.value,
        }

        const response = await articleService.saveArticle(params)
        if (!response.success) {
            alertVariant.value = 'error'
            alertMessage.value = response.errors[0]
            setTimeout(() => {
                showAlert.value = true
            }, 0)
            return
        }

        alertVariant.value = 'success'
        alertMessage.value = response.message
        setTimeout(() => {
            showAlert.value = true
            loadArticle(response.data.slug)
        }, 0)
    } catch (error) {
        console.error(error)
        alertVariant.value = 'error'
        alertMessage.value = error.errors[0]
        setTimeout(() => {
            showAlert.value = true
        }, 0)
    }
}

const loadArticle = async (slug) => {
    try {
        const response = await articleService.getArticle(slug)
        if (response.success) {
            articleTitle.value = response.data.title
            sections.value = JSON.parse(response.data.sections)
            editable.value = response.data.editable
            articleStore.addArticle(response.data)
        } else {
            sections.value = []
            editable.value = false
            articleStore.clearArticle()
        }
    } catch (error) {
        sections.value = []
        editable.value = false
        articleStore.clearArticle()
        console.error(error)
    }
}

onMounted(async () => {
    await loadArticle(title.value)
})
</script>
