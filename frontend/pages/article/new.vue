<!-- new article search page -->
<template>
    <main class="w-full">
        <!-- Top bar -->
        <TopBarTitle :page-title="'Search Results'" />

        <!-- new article search result option -->
        <section class="bg-white rounded-2xl shadow-md mt-4 p-4 sm:p-6">
            <p
                v-if="authStore.isAuthenticated"
                class="text-gray-700 text-sm sm:text-base leading-relaxed"
            >
                The page
                <NuxtLink
                    :to="`/article?title=${encodeURIComponent(title)}`"
                    class="font-semibold text-red-500 hover:text-red-600"
                >
                    “{{ title }}”
                </NuxtLink>
                does not exist. You can create a draft and submit it for review
                or request that a redirect be created.
            </p>
            <p
                v-if="!authStore.isAuthenticated"
                class="text-gray-700 text-sm sm:text-base leading-relaxed"
            >
                The page
                <span class="text-red-500 text-sm">{{ title }}</span> does not
                exist. You can create a draft and submit it for review or
                request that a redirect be created.
                <span class="text-green-600 font-medium">
                    Please
                    <NuxtLink
                        to="/login"
                        class="underline hover:text-green-700"
                    >
                        login
                    </NuxtLink>
                    to continue.
                </span>
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
