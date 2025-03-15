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
                    link: '/user/talk/edit-source',
                    isAuthenticated: authStore.isAuthenticated,
                },
                {
                    name: 'View History',
                    link: '/user/talk/view-history',
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
        <section class="bg-white p-2 mt-4">
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
const title = decodeURIComponent(route.query.title)
const talkTitle = ref('')
const talk = ref({})
const editorContent = ref('')

const loadTalk = async (title) => {
    try {
        const response = await talkService.getTalk(title)
        if (response.success) {
            talkTitle.value = response.data.title
            talk.value = JSON.parse(response.data.sections)

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
            article.value = []
            talkStore.clearArticle()
        }
    } catch (error) {
        sections.value = []
        talkStore.clearArticle()
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
            uuid: talkStore.talk.uuid,
            title: articleStore.article.title,
            slug: articleStore.article.slug,
            content: editorContent.value,
        }

        const response = await talkService.updateTalk(params)
        if (!response.success) {
            alertVariant.value = 'error'
            alertMessage.value = response.errors[0]
            setTimeout(() => {
                showAlert.value = true
            }, 0)
            return
        }

        router.push(
            `/talk?title=${encodeURIComponent(articleStore.article.slug)}`
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
    await loadTalk(title)
})
</script>
