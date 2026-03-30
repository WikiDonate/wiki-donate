<!-- eslint-disable vue/html-self-closing -->
<template>
    <main
        class="w-full lg:mr-48 mt-6 bg-white rounded-2xl shadow-md overflow-hidden"
    >
        <!-- Gradient Header1 -->
        <div
            class="bg-gradient-to-r from-indigo-600 to-purple-600 py-4 px-6 text-center"
        >
            <h2
                class="font-bold text-lg sm:text-xl md:text-2xl text-white tracking-wide"
            >
                Welcome to WikiDonate
            </h2>
        </div>

        <!-- Welcome Speech -->
        <div class="p-6 sm:p-10 text-gray-800 leading-relaxed">
            <h1
                class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-center mb-6 text-gray-900"
            >
                <NuxtLink
                    to="/article?title=wikidonatehow-to-use-wikidonate"
                    class="text-blue-500 hover:text-blue-700"
                >
                    Read how to use WikiDonate
                </NuxtLink>
            </h1>
            <p class="text-base sm:text-lg text-gray-700 mb-6 text-center">
                WikiDonate is to be collectively run by the users and runs
                according to this document
                <NuxtLink
                    to="https://efy.koc.mybluehost.me/elementor-3694/"
                    class="text-blue-500 hover:text-blue-700"
                >
                    Learn more </NuxtLink
                >.
            </p>

            <!-- Links -->
            <div
                class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4"
            >
                <NuxtLink
                    href="/article?title=wikidonatehow-to-use-wikidonate"
                    class="flex items-center gap-2 px-4 sm:px-6 py-2 sm:py-3 rounded-full text-white font-semibold bg-indigo-600 hover:bg-indigo-700 transition"
                >
                    <font-awesome-icon
                        :icon="['fas', 'book-open']"
                        class="w-5 h-5"
                    />
                    How to Use WikiDonate
                </NuxtLink>
                <a
                    href="https://github.com/WikiDonate/wiki-donate"
                    target="_blank"
                    class="flex items-center gap-2 px-4 sm:px-6 py-2 sm:py-3 rounded-full text-white font-semibold bg-gray-800 hover:bg-gray-900 transition"
                >
                    <font-awesome-icon
                        :icon="['fab', 'github']"
                        class="w-5 h-5"
                    />
                    GitHub Repository
                </a>
            </div>

            <!-- Footer note -->
            <div class="mt-8 text-center text-gray-500 text-sm">
                <p>
                    This website is open-source on GitHub. Join us & contribute!
                </p>
            </div>
        </div>
    </main>
</template>

<script setup>
useHead({
    title: 'Main Page',
})
</script>
