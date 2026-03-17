<!-- eslint-disable vue/no-v-html -->
<template>
    <div
        class="bg-gray-50 rounded-xl p-4 border border-gray-100 hover:bg-gray-100 hover:border-gray-200 transition duration-300"
    >
        <div class="flex items-start gap-3">
            <!-- Avatar -->
            <div
                class="w-10 h-10 flex-shrink-0 rounded-full flex items-center justify-center text-white font-bold text-sm"
                :style="{
                    backgroundColor: opinion.avatarColor,
                }"
            >
                {{ opinion.name.charAt(0) }}
            </div>

            <!-- Content -->
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between mb-2">
                    <div>
                        <h4
                            class="font-semibold text-gray-900 text-sm break-words"
                        >
                            {{ opinion.name }}
                        </h4>
                        <p class="text-xs text-gray-500">
                            {{ timeAgo }}
                        </p>
                    </div>
                </div>

                <!-- Opinion Content -->
                <div
                    class="prose prose-sm max-w-none text-gray-700 leading-relaxed break-words"
                >
                    <div
                        v-if="opinion.content.length > 200 && !opinion.expanded"
                    >
                        <span v-html="truncateText(opinion.content, 200)" />
                        <button
                            class="text-indigo-600 hover:text-indigo-800 text-sm font-medium ml-1"
                            @click="$emit('toggle-expand', opinion.id)"
                        >
                            See more
                        </button>
                    </div>
                    <div
                        v-else-if="
                            opinion.content.length > 200 && opinion.expanded
                        "
                    >
                        <span v-html="opinion.content" />
                        <button
                            class="text-indigo-600 hover:text-indigo-800 text-sm font-medium ml-1"
                            @click="$emit('toggle-expand', opinion.id)"
                        >
                            See less
                        </button>
                    </div>
                    <div v-else v-html="opinion.content" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    opinion: {
        type: Object,
        required: true,
    },
})

defineEmits(['toggle-expand'])

// Helper: format time ago
const getTimeAgo = (date) => {
    const now = new Date()
    const past = new Date(date)
    const seconds = Math.floor((now - past) / 1000)

    if (seconds < 60) return 'Just now'
    const minutes = Math.floor(seconds / 60)
    if (minutes < 60) return `${minutes} minute${minutes > 1 ? 's' : ''} ago`
    const hours = Math.floor(minutes / 60)
    if (hours < 24) return `${hours} hour${hours > 1 ? 's' : ''} ago`
    const days = Math.floor(hours / 24)
    if (days < 7) return `${days} day${days > 1 ? 's' : ''} ago`
    const weeks = Math.floor(days / 7)
    if (weeks < 4) return `${weeks} week${weeks > 1 ? 's' : ''} ago`
    const months = Math.floor(days / 30)
    if (months < 12) return `${months} month${months > 1 ? 's' : ''} ago`
    const years = Math.floor(days / 365)
    return `${years} year${years > 1 ? 's' : ''} ago`
}

const timeAgo = computed(() => getTimeAgo(props.opinion.createdAt))

const truncateText = (text, length) => {
    if (text.length <= length) return text
    return text.substring(0, length) + '...'
}
</script>
