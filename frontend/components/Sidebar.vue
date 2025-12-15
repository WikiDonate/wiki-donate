<template>
    <aside
        class="hidden lg:block lg:w-1/5 bg-white border-r border-indigo-200 shadow-sm p-4 sticky top-20 max-h-[calc(100vh-5rem)] overflow-y-auto"
    >
        <!-- Title -->
        <h2
            class="font-bold text-lg mb-4 bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-purple-600"
        >
            Main Menu
        </h2>
        <!-- Navigation -->
        <ul class="space-y-2">
            <li v-for="(item, index) in mainMenu" :key="index">
                <NuxtLink
                    :to="item.link"
                    exact
                    class="flex items-center px-3 py-2 text-sm font-medium transition-colors"
                    :class="
                        isActiveRoute(item.link, $route)
                            ? 'bg-indigo-50 text-indigo-700 border-l-4 border-indigo-600'
                            : 'text-gray-700 hover:bg-gray-50 hover:text-indigo-600'
                    "
                >
                    <font-awesome-icon
                        :icon="item.icon"
                        class="mr-2 w-4 h-4 text-gray-400"
                    />
                    {{ item.name }}
                </NuxtLink>
            </li>
        </ul>
    </aside>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import menuData from '~/static/menu.json'

const authStore = useAuthStore()
const route = useRoute()
const mainMenu = ref([])

onMounted(() => handleMenu())
watch(route, () => handleMenu())

const handleMenu = () => {
    mainMenu.value = menuData.filter((item) => {
        if (authStore.isAuthenticated) {
            return (
                (item.type === 'MainMenu' || item.type === '') &&
                item.onLogin !== 'hide'
            )
        } else {
            return (
                item.type === 'MainMenu' ||
                (item.type === '' && item.onLogin !== 'show')
            )
        }
    })
}

// Function to check if the current route is active
const isActiveRoute = (path, route) => route.path === path
</script>
