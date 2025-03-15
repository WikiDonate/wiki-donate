<!-- eslint-disable vue/html-self-closing -->
<!-- eslint-disable vue/no-v-html -->
<template>
    <main class="w-full">
        <!-- Top bar -->
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
                    link: `/user/talk?username=${authStore.user.username}`,
                    isAuthenticated: authStore.isAuthenticated,
                },
            ]"
            :right-menu-items="[
                {
                    name: 'Edit Source',
                    link: `/user/page/edit-source?username=${authStore.user.username}`,
                    isAuthenticated: authStore.isAuthenticated,
                },
                {
                    name: 'View History',
                    link: `/user/page/view-history?username=${authStore.user.username}`,
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

        <!-- Content -->
        <section
            v-if="editorContent && articleStore.article.editable"
            class="bg-white p-2"
        >
            <div>
                <QuillEditor
                    v-if="editorContent"
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
import { onMounted, ref } from 'vue'
import { articleService } from '~/services/articleService'

useHead({
    title: 'Edit Section',
})

const articleStore = useArticleStore()
const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
// const title = decodeURIComponent(route.query.title)
const uuid = route.query.uuid || ''
const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const editorContent = ref('')
const section = ref({})

const loadSection = async (uuid) => {
    section.value = JSON.parse(articleStore.article.sections).find(
        (item, idx) => idx == uuid
    )

    if (!section.value) {
        alertVariant.value = 'error'
        alertMessage.value = 'Section not found'
        setTimeout(() => {
            showAlert.value = true
        }, 0)

        return
    }

    editorContent.value = section.value.title + '' + section.value.content
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

        const content = JSON.parse(articleStore.article.sections)
        content[uuid] = editorContent.value

        let resultString = ''
        content.forEach((item) => {
            if (typeof item === 'string') {
                resultString += item
            } else {
                if (item.title) resultString += item.title
                if (item.content) resultString += item.content
            }
        })

        // Prepare params
        const params = {
            title: articleStore.article.title,
            slug: articleStore.article.slug,
            content: resultString,
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
            `/user/page?username=${encodeURIComponent(articleStore.article.slug)}`
        )
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value = error.errors[0]
        setTimeout(() => {
            showAlert.value = true
        }, 0)
    }
}

onMounted(async () => {
    await loadSection(uuid)
})
</script>
