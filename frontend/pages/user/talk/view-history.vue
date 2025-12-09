<!-- eslint-disable vue/no-use-v-if-with-v-for -->
<!-- view history page -->
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

        <!-- loader -->
        <div v-if="loading" class="flex items-center justify-center py-12">
            <LoadingSpinner text="Loading History" />
        </div>

        <!-- view history list -->
        <section v-else class="bg-white rounded-2xl shadow-md p-4 sm:p-6 mt-4">
            <div
                v-if="revisionHistory.length"
                class="space-y-4 divide-y divide-gray-200"
            >
                <div
                    v-for="history in revisionHistory"
                    :key="history.uuid"
                    class="pt-4 first:pt-0"
                >
                    <!-- Links row -->
                    <div
                        class="flex items-center justify-between flex-wrap gap-2"
                    >
                        <div class="text-sm space-x-2">
                            (<NuxtLink
                                :to="`/user/talk/difference-between-revisions?username=${title}&uuid=${history.uuid}`"
                                class="text-indigo-600 hover:text-purple-600 underline"
                                >cur</NuxtLink
                            >
                            |
                            <NuxtLink
                                :to="`/user/talk/difference-between-revisions?username=${title}&uuid=${history.uuid}`"
                                class="text-indigo-600 hover:text-purple-600 underline"
                                >prev</NuxtLink
                            >)
                        </div>

                        <!-- Date -->
                        <span
                            class="text-gray-500 text-xs sm:text-sm"
                            :title="history.createdAt"
                        >
                            {{ formatDateUTC(history.createdAt) }}
                        </span>
                    </div>

                    <!-- User info -->
                    <p class="mt-1 text-sm">
                        Update by
                        <NuxtLink
                            :to="`/user/page?username=${history.user.username}`"
                            class="font-semibold text-indigo-600 hover:text-purple-600"
                            >{{ history.user.username }}</NuxtLink
                        >
                    </p>
                </div>
            </div>

            <p v-else class="text-center text-gray-500">
                No revision history found.
            </p>
        </section>
    </main>
</template>

<script setup>
import { talkService } from '~/services/talkService'
import { formatDateUTC } from '~/utils/dateFormatUTC'

useHead({
    title: 'Revision History',
})

const talkStore = useTalkStore()
const route = useRoute()
const title = decodeURIComponent(route.query.username)
const revisionHistory = ref({})
const loading = ref(false)

const loadHistory = async (slug) => {
    loading.value = true
    try {
        const response = await talkService.getHistory(slug)
        if (response.success) {
            revisionHistory.value = response.data
            talkStore.addHistory(response.data)
        } else {
            revisionHistory.value = []
            talkStore.clearHistory()
        }
    } catch (error) {
        revisionHistory.value = []
        talkStore.clearHistory()
        console.error(error)
    } finally {
        loading.value = false
    }
}

onMounted(async () => {
    await loadHistory(title)
})
</script>
