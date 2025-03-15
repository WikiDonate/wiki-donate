<!-- eslint-disable vue/no-use-v-if-with-v-for -->
<!-- view history page -->
<template>
    <main class="w-full">
        <!-- Top bar Title -->
        <TopBarTitle :page-title="`Hello, ${authStore.user.username}!`" />
        <TopBar
            :left-menu-items="[
                {
                    name: 'User Page',
                    link: `/user/page?username=${authStore.user.username}`,
                    isAuthenticated: authStore.isAuthenticated,
                },
                {
                    name: 'Talk',
                    link: `/user/talk?username=${authStore.user.username}`,
                    isAuthenticated: authStore.isAuthenticated,
                },
            ]"
            :right-menu-items="[
                {
                    name: 'Edit Source',
                    link: `/user/page/edit-source?username=${authStore.user.username}`,
                    isAuthenticated: authStore.isAuthenticated,
                },
                {
                    name: 'View History',
                    link: `/user/page/view-history?username=${authStore.user.username}`,
                    isAuthenticated: authStore.isAuthenticated,
                },
            ]"
        />

        <!-- view history list -->
        <section class="bg-white p-2">
            <ul class="list-disc pl-5">
                <li v-for="history in revisionHistory" :key="history.uuid">
                    (<NuxtLink
                        :to="`/user/page/difference-between-revisions?username=${title}&uuid=${history.uuid}`"
                        class="underline"
                        >cur</NuxtLink
                    >
                    |
                    <NuxtLink
                        :to="`/user/page/difference-between-revisions?username=${title}&uuid=${history.uuid}`"
                        class="underline"
                        >prev</NuxtLink
                    >) - {{ history.createdAt }} update by
                    <NuxtLink
                        :to="`/user/page?username=${history.user.username}`"
                        class="underline"
                        >{{ history.user.username }}</NuxtLink
                    >
                </li>
            </ul>
        </section>
    </main>
</template>

<script setup>
import { articleService } from '~/services/articleService'

useHead({
    title: 'Revision History',
})

const articleStore = useArticleStore()
const authStore = useAuthStore()
const route = useRoute()
const title = decodeURIComponent(route.query.username)
const revisionHistory = ref({})

const loadHistory = async (slug) => {
    try {
        const response = await articleService.getHistory(slug)
        if (response.success) {
            revisionHistory.value = response.data
            articleStore.addHistory(response.data)
        } else {
            revisionHistory.value = []
            articleStore.clearHistory()
        }
    } catch (error) {
        revisionHistory.value = []
        articleStore.clearHistory()
        console.error(error)
    }
}

onMounted(async () => {
    await loadHistory(title)
})
</script>
