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
                    link: `/user/talk/edit-source?username=${title}`,
                    isAuthenticated: true,
                },
                {
                    name: 'View History',
                    link: `/user/talk/view-history?username=${title}`,
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
            <LoadingSpinner text="Loading Talk" />
        </div>

        <!-- edit source section -->
        <section
            v-else-if="editorContent && authStore.isAuthenticated"
            class="bg-white p-2 mt-4"
        >
            <div>
                <QuillEditor
                    v-if="editorContent"
                    v-model:content="editorContent"
                    :initial-content="editorContent"
                />
            </div>
            <div
                class="text-xs sm:text-sm text-gray-600 mt-3 flex items-center gap-2"
            >
                <span class="text-gray-700"
                    >💬 Sign your posts on talk pages by typing</span
                >
                <span
                    class="inline-block font-mono text-indigo-600 border border-gray-300 bg-indigo-50 px-2 py-0.5 rounded-md shadow-sm"
                >
                    ~~~~
                </span>
            </div>
            <div class="w-40 mt-4">
                <FormSubmitButton
                    :text="submitting ? 'Submitting...' : 'Submit'"
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
            <span v-else-if="!talkStore.talk || !talkStore.talk.uuid">
                This talk page does not exist yet. Please start a discussion
                first before editing.
            </span>
            <span v-else> This talk has no content to edit. </span>
        </p>
    </main>
</template>

<script setup>
import { talkService } from '~/services/talkService'

useHead({
    title: 'Edit Source',
})

const articleStore = useArticleStore()
const authStore = useAuthStore()
const talkStore = useTalkStore()
const route = useRoute()
const router = useRouter()

const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const title = decodeURIComponent(route.query.username)
const talkTitle = ref('')
const talk = ref({})
const editorContent = ref('')
const loading = ref(false)
const submitting = ref(false)

const loadTalk = async (title) => {
    loading.value = true
    try {
        const response = await talkService.getTalk(title)
        if (response.success) {
            talkTitle.value = response.data.title
            talk.value = response.data.sections

            let talkString = ''
            talk.value.forEach((item) => {
                if (typeof item === 'string') {
                    talkString += item
                } else {
                    if (item.title) talkString += item.title
                    if (item.content) talkString += item.content
                }
            })

            editorContent.value = talkString
            talkStore.addTalk(response.data)
        } else {
            talk.value = []
            talkStore.clearTalk()
        }
    } catch (error) {
        console.error(error)
        talk.value = []
        talkStore.clearTalk()
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
            uuid: talkStore.talk.uuid,
            title: articleStore.article.title,
            slug: articleStore.article.slug,
            content: editorContent.value,
        }

        const response = await talkService.updateTalk(params)
        if (!response.success) {
            throw new Error(response.errors?.[0] || 'Failed to update talk')
        }

        router.push(`/talk?title=${encodeURIComponent(talkStore.talk.slug)}`)
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
    await loadTalk(title)
})
</script>
