<!-- eslint-disable vue/no-use-v-if-with-v-for -->
<!-- view history page -->
<template>
    <main class="w-full">
        <!-- Top bar Title -->
        <TopBarTitle
            :page-title="`${articleStore.article.title} : Revision history`"
        />

        <!-- Top bar -->
        <TopBar
            :left-menu-items="[
                {
                    name: 'Article',
                    link: '/article?title=' + encodeURIComponent(title),
                },
                {
                    name: 'Talk',
                    link: '/talk?title=' + encodeURIComponent(title),
                },
            ]"
            :right-menu-items="[
                {
                    name: 'Edit Source',
                    link:
                        '/article/edit-source?title=' +
                        encodeURIComponent(title),
                },
                {
                    name: 'View History',
                    link:
                        '/article/view-history?title=' +
                        encodeURIComponent(title),
                },
            ]"
        />

        <!-- view history list -->
        <section class="bg-white p-2">
            <ul class="list-disc pl-5">
                <li v-for="history in revisionHistory" :key="history.uuid">
                    (<NuxtLink
                        :to="`/article/difference-between-revisions?title=${title}&uuid=${history.uuid}`"
                        class="underline"
                        >cur</NuxtLink
                    >
                    |
                    <NuxtLink
                        :to="`/article/difference-between-revisions?title=${title}&uuid=${history.uuid}`"
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
import { articleService } from '~/services/articleService'

useHead({
    title: 'Revision History',
})

const articleStore = useArticleStore()
const route = useRoute()
const title = decodeURIComponent(route.query.title)
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
