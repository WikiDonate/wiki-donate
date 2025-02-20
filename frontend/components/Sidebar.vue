<template>
    <aside class="hidden md:hidden lg:w-1/5 bg-white p-4 ml-6 lg:block">
        <h2 class="font-bold mb-4">Main Menu</h2>
        <ul class="space-y-2">
            <li v-for="(item, index) in mainMenu" :key="index">
                <NuxtLink
                    :to="item.link"
                    exact
                    :class="
                        isActiveRoute(item.link, $route)
                            ? 'block underline text-blue-500'
                            : 'block text-blue-500 hover:text-blue-500'
                    "
                >
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
