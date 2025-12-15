<template>
    <footer class="border-t border-t-indigo-200 py-6">
        <div class="max-w-6xl mx-auto px-4 flex flex-col items-center">
            <!-- Logo -->
            <div class="flex items-center justify-center mb-3">
                <div
                    class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg mr-2"
                >
                    <font-awesome-icon
                        :icon="['fas', 'hands-praying']"
                        class="h-6 w-6 text-white"
                    />
                </div>
                <h2
                    class="font-bold text-xl bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-purple-600"
                >
                    WikiDonate
                </h2>
            </div>

            <!-- Copyright -->
            <p class="text-gray-500 text-sm">
                &copy; 2024 Wikidonate.org. All rights reserved.
            </p>
        </div>
    </footer>
</template>
