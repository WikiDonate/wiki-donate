<template>
    <div class="min-h-screen flex flex-col bg-gray-50 text-gray-800">
        <div class="flex flex-1">
            <!-- Desktop Sidebar -->
            <div
                class="hidden lg:block lg:w-60 fixed left-0 top-0 bottom-0 z-40"
            >
                <AdminSidebar />
            </div>

            <!-- Mobile Sidebar Overlay -->
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="-translate-x-full"
                enter-to-class="translate-x-0"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="translate-x-0"
                leave-to-class="-translate-x-full"
            >
                <div
                    v-if="mobileSidebarOpen"
                    class="fixed inset-0 z-50 lg:hidden"
                >
                    <div
                        class="absolute inset-0 bg-black/40"
                        @click="mobileSidebarOpen = false"
                    ></div>
                    <div class="absolute left-0 top-0 bottom-0 w-60 bg-white">
                        <AdminSidebar />
                    </div>
                </div>
            </transition>

            <!-- Main Content -->
            <div class="flex-1 flex flex-col lg:ml-60">
                <!-- Mobile Top Bar -->
                <div
                    class="sticky top-0 z-30 lg:hidden bg-white border-b border-gray-200 px-4 py-3 flex items-center gap-3"
                >
                    <button
                        class="text-gray-600 p-1.5 rounded-md hover:bg-gray-100 transition-colors"
                        @click="mobileSidebarOpen = !mobileSidebarOpen"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'bars']"
                            class="w-5 h-5"
                        />
                    </button>
                    <span
                        class="font-bold text-lg bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-purple-600"
                    >
                        Admin Panel
                    </span>
                </div>

                <!-- Page Content -->
                <div class="flex-1 p-4 sm:p-6 lg:p-8">
                    <slot />
                </div>

                <Footer />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'

const mobileSidebarOpen = ref(false)
</script>
