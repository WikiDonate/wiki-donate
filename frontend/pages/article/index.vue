<!-- eslint-disable vue/html-self-closing -->
<!-- eslint-disable vue/no-v-html -->
<template>
    <main class="w-full">
        <!-- Top bar -->
        <TopBarTitle :page-title="`${articleTitle || title}`" />

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
            <div v-if="sections.length === 0">
                <div v-if="authStore.isAuthenticated">
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
                <div
                    v-else
                    class="text-center py-20 bg-gray-50 rounded-xl border border-dashed border-gray-300"
                >
                    <font-awesome-icon
                        :icon="['fas', 'file-alt']"
                        class="text-gray-400 text-5xl mb-4"
                    />
                    <h2 class="text-2xl font-semibold text-gray-700 mb-2">
                        No Content Yet
                    </h2>
                    <p class="text-gray-500 max-w-md mx-auto">
                        This article doesn't have any content yet. Please log in
                        to contribute or check back later.
                    </p>
                    <div class="mt-6">
                        <NuxtLink
                            to="/login"
                            class="inline-flex items-center gap-2 bg-indigo-600 text-white px-6 py-2 rounded-lg font-semibold hover:bg-indigo-700 transition-colors"
                        >
                            Log In to Edit
                        </NuxtLink>
                    </div>
                </div>
            </div>
            <div v-else>
                <!-- Sections -->
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
                    </div>
                    <div
                        class="text-gray-700 leading-relaxed break-words break-all"
                        v-html="item.content"
                    />
                    <div
                        v-if="index < sections.length - 1"
                        class="border-t border-indigo-300 mt-6 pt-6"
                    />
                </div>

                <!-- Donation Formulas List (Publicly visible) -->
                <div v-if="donationFormulas.length > 0" class="mt-12">
                    <h3
                        class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'hand-holding-heart']"
                            class="text-indigo-600"
                        />
                        Community Donation Formulas
                    </h3>
                    <div class="space-y-4">
                        <div
                            v-for="formula in donationFormulas"
                            :id="`formula-${formula.uuid}`"
                            :key="formula.uuid"
                            class="bg-white border border-indigo-100 rounded-xl shadow-sm transition-all duration-500"
                        >
                            <!-- Accordion Header -->
                            <div
                                class="flex justify-between items-center p-4 bg-indigo-50/30 cursor-pointer hover:bg-indigo-50 transition-colors"
                                @click="toggleFormula(formula.uuid)"
                            >
                                <div class="flex items-center gap-3">
                                    <font-awesome-icon
                                        :icon="[
                                            'fas',
                                            isFormulaOpen(formula.uuid)
                                                ? 'eye'
                                                : 'eye-slash',
                                        ]"
                                        class="text-indigo-400 text-sm"
                                    />
                                    <h4 class="font-bold text-indigo-700">
                                        {{ formula.user?.username }}:
                                        {{ getUserFormulaIndex(formula) }}
                                    </h4>
                                    <Button
                                        width="auto"
                                        class="!py-1 !px-2 !text-xs !rounded-lg !bg-green-600 hover:!bg-green-700 border-none shadow-sm flex items-center gap-1"
                                        @click.stop=""
                                    >
                                        <font-awesome-icon
                                            :icon="['fas', 'heart']"
                                            class="w-3 h-3"
                                        />
                                        <span>Donate</span>
                                    </Button>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex items-center gap-4">
                                    <div class="flex gap-2" @click.stop>
                                        <div class="relative">
                                            <transition
                                                enter-active-class="transition duration-200 ease-out"
                                                enter-from-class="transform -translate-y-1 opacity-0"
                                                enter-to-class="transform translate-y-0 opacity-100"
                                                leave-active-class="transition duration-150 ease-in"
                                                leave-from-class="opacity-100"
                                                leave-to-class="opacity-0"
                                            >
                                                <div
                                                    v-if="
                                                        copiedUuid ===
                                                        formula.uuid
                                                    "
                                                    class="absolute -top-10 left-1/2 -translate-x-1/2 bg-gray-800 text-white text-[12px] px-2 py-1 rounded shadow-2xl whitespace-nowrap z-50 animate-bounce"
                                                >
                                                    Copied!
                                                    <div
                                                        class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-gray-800 rotate-45"
                                                    ></div>
                                                </div>
                                            </transition>
                                            <button
                                                class="text-indigo-400 hover:text-indigo-600 p-1 transition-colors"
                                                title="Copy Formula URL"
                                                @click="
                                                    copyFormulaUrl(formula.uuid)
                                                "
                                            >
                                                <font-awesome-icon
                                                    :icon="['fas', 'link']"
                                                />
                                            </button>
                                        </div>
                                        <template
                                            v-if="
                                                authStore.user?.username ===
                                                formula.user?.username
                                            "
                                        >
                                            <button
                                                class="text-indigo-600 hover:text-indigo-800 p-1 transition-colors"
                                                title="Edit Formula"
                                                @click="
                                                    openEditFormulaModal(
                                                        formula
                                                    )
                                                "
                                            >
                                                <font-awesome-icon
                                                    :icon="['fas', 'edit']"
                                                />
                                            </button>
                                            <button
                                                class="text-red-500 hover:text-red-700 p-1 transition-colors"
                                                title="Delete Formula"
                                                @click="
                                                    handleDeleteFormula(
                                                        formula.uuid
                                                    )
                                                "
                                            >
                                                <font-awesome-icon
                                                    :icon="['fas', 'trash-alt']"
                                                />
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <!-- Accordion Body -->
                            <div
                                v-show="isFormulaOpen(formula.uuid)"
                                class="p-4 border-t border-indigo-50"
                            >
                                <div class="space-y-2">
                                    <div
                                        v-for="(item, i) in formula.formula"
                                        :key="i"
                                        class="flex justify-between items-center text-sm border-b border-gray-50 pb-2"
                                    >
                                        <span class="text-gray-700">{{
                                            item.organization
                                        }}</span>
                                        <span
                                            class="font-semibold text-indigo-600"
                                            >{{ item.percentage }}%</span
                                        >
                                    </div>
                                    <!-- Total Row -->
                                    <div
                                        class="flex justify-between items-center pt-2 font-bold text-gray-800"
                                    >
                                        <span>Total</span>
                                        <span class="text-indigo-700"
                                            >100%</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    v-else-if="!loading"
                    class="mt-12 text-center py-10 bg-gray-50 rounded-xl border border-dashed border-gray-300"
                >
                    <font-awesome-icon
                        :icon="['fas', 'info-circle']"
                        class="text-gray-400 text-3xl mb-3"
                    />
                    <p class="text-gray-500">
                        No donation formulas created yet.
                        <template v-if="authStore.isAuthenticated">
                            Be the first to create one!
                        </template>
                        <template v-else>
                            <NuxtLink
                                to="/login"
                                class="text-indigo-600 font-semibold hover:underline"
                            >
                                Log in to create one!
                            </NuxtLink>
                        </template>
                    </p>
                </div>

                <!-- Create Donation Formula Button -->
                <div
                    v-if="authStore.isAuthenticated"
                    class="flex justify-center lg:justify-end items-center mt-5"
                >
                    <button
                        class="flex items-center gap-2 text-base lg:text-lg bg-gradient-to-r from-indigo-600 to-purple-600 transition-all duration-300 text-white font-semibold py-2 px-4 rounded-lg hover:from-indigo-500 hover:to-purple-500 shadow-md whitespace-nowrap"
                        @click="openCreateFormulaModal"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'plus-circle']"
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
            :initial-data="selectedFormula"
            :is-saving="submittingFormula"
            :is-edit="!!selectedFormula.uuid"
            @save="handleSaveDonationFormula"
        />

        <!-- Confirm Modal for Deletion -->
        <ConfirmModal
            v-model="showConfirmDelete"
            title="Delete Donation Formula"
            message-title="Confirm Deletion"
            message="Are you sure you want to delete this donation formula? This action cannot be undone."
            confirm-text="Delete Formula"
            :is-loading="submittingDelete"
            @confirm="handleConfirmDelete"
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
const submittingFormula = ref(false)
const submittingDelete = ref(false)
const accessType = ref('public')

// Donation formulas list
const donationFormulas = ref([])
const openFormulas = ref([])
const showDonationModal = ref(false)
const showConfirmDelete = ref(false)
const formulaToDelete = ref(null)
const selectedFormula = ref({ formula: [] })
const focusedFormula = ref(null)
const copiedUuid = ref(null)

const copyFormulaUrl = (uuid) => {
    const url = new URL(window.location.href)
    url.hash = `formula-${uuid}`
    navigator.clipboard.writeText(url.toString())

    // Show local tooltip
    copiedUuid.value = uuid
    setTimeout(() => {
        if (copiedUuid.value === uuid) {
            copiedUuid.value = null
        }
    }, 2000)
}

/**
 * Returns the index of a formula among all formulas from the same user
 */
const getUserFormulaIndex = (currentFormula) => {
    const userFormulas = donationFormulas.value.filter(
        (f) => f.user?.uuid === currentFormula.user?.uuid
    )
    const index = userFormulas.findIndex((f) => f.uuid === currentFormula.uuid)
    return index + 1
}

const toggleFormula = (uuid) => {
    const index = openFormulas.value.indexOf(uuid)
    if (index > -1) {
        openFormulas.value.splice(index, 1)
    } else {
        openFormulas.value.push(uuid)
    }
}

const isFormulaOpen = (uuid) => {
    return openFormulas.value.includes(uuid)
}

// check if current user is owner of the article
const isOwner = computed(() => {
    return (
        authStore.user &&
        authStore.user?.uuid === articleStore.article?.user?.uuid
    )
})

// computed: can user edit the article?
const canEdit = computed(() => {
    if (!authStore.isAuthenticated || !editable.value) return false

    if (accessType.value === 'public') return true
    if (accessType.value === 'private' && isOwner.value) return true

    return false
})

const openCreateFormulaModal = () => {
    selectedFormula.value = {
        formula: [{ organization: '', percentage: 0 }],
    }
    showDonationModal.value = true
}

const openEditFormulaModal = (formula) => {
    selectedFormula.value = JSON.parse(JSON.stringify(formula))
    showDonationModal.value = true
}

/**
 * Handles the saving of a donation formula from the modal
 */
const handleSaveDonationFormula = async (data) => {
    if (submittingFormula.value) return
    submittingFormula.value = true
    showAlert.value = false
    try {
        let response
        if (selectedFormula.value.uuid) {
            // Update existing
            response = await articleService.updateDonationFormula(
                selectedFormula.value.uuid,
                {
                    formula: data.formula,
                }
            )
        } else {
            // Create new
            response = await articleService.saveDonationFormula({
                article_slug: articleStore.article.slug,
                formula: data.formula,
            })
        }

        if (response.success) {
            alertVariant.value = 'success'
            alertMessage.value = selectedFormula.value.uuid
                ? 'Donation formula updated successfully!'
                : 'Donation formula created successfully!'
            showDonationModal.value = false
            // Refresh the list and open the new/updated formula
            await loadDonationFormulas(
                articleStore.article.slug,
                response.data.uuid
            )
        } else {
            throw new Error(
                response.message || 'Failed to save donation formula'
            )
        }
    } catch (error) {
        console.error(error)
        alertVariant.value = 'error'
        alertMessage.value = error.message || 'Unexpected error'
    } finally {
        submittingFormula.value = false
        showAlert.value = true
    }
}

const handleDeleteFormula = (uuid) => {
    formulaToDelete.value = uuid
    showConfirmDelete.value = true
}

const handleConfirmDelete = async () => {
    if (!formulaToDelete.value || submittingDelete.value) return

    submittingDelete.value = true
    showAlert.value = false
    try {
        const response = await articleService.deleteDonationFormula(
            formulaToDelete.value
        )
        if (response.success) {
            alertVariant.value = 'success'
            alertMessage.value = 'Donation formula deleted successfully!'
            showConfirmDelete.value = false
            formulaToDelete.value = null
            // Refresh the list
            await loadDonationFormulas(articleStore.article.slug)
        } else {
            throw new Error(response.message || 'Failed to delete formula')
        }
    } catch (error) {
        console.error(error)
        alertVariant.value = 'error'
        alertMessage.value = error.message || 'Failed to delete formula'
    } finally {
        submittingDelete.value = false
        showAlert.value = true
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

        // Save article
        const params = {
            title: title.value,
            content: editorContent.value,
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

            // Update page title
            useHead({
                title: `${articleTitle.value} - WikiDonate`,
                meta: [
                    {
                        name: 'description',
                        content: `Read about ${articleTitle.value} on WikiDonate.`,
                    },
                ],
            })

            // Load donation formulas
            await loadDonationFormulas(response.data.slug)
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
const loadDonationFormulas = async (slug, uuidToOpen = null) => {
    try {
        const response = await articleService.getDonationFormulasByArticle(slug)
        if (response.success && response.data) {
            donationFormulas.value = response.data
            // Keep formulas closed by default
            openFormulas.value = []

            if (uuidToOpen) {
                openFormulas.value = [uuidToOpen]
            }

            // Check if there's a specific formula in the hash to focus on (only if not manually opening one)
            const hash = route.hash
            if (!uuidToOpen && hash && hash.startsWith('#formula-')) {
                const uuid = hash.replace('#formula-', '')
                const formulaExists = response.data.some((f) => f.uuid === uuid)
                if (formulaExists) {
                    openFormulas.value = [uuid]
                    focusedFormula.value = uuid
                    // Scroll to it after a short delay to ensure rendering and images are loaded
                    setTimeout(() => {
                        const el = document.getElementById(`formula-${uuid}`)
                        if (el) {
                            el.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center',
                            })
                        }
                    }, 500)
                }
            }
        } else {
            donationFormulas.value = []
            openFormulas.value = []
        }
    } catch (error) {
        console.error('Error loading donation formulas:', error)
        donationFormulas.value = []
        openFormulas.value = []
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
