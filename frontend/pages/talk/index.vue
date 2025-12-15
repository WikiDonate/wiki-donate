<!-- eslint-disable vue/html-self-closing -->
<!-- eslint-disable vue/no-v-html -->
<template>
    <main class="w-full">
        <!-- Top bar -->
        <TopBarTitle :page-title="`${talkTitle || title}`" />
        <TopBar
            :left-menu-items="[
                {
                    name: 'Article',
                    link: '/article?title=' + encodeURIComponent(title),
                    isAuthenticated: false,
                },
                {
                    name: 'Talk',
                    link: '/talk?title=' + encodeURIComponent(title),
                    isAuthenticated: false,
                },
            ]"
            :right-menu-items="[
                {
                    name: 'Edit Source',
                    link:
                        '/talk/edit-source?title=' + encodeURIComponent(title),
                    isAuthenticated: true,
                },
                {
                    name: 'View History',
                    link:
                        '/talk/view-history?title=' + encodeURIComponent(title),
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

        <!-- Talk page -->
        <section v-else class="bg-white p-2">
            <div v-if="sections.length === 0">
                <!-- Check if article exists -->
                <div
                    v-if="!articleStore.article || !articleStore.article?.uuid"
                    class="text-center text-gray-600 py-4"
                >
                    <p>
                        This article does not exist yet. Please
                        <NuxtLink
                            :to="`/article?title=${title}`"
                            class="text-indigo-600 font-medium hover:underline"
                        >
                            create the article
                        </NuxtLink>
                        before starting a discussion.
                    </p>
                </div>
                <div v-else-if="authStore.isAuthenticated">
                    <div>
                        <QuillEditor
                            v-model:content="editorContent"
                            placeholder="What are your thoughts on this article?"
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
                </div>
                <div v-else class="text-center text-gray-600 py-4">
                    <p>
                        You must
                        <NuxtLink
                            to="/login"
                            class="text-green-600 underline font-medium hover:text-green-700"
                        >
                            log in
                        </NuxtLink>
                        to start a discussion.
                    </p>
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
                            v-if="authStore.isAuthenticated"
                            :to="`/talk/edit-section?title=${title}&uuid=${index}`"
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
                        v-html="parseWikiUserLinks(item.content)"
                    />

                    <!-- Metadata like Wikipedia -->
                    <!-- <div class="text-sm text-gray-500 mt-2 italic">
                        <NuxtLink
                            v-if="item.edited_by"
                            :to="`/user/page?username=${item.edited_by.username}`"
                            class="font-semibold text-indigo-600 hover:text-purple-600"
                            >{{ item.edited_by.username }}</NuxtLink
                        >
                        <NuxtLink
                            v-else
                            :to="`/user/page?username=${talkStore.talk?.user?.username}`"
                            class="font-semibold text-indigo-600 hover:text-purple-600"
                            >{{ talkStore.talk?.user?.username }}</NuxtLink
                        >
                        •
                        <span>{{
                            item.edited_at
                                ? formatDateUTCLong(item.edited_at)
                                : formatDateUTCLong(talkStore.talk?.created_at)
                        }}</span>
                    </div> -->
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
import { talkService } from '~/services/talkService'
// import { formatDateUTCLong } from '~/utils/dateFormatUTC'
import { parseWikiUserLinks } from '~/composables/global'

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
const sections = ref([])
const submitting = ref(false)
const loading = ref(false)

const handleSubmit = async () => {
    if (submitting.value) return
    submitting.value = true
    showAlert.value = false

    try {
        if (!editorContent.value) {
            throw new Error('Please enter some content')
        }

        const params = {
            articleUuid: articleStore.article.uuid,
            title: title.value,
            content: editorContent.value,
        }

        const response = await talkService.saveTalk(params)
        if (!response.success) {
            throw new Error(response.errors?.[0] || 'Failed to save talk')
        }

        alertVariant.value = 'success'
        alertMessage.value = response.message
        setTimeout(() => {
            showAlert.value = true
        }, 0)

        await loadTalk(response.data.slug)
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

const loadTalk = async (title) => {
    loading.value = true
    try {
        const response = await talkService.getTalk(title)
        if (response.success) {
            talkTitle.value = response.data.title
            sections.value = response.data.sections
            talkStore.addTalk(response.data)
        } else {
            sections.value = []
            talkStore.clearTalk()
        }
    } catch (error) {
        sections.value = []
        talkStore.clearTalk()
        console.error(error)
    } finally {
        loading.value = false
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
