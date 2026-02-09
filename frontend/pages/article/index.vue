<!-- eslint-disable vue/html-self-closing -->
<!-- eslint-disable vue/no-v-html -->
<template>
    <main class="w-full">
        <!-- Top bar -->
        <TopBarTitle :page-title="`${articleTitle || title}`" />
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
                        '/article/edit-source?title=' +
                        encodeURIComponent(title),
                    isAuthenticated: true,
                },
                {
                    name: 'View History',
                    link:
                        '/article/view-history?title=' +
                        encodeURIComponent(title),
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
            <LoadingSpinner text="Loading Article" />
        </div>

        <!-- article page -->
        <section v-else class="bg-white p-2">
            <div v-if="sections.length === 0 && authStore.isAuthenticated">
                <div class="mt-2 w-full flex justify-start items-center">
                    <!-- Dropdown for access -->
                    <div
                        class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 sm:gap-6"
                    >
                        <label
                            class="text-base sm:text-lg font-semibold text-gray-900 whitespace-nowrap"
                        >
                            Donation Formula Page
                        </label>

                        <div class="flex flex-wrap gap-4 sm:gap-6 items-center">
                            <label
                                class="flex items-center gap-2 cursor-pointer"
                            >
                                <input
                                    v-model="accessType"
                                    type="radio"
                                    value="private"
                                    class="accent-indigo-600"
                                />
                                <span class="text-sm sm:text-base text-gray-700"
                                    >Yes</span
                                >
                            </label>
                            <label
                                class="flex items-center gap-2 cursor-pointer"
                            >
                                <input
                                    v-model="accessType"
                                    type="radio"
                                    value="public"
                                    class="accent-indigo-600"
                                />
                                <span class="text-sm sm:text-base text-gray-700"
                                    >No</span
                                >
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <QuillEditor v-model:content="editorContent" />
                </div>

                <div class="w-40 mt-4">
                    <FormSubmitButton
                        :text="submitting ? 'Saving...' : 'Save Article'"
                        :disabled="submitting"
                        @click="handleSubmit"
                    />
                </div>
            </div>
            <div v-else>
                <!-- Sections -->
                <div
                    v-for="(item, index) in sections"
                    :key="`section-${index}`"
                >
                    <div class="flex justify-between">
                        <!-- Article -->
                        <div class="flex flex-col justify-between items-start">
                            <div
                                class="font-semibold text-lg mb-2 break-words break-all text-gray-900"
                                v-html="item.title"
                            />
                            <div
                                class="text-gray-700 leading-relaxed break-words break-all"
                                v-html="item.content"
                            />
                        </div>

                        <!-- Buttons -->
                        <div class="flex flex-col gap-1 justify-end items-end">
                            <NuxtLink
                                v-if="canEdit"
                                :to="`/article/edit-section?title=${title}&uuid=${index}`"
                                exact
                                class="flex items-center gap-1 text-xs sm:text-sm bg-gradient-to-r from-indigo-600 to-purple-600 transition-all duration-300 text-white font-semibold py-1 px-2 rounded-lg hover:from-indigo-500 hover:to-purple-500 shadow-sm whitespace-nowrap"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'pen']"
                                    class="w-3 h-3"
                                />
                                <span class="hidden sm:inline">Edit</span>
                            </NuxtLink>
                            <NuxtLink
                                class="flex items-center gap-1 text-xs sm:text-sm bg-gradient-to-r from-indigo-600 to-purple-600 transition-all duration-300 text-white font-semibold py-1 px-2 rounded-lg hover:from-indigo-500 hover:to-purple-500 shadow-sm whitespace-nowrap"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'money-bill']"
                                    class="w-3 h-3"
                                />
                                <span class="hidden sm:inline"
                                    >Create Donation Formula</span
                                >
                            </NuxtLink>
                        </div>
                    </div>

                    <div
                        v-if="index < sections.length - 1"
                        class="border-t border-indigo-300 mt-6 pt-6"
                    />
                </div>

                <!-- Donation Formula-->
                <div
                    class="flex justify-center lg:justify-end items-center mt-10"
                >
                    <button
                        class="flex items-center gap-1 text-xl lg:text-lg bg-gradient-to-r from-indigo-600 to-purple-600 transition-all duration-300 text-white font-semibold py-1 px-2 rounded-lg hover:from-indigo-500 hover:to-purple-500 shadow-sm whitespace-nowrap"
                        @click="showDonationModal = true"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'money-bill']"
                            class="w-5 h-5"
                        />
                        <span>Create Donation Formula</span>
                    </button>
                </div>
            </div>
        </section>

        <!-- Donation Formula Modal -->
        <DonationFormulaModal
            v-model="showDonationModal"
            @save="handleSaveDonationFormula"
        />
    </main>
</template>

<script setup>
import { articleService } from '~/services/articleService'

useHead({
    title: 'Article',
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
const sections = ref([])
const editable = ref(false)
const loading = ref(false)
const submitting = ref(false)
const accessType = ref('public')

// Donation formula modal visibility state
const showDonationModal = ref(false)

// check if current user is owner
const isOwner = computed(() => {
    return (
        authStore.user &&
        authStore.user?.uuid === articleStore.article?.user?.uuid
    )
})

// computed: can user edit?
const canEdit = computed(() => {
    if (!authStore.isAuthenticated || !editable.value) return false

    if (accessType.value === 'public') return true
    if (accessType.value === 'private' && isOwner.value) return true

    return false
})

/**
 * Handles the saving of a donation formula from the modal
 * @param {Array} data - List of organizations and their percentage allocations
 */
const handleSaveDonationFormula = (data) => {
    console.log('Donation Formula Saved:', data)
    // TODO: Integrate with backend API to persist the formula
    alertVariant.value = 'success'
    alertMessage.value = 'Donation formula saved successfully!'
    showAlert.value = true
}

const handleSubmit = async () => {
    if (submitting.value) return
    submitting.value = true
    showAlert.value = false

    try {
        if (!editorContent.value) {
            throw new Error('Please enter some content')
        }

        // Save article
        const params = {
            title: title.value,
            content: editorContent.value,
            accessType: accessType.value,
        }

        const response = await articleService.saveArticle(params)
        if (!response.success) {
            throw new Error(response.errors?.[0] || 'Failed to save article')
        }

        alertVariant.value = 'success'
        alertMessage.value = response.message
        setTimeout(() => {
            showAlert.value = true
        }, 0)

        await loadArticle(response.data.slug)
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

const loadArticle = async (slug) => {
    loading.value = true
    try {
        const response = await articleService.getArticle(slug)
        if (response.success) {
            articleTitle.value = response.data.title
            sections.value = JSON.parse(response.data.sections)
            editable.value = response.data.editable
            accessType.value = response.data.accessType
            articleStore.addArticle(response.data)
        } else {
            sections.value = []
            editable.value = false
            accessType.value = 'public'
            articleStore.clearArticle()
        }
    } catch (error) {
        sections.value = []
        editable.value = false
        accessType.value = 'public'
        articleStore.clearArticle()
        console.error(error)
    } finally {
        loading.value = false
    }
}

onMounted(async () => {
    await loadArticle(title.value)
})

watch(route, (newRoute) => {
    if (newRoute.query.title) {
        title.value = decodeURIComponent(newRoute.query.title)
        loadArticle(decodeURIComponent(newRoute.query.title))
    }
})
</script>
