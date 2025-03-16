<!-- eslint-disable vue/no-v-html -->
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

        <!-- view history list -->
        <section class="bg-white p-2">
            <div
                class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 lg:gap-8"
            >
                <div
                    class="bg-gray-50 p-4 md:p-6 border border-gray-300 rounded-lg shadow overflow-x-auto"
                >
                    <h3 class="font-bold mb-2 text-center">Current version</h3>
                    <pre v-html="curHtml" />
                </div>
                <div
                    class="bg-gray-50 p-4 md:p-6 border border-gray-300 rounded-lg shadow overflow-x-auto"
                >
                    <h3 class="font-bold mb-2 text-center">
                        Difference version
                    </h3>
                    <pre v-html="diffHtml" />
                </div>
            </div>
        </section>
    </main>
</template>

<script setup>
import DiffMatchPatch from 'diff-match-patch'
import { onMounted, ref } from 'vue'

const route = useRoute()
const title = decodeURIComponent(route.query.username)
const uuid = route.query.uuid || ''
const talkStore = useTalkStore()
const curHtml = ref('')
const diffHtml = ref('')

const generateDiffHtml = (diffs) => {
    let html = ''
    diffs.forEach(([op, data]) => {
        if (op === DiffMatchPatch.DIFF_INSERT) {
            html += `<span class="bg-green-100 text-green-700 px-1 rounded">${data}</span>`
        } else if (op === DiffMatchPatch.DIFF_DELETE) {
            html += `<span class="bg-red-100 text-red-700 px-1 rounded">${data}</span>`
        } else {
            html += `<span>${data}</span>`
        }
    })
    return html
}

const fetchAndCompare = async (uuid) => {
    const version = talkStore.history.find((item) => item.uuid == uuid)
    // Old content prepare
    let oldContent = ''
    JSON.parse(version.oldContent).forEach((item) => {
        if (typeof item === 'string') {
            oldContent += item
        } else {
            if (item.title) oldContent += item.title
            if (item.content) oldContent += item.content
        }
    })

    // New content prepare
    let newContent = ''
    JSON.parse(version.newContent).forEach((item) => {
        if (typeof item === 'string') {
            newContent += item
        } else {
            if (item.title) newContent += item.title
            if (item.content) newContent += item.content
        }
    })

    const dmp = new DiffMatchPatch()
    const diffs = dmp.diff_main(oldContent, newContent)
    dmp.diff_cleanupSemantic(diffs)
    curHtml.value = oldContent
    diffHtml.value = generateDiffHtml(diffs)
}

onMounted(() => {
    fetchAndCompare(uuid)
})
</script>
