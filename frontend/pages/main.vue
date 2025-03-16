<!-- eslint-disable vue/html-self-closing -->
<template>
    <main class="w-full shadow rounded-lg lg:mr-48">
        <h2 class="font-bold text-center lg:text-xl mb-2 p-1 bg-sky-100">
            Welcome to Wikidonate
        </h2>
        <!-- Welcome Speech -->
        <div class="mx-auto bg-white p-6 text-justify">
            <h1 class="text-3xl font-bold text-gray-900 mb-6 text-center">
                Donate to Your Cause
            </h1>
            <p class="text-lg text-gray-700 mb-6">
                Donate to your cause. If you don’t find it, add it! If you have
                an idea to save the world, add that too!
            </p>
            <p class="text-gray-600 mb-6">
                You care about the cause, not yet another NGO. Donate to the
                cause and let the NGOs compete in a neutral area to prove that
                they are the best use of money.
            </p>
            <p class="text-gray-600 mb-6">
                The rich billionaire should not get to decide what the best use
                of charity is. Here, donors can decide the best expertise for
                each issue.
            </p>
            <p class="text-gray-600 mb-6">
                Imagine media that can be run independently of those they are
                supposed to hold to account. Imagine scientists not having to
                work for pharmaceutical companies and can discover drugs that
                may not be profitable and sell them for free. Imagine research
                done and shared without the constraints of journals’ financial
                interests.
            </p>
            <p class="text-gray-600 mb-6">
                You can now have your own foundation with a collection of causes
                you care about that others can contribute to in your name.
            </p>
            <div class="text-center">
                <NuxtLink
                    href="/article?title=wikidonatehow-to-use-wikidonate"
                    class="text-lg text-blue-500 font-semibold hover:text-blue-700"
                    >Check out how to use WikiDonate here.</NuxtLink
                >
            </div>
            <div class="text-center">
                <p class="text-lg text-gray-700 font-semibold">
                    This website is open-source on GitHub.
                </p>
                <a
                    href="https://github.com/WikiDonate/wiki-donate"
                    class="text-lg text-blue-500 font-semibold hover:text-blue-700"
                    >Repository</a
                >
            </div>
        </div>
    </main>
</template>

<script setup>
useHead({
    title: 'Main Page',
})
</script>
