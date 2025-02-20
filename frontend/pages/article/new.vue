<!-- new article search page -->
<template>
    <main class="w-full">
        <!-- Top bar -->
        <TopBarTitle :page-title="'Search Results'" />

        <!-- new article search result option -->
        <section class="bg-white p-2">
            <p
                v-if="authStore.isAuthenticated"
                class="bg-white-100 mb-4 text-justify text-sm"
            >
                The page
                <NuxtLink
                    :to="`/article?title=${encodeURIComponent(title)}`"
                    exact
                    >" <span class="text-red-500 text-sm">{{ title }}</span
                    >"</NuxtLink
                >
                does not exist. You can create a draft and submit it for review
                or request that a redirect be created.
            </p>
            <p
                v-if="!authStore.isAuthenticated"
                class="bg-white-100 mb-4 text-justify text-sm"
            >
                The page
                <span class="text-red-500 text-sm">{{ title }}</span> does not
                exist. You can create a draft and submit it for review or
                request that a redirect be created.
                <span class="text-green-500 text-sm"
                    >Please
                    <NuxtLink to="/login" exact> login</NuxtLink>
                    to continue.</span
                >
            </p>
        </section>
    </main>
</template>

<script setup>
useHead({
    title: 'New Article',
})

const route = useRoute()
const authStore = useAuthStore()
const title = decodeURIComponent(route.query.title)
</script>
