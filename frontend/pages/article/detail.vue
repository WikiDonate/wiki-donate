<!-- eslint-disable vue/no-v-html -->
<template>
    <main class="w-full bg-white overflow-x-hidden">
        <!-- Top bar -->
        <TopBarTitle :page-title="articleTitle || title" />

        <!-- loader -->
        <div v-if="loading" class="flex items-center justify-center py-12">
            <LoadingSpinner text="Loading Article" />
        </div>

        <!-- Article detail page -->
        <section v-else class="px-2 lg:px-4 py-6">
            <div
                class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-6"
            >
                <!-- Left: Article Content -->
                <div class="col-span-12 lg:col-span-7 xl:col-span-8 space-y-6">
                    <div
                        class="bg-white rounded-2xl shadow-md overflow-hidden border border-gray-100"
                    >
                        <!-- Content -->
                        <div class="p-4 space-y-8">
                            <div
                                v-for="(item, index) in sections"
                                :key="`section-${index}`"
                                class="prose max-w-none"
                            >
                                <div
                                    class="font-semibold text-lg mb-2 break-words text-gray-900"
                                    v-html="item.title"
                                />
                                <div
                                    class="text-gray-700 leading-relaxed break-words"
                                    v-html="item.content"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Opinions -->
                <div class="col-span-12 lg:col-span-5 xl:col-span-4 space-y-6">
                    <!-- Opinion Form -->
                    <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                        <div
                            class="bg-gradient-to-r from-indigo-600 to-purple-600 px-4 py-4"
                        >
                            <h3 class="text-xl font-bold text-white">
                                Share Your Opinion
                            </h3>
                        </div>

                        <div class="p-4">
                            <!-- Opinion Message -->
                            <div>
                                <AlertMessage
                                    v-if="showOpinionAlert"
                                    :variant="alertOpinionVariant"
                                    :message="alertOpinionMessage"
                                    @close="showAlert = false"
                                />
                            </div>
                            <!-- Quill Editor -->
                            <QuillEditor
                                ref="opinionEditor"
                                v-model:content="opinionContent"
                                :toolbar-options="[['bold', 'italic']]"
                                class="bg-gray-50 rounded-lg min-h-[120px] mb-4 border border-gray-200"
                                placeholder="What are your thoughts on this article?"
                            />

                            <!-- Submit Button -->
                            <div class="flex justify-center">
                                <FormSubmitButton
                                    :text="
                                        isOpinionLoading
                                            ? 'Submitting...'
                                            : 'Submit'
                                    "
                                    type="submit"
                                    variant="primary"
                                    :disabled="isOpinionLoading"
                                    @click="addOpinion"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Opinions List -->
                    <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                        <!-- Header -->
                        <div
                            class="bg-gradient-to-r from-indigo-600 to-purple-600 px-4 py-4 flex items-center justify-between"
                        >
                            <div
                                class="flex items-center gap-3 text-white font-bold text-base sm:text-lg"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'comments']"
                                    class="w-4 h-4 sm:w-5 sm:h-5"
                                />
                                Community Opinions
                            </div>
                            <span
                                class="bg-white text-indigo-600 px-2 py-1 rounded-full text-xs font-medium"
                            >
                                {{ allOpinions.length }}
                            </span>
                        </div>

                        <!-- Opinions -->
                        <div
                            class="p-4 max-h-96 overflow-y-auto space-y-4 opinions"
                        >
                            <div
                                v-if="allOpinions.length === 0"
                                class="text-center py-8 text-gray-500"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'comments']"
                                    class="w-10 h-10 mb-3 text-gray-400"
                                />
                                <p>No opinions yet. Be the first to share!</p>
                            </div>

                            <OpinionCard
                                v-for="opinion in allOpinions"
                                v-else
                                :key="opinion.id"
                                :opinion="{
                                    ...opinion,
                                    expanded:
                                        expandedOpinions[opinion.id] || false,
                                    avatarColor: getAvatarColor(opinion.id),
                                }"
                                @toggle-expand="toggleOpinionExpand(opinion.id)"
                            />
                        </div>
                    </div>
                    <!-- /Opinions -->
                </div>
            </div>
        </section>
    </main>
</template>

<script setup>
import { articleService } from '~/services/articleService'

useHead({ title: 'Article' })

const articleStore = useArticleStore()
const route = useRoute()
const title = ref(decodeURIComponent(route.query.title))

const showOpinionAlert = ref(false)
const alertOpinionVariant = ref('')
const alertOpinionMessage = ref('')
const articleTitle = ref('')
const sections = ref([])
const editable = ref(false)
const loading = ref(false)
const isOpinionLoading = ref(false)

// Opinions state
const opinionContent = ref('')
const opinions = ref([])
const opinionEditor = ref(null)

// Track expansion (by opinion id)
const expandedOpinions = reactive({})

// Dummy opinions
const dummyOpinions = ref([
    {
        id: 1,
        name: 'Sarah Johnson',
        content:
            'This is a fantastic initiative! I really believe this approach could revolutionize how we think about community engagement and collaborative decision-making. The potential for positive impact is enormous when we combine resources and expertise in this way.',
        createdAt: new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(),
    },
    {
        id: 2,
        name: 'Mike Chen',
        content:
            'Interesting concept. I wonder how this would work in practice. There are several logistical challenges that need to be addressed, particularly around implementation timelines and resource allocation across different stakeholder groups.',
        createdAt: new Date(Date.now() - 4 * 60 * 60 * 1000).toISOString(),
    },
    {
        id: 3,
        name: 'Emily Rodriguez',
        content:
            'The idea of democratizing philanthropy is compelling. It allows for more diverse perspectives to be included in the decision-making process, which ultimately leads to more equitable outcomes for all community members involved.',
        createdAt: new Date(Date.now() - 6 * 60 * 60 * 1000).toISOString(),
    },
    {
        id: 4,
        name: 'David Thompson',
        content:
            'Could create healthy competition in the NGO sector. Organizations would need to demonstrate their effectiveness more transparently to attract funding, which would ultimately benefit the communities they serve through improved services.',
        createdAt: new Date(Date.now() - 8 * 60 * 60 * 1000).toISOString(),
    },
    {
        id: 5,
        name: 'Lisa Park',
        content:
            "Transparency is what appeals to me most here. When donors can see exactly how their contributions are being used and what impact they're having, it builds trust and encourages continued participation in philanthropic efforts.",
        createdAt: new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString(),
    },
])

// Combine user + dummy opinions
const allOpinions = computed(() => [...opinions.value, ...dummyOpinions.value])

const avatarColors = [
    '#8B5CF6',
    '#EC4899',
    '#10B981',
    '#F59E0B',
    '#EF4444',
    '#6366F1',
]

// Pick avatar color based on id hash
const getAvatarColor = (id) => {
    const idx =
        typeof id === 'string'
            ? id.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0)
            : id
    return avatarColors[idx % avatarColors.length]
}

// Toggle opinion expansion
const toggleOpinionExpand = (id) => {
    expandedOpinions[id] = !expandedOpinions[id]
}

// Load article
const loadArticle = async (slug) => {
    loading.value = true
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
    } catch (err) {
        sections.value = []
        editable.value = false
        articleStore.clearArticle()
        console.error(err)
    } finally {
        loading.value = false
    }
}

// Add opinion
const addOpinion = () => {
    if (!opinionContent.value.trim()) return
    opinions.value.unshift({
        id: `user-${Date.now()}`,
        name: 'You',
        content: opinionContent.value,
        createdAt: new Date().toISOString(),
    })
    opinionContent.value = ''

    const quill = opinionEditor.value?.$refs?.quillEditor?.getQuill?.()
    if (quill) {
        quill.setText('') // reset editor text
    }

    alertOpinionVariant.value = 'success'
    alertOpinionMessage.value = 'Opinion added!'
    setTimeout(() => {
        showOpinionAlert.value = true
    }, 0)
}

onMounted(() => loadArticle(title.value))

watch(route, (newRoute) => {
    if (newRoute.query.title) {
        title.value = decodeURIComponent(newRoute.query.title)
        loadArticle(title.value)
    }
})
</script>

<style scoped>
/* Custom scrollbar */
.opinions::-webkit-scrollbar {
    width: 4px;
}
.opinions::-webkit-scrollbar-track {
    @apply bg-gray-100 rounded-full;
}
.opinions::-webkit-scrollbar-thumb {
    @apply bg-gray-300 rounded-full;
}
.opinions::-webkit-scrollbar-thumb:hover {
    @apply bg-gray-400;
}
</style>
