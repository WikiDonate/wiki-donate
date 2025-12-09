<template>
    <section
        class="container mx-auto bg-white border-b border-b-indigo-300 px-2 sm:px-4"
    >
        <div
            class="flex justify-between items-center overflow-x-auto hide-scrollbar py-2"
        >
            <!-- Left items -->
            <div class="flex space-x-2 sm:space-x-4 text-sm flex-shrink-0">
                <NuxtLink
                    v-for="(item, index) in leftMenuItems"
                    :key="index"
                    :to="item.link"
                    exact
                    class="px-3 py-1.5 rounded-full font-medium transition-all duration-200"
                    :class="
                        isActiveRoute(item.link, $route)
                            ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-sm'
                            : 'text-gray-700 hover:text-indigo-600 hover:bg-indigo-50'
                    "
                >
                    {{ item.name }}
                </NuxtLink>
            </div>

            <!-- Right items -->
            <div
                class="flex space-x-2 sm:space-x-4 text-sm flex-shrink-0 pr-2 sm:pr-4"
            >
                <NuxtLink
                    v-for="(item, index) in rightMenu"
                    :key="index"
                    :to="item.link"
                    exact
                    class="px-3 py-1.5 rounded-full font-medium transition-all duration-200"
                    :class="
                        isActiveRoute(item.link, $route)
                            ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-sm'
                            : 'text-gray-700 hover:text-indigo-600 hover:bg-indigo-50'
                    "
                >
                    {{ item.name }}
                </NuxtLink>
            </div>
        </div>
    </section>
</template>

<script setup>
const authStore = useAuthStore()
const route = useRoute()
const { rightMenuItems, leftMenuItems } = defineProps({
    leftMenuItems: {
        type: Array,
        default: () => [],
    },
    rightMenuItems: {
        type: Array,
        default: () => [],
    },
})
const rightMenu = ref([])

onMounted(() => handleRightMenuItems())
watch(route, () => handleRightMenuItems())

const handleRightMenuItems = () => {
    rightMenu.value = rightMenuItems.filter((item) => {
        if (authStore.isAuthenticated) {
            return item.isAuthenticated || !item.isAuthenticated
        } else {
            return !item.isAuthenticated
        }
    })
}

const isActiveRoute = (path, route) => {
    return route.href === decodeURIComponent(path) || route.path === path
}
</script>

<style scoped>
/* Hide scrollbar for horizontal scrolling */
.hide-scrollbar::-webkit-scrollbar {
    display: none;
}
.hide-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
