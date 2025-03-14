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
                    isAuthenticated: authStore.isAuthenticated,
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
        <section class="bg-white p-2">
            <div>User Page</div>
            <div>
                <QuillEditor v-model:content="editorContent" />
            </div>
            <div class="w-40 mt-4">
                <FormSubmitButton @click="handleSubmit" />
            </div>
        </section>
    </main>
</template>

<script setup>
const articleStore = useArticleStore()
const authStore = useAuthStore()
</script>
