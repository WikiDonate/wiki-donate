<!-- eslint-disable vue/no-use-v-if-with-v-for -->
<!-- view history page -->
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

        <!-- view history list -->
        <section class="bg-white p-2">
            <ul class="list-disc pl-5">
                <li v-for="history in revisionHistory" :key="history.uuid">
                    (<NuxtLink
                        :to="`/talk/difference-between-revisions?title=${title}&uuid=${history.uuid}`"
                        class="underline"
                        >cur</NuxtLink
                    >
                    |
                    <NuxtLink
                        :to="`/talk/difference-between-revisions?title=${title}&uuid=${history.uuid}`"
                        class="underline"
                        >prev</NuxtLink
                    >) - {{ history.createdAt }} update by
                    {{ history.user.username }}
                </li>
            </ul>
        </section>
    </main>
</template>

<script setup>
import { talkService } from '~/services/talkService'

useHead({
    title: 'Revision History',
})

const articleStore = useArticleStore()
const authStore = useAuthStore()
const talkStore = useTalkStore()
const route = useRoute()
const title = decodeURIComponent(route.query.title)
const revisionHistory = ref({})

const loadHistory = async (slug) => {
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
    }
}

onMounted(async () => {
    await loadHistory(title)
})
</script>
