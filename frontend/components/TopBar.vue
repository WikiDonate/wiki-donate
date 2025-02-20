<template>
    <section
        class="container mx-auto bg-white border-b border-b-gray-300 py-2 px-1 flex justify-between"
    >
        <!-- Left items -->
        <div class="space-x-4 text-sm flex basis-6/12 justify-start">
            <NuxtLink
                v-for="(item, index) in leftMenuItems"
                :key="index"
                :to="item.link"
                exact
                :class="
                    isActiveRoute(item.link, $route)
                        ? 'block underline text-blue-500'
                        : 'block text-gray-800 hover:text-blue-500'
                "
            >
                {{ item.name }}
            </NuxtLink>
        </div>

        <!-- Right items -->
        <div class="space-x-4 text-sm flex basis-6/12 justify-end pr-4">
            <NuxtLink
                v-for="(item, index) in rightMenu"
                :key="index"
                :to="item.link"
                exact
                :class="
                    isActiveRoute(item.link, $route)
                        ? 'block underline text-blue-500'
                        : 'block text-gray-800 hover:text-blue-500'
                "
            >
                {{ item.name }}
            </NuxtLink>
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
