<!-- eslint-disable vue/html-self-closing -->
<!-- eslint-disable vue/no-v-html -->
<template>
    <main class="w-full">
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

        <!-- Talk page -->
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
                >
                    <div class="flex justify-between">
                        <div v-html="item.title" />
                        <NuxtLink
                            v-if="authStore.isAuthenticated"
                            :to="`/talk/edit-section?title=${title}&uuid=${index}`"
                            exact
                            >[Edit]</NuxtLink
                        >
                    </div>
                    <div v-html="item.content" />
                    <br />
                </div>
            </div>
        </section>
    </main>
</template>

<script setup>
import { talkService } from '~/services/talkService'

useHead({
    title: 'Talk',
})

const articleStore = useArticleStore()
const authStore = useAuthStore()
const talkStore = useTalkStore()
const route = useRoute()
const title = ref(decodeURIComponent(route.query.title))
const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')
const editorContent = ref('')
const talkTitle = ref('')
const sections = ref({})

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

        const params = {
            articleUuid: articleStore.article.uuid,
            title: title.value,
            content: editorContent.value,
        }

        const response = await talkService.saveTalk(params)
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
            loadTalk(response.data.slug)
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

const loadTalk = async (title) => {
    try {
        const response = await talkService.getTalk(title)
        if (response.success) {
            talkTitle.value = response.data.title
            sections.value = JSON.parse(response.data.sections)
            talkStore.addTalk(response.data)
        } else {
            sections.value = []
            talkStore.clearTalk()
        }
    } catch (error) {
        sections.value = []
        talkStore.clearTalk()
        console.error(error)
    }
}

onMounted(async () => {
    await loadTalk(title.value)
})

watch(route, (newRoute) => {
    if (newRoute.query.title) {
        title.value = decodeURIComponent(newRoute.query.title)
        loadTalk(newRoute.query.title)
    }
})
</script>
