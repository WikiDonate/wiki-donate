<!-- eslint-disable vue/html-self-closing -->
<!-- eslint-disable vue/no-v-html -->
<template>
    <main class="w-full">
        <!-- Top bar -->
        <TopBarTitle :page-title="`${articleTitle || title}`" />

        <div v-if="loading" class="flex items-center justify-center py-12">
            <LoadingSpinner text="Loading Article" />
        </div>

        <section v-else class="bg-white p-2 md:p-4 lg:p-6">
            <!-- Donation Formulas -->
            <div v-if="donationFormulas.length > 0">
                <h3
                    class="text-xl md:text-2xl font-bold text-gray-800 mb-4 md:mb-6 flex items-center gap-2"
                >
                    <font-awesome-icon
                        :icon="['fas', 'hand-holding-heart']"
                        class="text-indigo-600"
                    />
                    <span class="hidden sm:inline"
                        >Community Donation Formulas</span
                    >
                    <span class="sm:hidden">Donation Formulas</span>
                </h3>
                <div class="space-y-3 md:space-y-4">
                    <div
                        v-for="formula in donationFormulas"
                        :id="`formula-${formula.uuid}`"
                        :key="formula.uuid"
                        class="bg-white border border-indigo-100 rounded-xl shadow-sm"
                    >
                        <div
                            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 sm:gap-4 p-3 sm:p-4 bg-indigo-50/30 cursor-pointer hover:bg-indigo-50"
                            @click="toggleFormula(formula.uuid)"
                        >
                            <div
                                class="flex flex-wrap items-center gap-2 md:gap-3"
                            >
                                <font-awesome-icon
                                    :icon="[
                                        'fas',
                                        isFormulaOpen(formula.uuid)
                                            ? 'eye'
                                            : 'eye-slash',
                                    ]"
                                    class="text-indigo-400 text-sm"
                                />
                                <h4
                                    class="font-bold text-indigo-700 text-sm md:text-base"
                                >
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

                            <div
                                class="flex items-center gap-3 sm:gap-4 pb-1 sm:pb-0"
                            >
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
                                                    copiedUuid === formula.uuid
                                                "
                                                class="absolute -top-10 left-1/2 -translate-x-1/2 bg-gray-800 text-white text-xs px-2 py-1 rounded shadow-xl whitespace-nowrap z-50"
                                            >
                                                Copied!
                                            </div>
                                        </transition>
                                        <button
                                            class="text-indigo-400 hover:text-indigo-600 p-1"
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
                                            class="text-indigo-600 hover:text-indigo-800 p-1"
                                            title="Edit Formula"
                                            @click="
                                                openEditFormulaModal(formula)
                                            "
                                        >
                                            <font-awesome-icon
                                                :icon="['fas', 'edit']"
                                            />
                                        </button>
                                        <button
                                            class="text-red-500 hover:text-red-700 p-1"
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
                            class="p-3 sm:p-4 border-t border-indigo-50"
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
                                    <span class="font-semibold text-indigo-600"
                                        >{{ item.percentage }}%</span
                                    >
                                </div>
                                <!-- Total Row -->
                                <div
                                    class="flex justify-between items-center pt-2 font-bold text-gray-800"
                                >
                                    <span>Total</span>
                                    <span class="text-indigo-700">100%</span>
                                </div>
                                <!-- Details -->
                                <div
                                    v-if="formula.details"
                                    class="mt-3 pt-2 border-t border-gray-100"
                                >
                                    <p
                                        class="text-xs text-gray-500 font-medium mb-1"
                                    >
                                        Details:
                                    </p>
                                    <p class="text-sm text-gray-700 italic">
                                        {{ formula.details }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-else
                class="text-center py-10 bg-gray-50 rounded-xl border border-dashed border-gray-300"
            >
                <font-awesome-icon
                    :icon="['fas', 'info-circle']"
                    class="text-gray-400 text-3xl mb-3"
                />
                <p class="text-gray-500">
                    No donation formulas created yet.
                    <template v-if="authStore.isAuthenticated"
                        >Be the first to create one!</template
                    >
                    <template v-else>
                        <NuxtLink
                            to="/login"
                            class="text-indigo-600 font-semibold hover:underline"
                            >Log in to create one!</NuxtLink
                        >
                    </template>
                </p>
            </div>

            <!-- Create Button -->
            <div
                v-if="authStore.isAuthenticated"
                class="flex justify-center lg:justify-end items-center mt-5"
            >
                <button
                    class="flex items-center gap-2 text-base lg:text-lg bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold py-2 px-4 rounded-lg hover:from-indigo-500 hover:to-purple-500 shadow-md"
                    @click="openCreateFormulaModal"
                >
                    <font-awesome-icon
                        :icon="['fas', 'plus-circle']"
                        class="w-5 h-5"
                    />
                    <span>Create Donation Formula</span>
                </button>
            </div>
        </section>

        <!-- Modals -->
        <DonationFormulaModal
            v-model="showDonationModal"
            :initial-data="selectedFormula"
            :is-saving="submittingFormula"
            :is-edit="!!selectedFormula.uuid"
            @save="handleSaveDonationFormula"
        />

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

useHead({ title: 'Article' })

const articleStore = useArticleStore()
const authStore = useAuthStore()
const route = useRoute()
const { clearToasts, notifySuccess, notifyError } = useToastify()

const title = ref(decodeURIComponent(route.query.title))
const articleTitle = ref('')
const sections = ref([])
const loading = ref(false)
const submittingFormula = ref(false)
const submittingDelete = ref(false)

const donationFormulas = ref([])
const openFormulas = ref([])
const showDonationModal = ref(false)
const showConfirmDelete = ref(false)
const formulaToDelete = ref(null)
const selectedFormula = ref({ formula: [] })
const copiedUuid = ref(null)

const copyFormulaUrl = (uuid) => {
    const url = new URL(window.location.href)
    url.hash = `formula-${uuid}`
    navigator.clipboard.writeText(url.toString())
    copiedUuid.value = uuid
    setTimeout(() => {
        if (copiedUuid.value === uuid) copiedUuid.value = null
    }, 2000)
}

const getUserFormulaIndex = (currentFormula) => {
    const userFormulas = donationFormulas.value.filter(
        (f) => f.user?.uuid === currentFormula.user?.uuid
    )
    return userFormulas.findIndex((f) => f.uuid === currentFormula.uuid) + 1
}

const toggleFormula = (uuid) => {
    const index = openFormulas.value.indexOf(uuid)
    if (index > -1) {
        openFormulas.value.splice(index, 1)
    } else {
        openFormulas.value.push(uuid)
    }
}

const isFormulaOpen = (uuid) => openFormulas.value.includes(uuid)

const openCreateFormulaModal = () => {
    selectedFormula.value = { formula: [{ organization: '', percentage: 0 }] }
    showDonationModal.value = true
}

const openEditFormulaModal = (formula) => {
    selectedFormula.value = JSON.parse(JSON.stringify(formula))
    showDonationModal.value = true
}

const handleSaveDonationFormula = async (data) => {
    if (submittingFormula.value) return
    submittingFormula.value = true
    clearToasts()
    try {
        let response
        if (selectedFormula.value.uuid) {
            response = await articleService.updateDonationFormula(
                selectedFormula.value.uuid,
                { formula: data.formula, details: data.details }
            )
        } else {
            response = await articleService.saveDonationFormula({
                article_slug: articleStore.article.slug,
                formula: data.formula,
                details: data.details,
            })
        }

        if (response.success) {
            showDonationModal.value = false
            notifySuccess(
                selectedFormula.value.uuid
                    ? 'Donation formula updated!'
                    : 'Donation formula created!'
            )
            await loadDonationFormulas(
                articleStore.article.slug,
                response.data.uuid
            )
        } else {
            throw new Error(response.message || 'Failed to save')
        }
    } catch (error) {
        notifyError(error.message || 'Unexpected error')
    } finally {
        submittingFormula.value = false
    }
}

const handleDeleteFormula = (uuid) => {
    formulaToDelete.value = uuid
    showConfirmDelete.value = true
}

const handleConfirmDelete = async () => {
    if (!formulaToDelete.value || submittingDelete.value) return
    submittingDelete.value = true
    clearToasts()
    try {
        const response = await articleService.deleteDonationFormula(
            formulaToDelete.value
        )
        if (response.success) {
            showConfirmDelete.value = false
            formulaToDelete.value = null
            notifySuccess('Donation formula deleted!')
            await loadDonationFormulas(articleStore.article.slug)
        } else {
            throw new Error(response.message || 'Failed to delete')
        }
    } catch (error) {
        notifyError(error.message || 'Failed to delete')
    } finally {
        submittingDelete.value = false
    }
}

const loadArticle = async (slug) => {
    loading.value = true
    try {
        const response = await articleService.getArticle(slug)
        if (response.success) {
            articleTitle.value = response.data.title
            sections.value = JSON.parse(response.data.sections)
            articleStore.addArticle(response.data)
            useHead({ title: `${articleTitle.value} - WikiDonate` })
            await loadDonationFormulas(response.data.slug)
        } else {
            sections.value = []
            articleStore.clearArticle()
        }
    } catch {
        sections.value = []
        articleStore.clearArticle()
    } finally {
        loading.value = false
    }
}

const loadDonationFormulas = async (slug, uuidToOpen = null) => {
    try {
        const response = await articleService.getDonationFormulasByArticle(slug)
        if (response.success && response.data) {
            donationFormulas.value = response.data
            openFormulas.value = uuidToOpen ? [uuidToOpen] : []

            if (!uuidToOpen && route.hash?.startsWith('#formula-')) {
                const uuid = route.hash.replace('#formula-', '')
                if (response.data.some((f) => f.uuid === uuid)) {
                    openFormulas.value = [uuid]
                    setTimeout(() => {
                        document
                            .getElementById(`formula-${uuid}`)
                            ?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center',
                            })
                    }, 500)
                }
            }
        } else {
            donationFormulas.value = []
            openFormulas.value = []
        }
    } catch {
        donationFormulas.value = []
        openFormulas.value = []
    }
}

onMounted(() => loadArticle(title.value))

watch(route, (newRoute) => {
    if (newRoute.query.title) {
        title.value = decodeURIComponent(newRoute.query.title)
        loadArticle(decodeURIComponent(newRoute.query.title))
    }
})
</script>
