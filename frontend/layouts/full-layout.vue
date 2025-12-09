<template>
    <div
        class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen flex flex-col"
    >
        <!-- Main content -->
        <div class="container mx-auto flex-1 text-gray-800">
            <div class="flex space-x-4">
                <slot />
            </div>
        </div>

        <!-- Footer stays at bottom -->
        <Footer />
    </div>
</template>
