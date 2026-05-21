<template>
    <main>
        <div
            class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden"
        >
            <AdminPageHeader
                title="How It Works"
                subtitle="Edit the steps shown on the How It Works page"
                :card="true"
            >
                <template #actions>
                    <button
                        class="px-4 py-2 bg-white/20 hover:bg-white/30 text-white text-sm font-medium rounded-lg transition-colors"
                        @click="addStep"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'plus']"
                            class="w-3.5 h-3.5 mr-1.5"
                        />
                        Add Step
                    </button>
                </template>
            </AdminPageHeader>

            <LoadingSpinner v-if="loading" text="Loading page content..." />

            <template v-else>
                <div class="p-6 space-y-6">
                    <div
                        v-for="(step, index) in steps"
                        :key="index"
                        class="border border-gray-200 rounded-lg p-4 relative"
                    >
                        <div class="flex items-start justify-between mb-3">
                            <span
                                class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white text-xs font-bold"
                            >
                                {{ index + 1 }}
                            </span>
                            <button
                                class="text-gray-400 hover:text-red-600 transition-colors p-1"
                                title="Remove step"
                                @click="removeStep(index)"
                            >
                                <font-awesome-icon
                                    :icon="['fas', 'times']"
                                    class="w-4 h-4"
                                />
                            </button>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <label
                                    class="block text-sm font-medium text-gray-700 mb-1"
                                >
                                    Icon
                                </label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="ico in availableIcons"
                                        :key="ico.join('-')"
                                        class="w-9 h-9 rounded-lg border-2 flex items-center justify-center transition-colors"
                                        :class="
                                            step.icon &&
                                            step.icon[0] === ico[0] &&
                                            step.icon[1] === ico[1]
                                                ? 'border-indigo-500 bg-indigo-50 text-indigo-600'
                                                : 'border-gray-200 text-gray-400 hover:border-gray-300 hover:text-gray-600'
                                        "
                                        :title="ico[1]"
                                        @click="step.icon = ico"
                                    >
                                        <font-awesome-icon
                                            :icon="ico"
                                            class="w-4 h-4"
                                        />
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label
                                    class="block text-sm font-medium text-gray-700 mb-1"
                                >
                                    Title
                                </label>
                                <input
                                    v-model="step.title"
                                    type="text"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                                    placeholder="Step title"
                                />
                            </div>
                            <div>
                                <label
                                    class="block text-sm font-medium text-gray-700 mb-1"
                                >
                                    Description
                                </label>
                                <textarea
                                    v-model="step.rawDescription"
                                    rows="3"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none resize-y"
                                    placeholder="Step description. Use [[link]] for clickable article links."
                                ></textarea>
                                <p class="text-xs text-gray-400 mt-1">
                                    Use
                                    <code class="text-indigo-500"
                                        >[[article-title]]</code
                                    >
                                    to create a link to an article.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="steps.length === 0"
                        class="text-center py-8 text-gray-400"
                    >
                        No steps yet. Click "Add Step" to create one.
                    </div>
                </div>

                <div
                    class="px-6 py-4 border-t border-gray-200 flex items-center justify-end gap-3"
                >
                    <Button
                        variant="secondary"
                        text="Reset"
                        width="auto"
                        class="px-4"
                        @click="resetForm"
                    />
                    <Button
                        variant="primary"
                        :text="saving ? 'Saving...' : 'Save Changes'"
                        width="auto"
                        class="px-4"
                        :disabled="saving"
                        @click="saveContent"
                    />
                </div>
            </template>
        </div>
    </main>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { adminService } from '~/services/adminService'
import { useToastify } from '~/composables/useToastify'

const { notifySuccess, notifyError } = useToastify()

useHead({ title: 'Admin - How It Works' })
definePageMeta({ layout: 'admin', middleware: ['auth', 'admin'] })

const loading = ref(true)
const saving = ref(false)
const steps = ref([])
const originalSteps = ref([])

const availableIcons = [
    ['fas', 'search'],
    ['fas', 'calculator'],
    ['fas', 'donate'],
    ['fas', 'lightbulb'],
    ['fas', 'pen'],
    ['fas', 'hand-holding-heart'],
    ['fas', 'globe'],
    ['fas', 'users'],
    ['fas', 'star'],
    ['fas', 'heart'],
    ['fas', 'book-open'],
    ['fas', 'bell'],
    ['fas', 'flag'],
    ['fas', 'rocket'],
    ['fas', 'cog'],
    ['fas', 'handshake'],
]

function parseDescriptionToRaw(description) {
    return description
        .map((seg) => (seg.type === 'link' ? `[[${seg.value}]]` : seg.value))
        .join('')
}

function parseRawToDescription(raw) {
    const segments = []
    const regex = /\[\[([^\]]+)\]\]/g
    let lastIndex = 0
    let match

    while ((match = regex.exec(raw)) !== null) {
        if (match.index > lastIndex) {
            segments.push({
                type: 'text',
                value: raw.slice(lastIndex, match.index),
            })
        }
        segments.push({ type: 'link', value: match[1] })
        lastIndex = regex.lastIndex
    }

    if (lastIndex < raw.length) {
        segments.push({ type: 'text', value: raw.slice(lastIndex) })
    }

    return segments
}

const addStep = () => {
    steps.value.push({
        icon: ['fas', 'search'],
        title: '',
        rawDescription: '',
    })
}

const removeStep = (index) => {
    steps.value.splice(index, 1)
}

const resetForm = () => {
    steps.value = originalSteps.value.map((s) => ({ ...s }))
}

const saveContent = async () => {
    const content = steps.value.map((step) => ({
        title: step.title,
        icon: step.icon || ['fas', 'search'],
        description: parseRawToDescription(step.rawDescription),
    }))

    saving.value = true
    try {
        const res = await adminService.updatePageContent(
            'how-it-works',
            content
        )
        if (res.success) {
            notifySuccess('How It Works page updated successfully')
            originalSteps.value = steps.value.map((s) => ({
                icon: s.icon,
                title: s.title,
                rawDescription: s.rawDescription,
            }))
        }
    } catch (error) {
        notifyError(error.errors?.[0] || 'Failed to save page content')
    } finally {
        saving.value = false
    }
}

onMounted(async () => {
    try {
        const res = await adminService.getPageContent('how-it-works')
        if (res.success) {
            steps.value = res.data.content.map((step) => ({
                icon: step.icon || ['fas', 'search'],
                title: step.title,
                rawDescription: parseDescriptionToRaw(step.description),
            }))
            originalSteps.value = steps.value.map((s) => ({
                icon: s.icon,
                title: s.title,
                rawDescription: s.rawDescription,
            }))
        }
    } catch {
        notifyError('Failed to load page content')
    } finally {
        loading.value = false
    }
})
</script>
