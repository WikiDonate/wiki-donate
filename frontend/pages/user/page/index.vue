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

        <!-- Sections -->
        <section class="bg-white p-2">
            <div v-if="sections.length === 0">
                <div>
                    <QuillEditor v-model:content="editorContent" />
                </div>
                <div class="w-40 mt-4">
                    <FormSubmitButton @click="handleSubmit" />
                </div>
            </div>
            <div v-else>
                <div
                    v-for="(item, index) in sections"
                    :key="`section-${index}`"
                    class="ql-content"
                >
                    <div class="flex justify-between">
                        <div v-html="item.title" />
                        <NuxtLink
                            v-if="authStore.isAuthenticated && editable"
                            :to="`/user/page/edit-section?username=${title}&uuid=${index}`"
                            exact
                            >[Edit]</NuxtLink
                        >
                    </div>
                    <div class="ql-text" v-html="item.content" />
                    <br />
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
            type: 'userPage',
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
