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
            <!-- Links -->
            <div
                class="flex flex-col sm:flex-row items-center justify-center gap-4"
            >
                <NuxtLink
                    to="/how-it-works"
                    class="flex items-center gap-2 px-4 sm:px-6 py-2 sm:py-3 rounded-full text-white font-semibold bg-indigo-600 hover:bg-indigo-700 transition"
                >
                    <font-awesome-icon
                        :icon="['fas', 'book-open']"
                        class="w-5 h-5"
                    />
                    How to Use WikiDonate
                </NuxtLink>
                <a
                    href="https://efy.koc.mybluehost.me/elementor-3694/"
                    target="_blank"
                    class="flex items-center gap-2 px-4 sm:px-6 py-2 sm:py-3 rounded-full text-white font-semibold bg-green-800 hover:bg-green-900 transition"
                >
                    <font-awesome-icon
                        :icon="['fas', 'file']"
                        class="w-5 h-5"
                    />
                    Founding Document
                </a>
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
        </div>
    </main>
</template>

<script setup>
useHead({
    title: 'Main Page',
})
</script>
