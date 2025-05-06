<!-- eslint-disable vue/html-self-closing -->
<template>
    <main class="w-full mx-auto shadow-lg rounded-lg overflow-hidden bg-white">
        <header class="bg-sky-100 py-4">
            <h2 class="text-center text-xl font-bold text-gray-900">
                Welcome to WikiDonate
            </h2>
        </header>

        <section class="p-8 text-center">
            <div class="space-y-4">
                <p class="text-lg font-semibold text-gray-800">
                    <NuxtLink
                        to="/article?title=wikidonatehow-to-use-wikidonate"
                        class="text-blue-500 hover:text-blue-700"
                    >
                        Read how to use WikiDonate
                    </NuxtLink>
                    and
                    <NuxtLink
                        to="/article?title=wikidonatehow-wikidonate-works "
                        class="text-blue-500 hover:text-blue-700"
                    >
                        how WikiDonate works </NuxtLink
                    >.
                </p>

                <p class="text-lg font-semibold text-gray-800">
                    WikiDonate is to be collectively run by the users and runs
                    according to this document
                    <NuxtLink
                        to="https://efy.koc.mybluehost.me/elementor-3694/"
                        class="text-blue-500 hover:text-blue-700"
                    >
                        Learn more </NuxtLink
                    >.
                </p>

                <p class="text-lg font-semibold text-gray-800">
                    This website is open-source on GitHub. Help improve the
                    code!
                    <NuxtLink
                        to="https://github.com/WikiDonate/wiki-donate"
                        class="text-blue-500 hover:text-blue-700"
                    >
                        Contribute here.
                    </NuxtLink>
                </p>
            </div>
        </section>
    </main>
</template>

<script setup>
useHead({
    title: 'Main Page',
})
</script>
