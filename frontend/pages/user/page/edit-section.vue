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
            <LoadingSpinner text="Loading Section" />
        </div>

        <!-- Content -->
        <section v-else-if="canEdit" class="bg-white p-2">
            <div>
                <QuillEditor
                    v-if="editorContent"
                    v-model:content="editorContent"
                    :initial-content="editorContent"
                />
            </div>
            <div class="w-40 mt-4">
                <FormSubmitButton
                    :text="submitting ? 'Updating...' : 'Update Section'"
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
            <span
                v-else-if="!articleStore.article || !articleStore.article.uuid"
            >
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
import { onMounted, ref } from 'vue'
import { articleService } from '~/services/articleService'

useHead({
    title: 'Edit Section',
})

const articleStore = useArticleStore()
const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
const title = decodeURIComponent(route.query.username)
const uuid = route.query.uuid || ''
const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const editorContent = ref('')
const section = ref({})
const submitting = ref(false)
const loading = ref(false)

// check if current user is owner
const isOwner = computed(() => {
    return (
        authStore.user &&
        authStore.user?.uuid === articleStore.article?.user?.uuid
    )
})

// computed: can user edit?
const canEdit = computed(() => {
    if (!authStore.isAuthenticated) return false
    if (!editorContent.value) return false
    if (!articleStore.article?.editable) return false

    if (articleStore.article?.accessType === 'public') return true
    if (articleStore.article?.accessType === 'private' && isOwner.value)
        return true

    return false
})

const loadSection = async (uuid) => {
    loading.value = true
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
    loading.value = false
}

const handleSubmit = async () => {
    if (submitting.value) return
    submitting.value = true
    showAlert.value = false

    try {
        if (!editorContent.value) {
            throw new Error('Please enter some content')
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
    await loadSection(uuid)
})
</script>
