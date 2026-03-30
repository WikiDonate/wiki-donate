<template>
    <header
        class="sticky bg-white top-0 z-50 border-b border-indigo-200 py-3 shadow-sm"
    >
        <!-- Main Header -->
        <div
            class="container mx-auto flex items-center justify-between px-2 sm:px-4"
        >
            <!-- Left: Logo -->
            <NuxtLink to="/" class="flex items-center">
                <div
                    class="w-8 h-8 sm:w-10 sm:h-10 bg-indigo-600 rounded-xl flex items-center justify-center shadow-md mr-2"
                >
                    <font-awesome-icon
                        :icon="['fas', 'hands-praying']"
                        class="h-5 w-5 sm:h-6 sm:w-6 text-white"
                    />
                </div>
                <h2
                    class="font-bold text-lg sm:text-2xl bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-purple-600"
                >
                    WikiDonate
                </h2>
            </NuxtLink>

            <!-- Center: Desktop Search -->
            <div class="hidden md:flex flex-1 justify-center px-4">
                <div class="w-full max-w-2xl">
                    <SearchBox />
                </div>
            </div>

            <!-- Right: Language, Auth, Menus -->
            <div class="flex items-center space-x-1 sm:space-x-2">
                <!-- Language -->
                <div
                    class="block border border-gray-200 rounded-md ml-1 sm:ml-2"
                >
                    <GoogleTranslateSelect
                        ref="translateSelect"
                        default-language-code="en"
                        default-page-language-code="en"
                        :fetch-browser-language="false"
                        trigger="click"
                        display-mode="flag"
                        :languages="languagesList"
                        class="px-0 sm:px-1 uppercase text-sm font-semibold text-gray-700"
                    />
                </div>

                <!-- Logged-in User -->
                <div v-if="authStore.isAuthenticated" class="block">
                    <div class="relative">
                        <button
                            id="logoutButton"
                            class="flex items-center space-x-1 sm:space-x-2 px-1 sm:px-3 py-2 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors"
                            @click="toggleDropdownMenu('avatar')"
                        >
                            <div
                                class="w-6 h-6 sm:w-8 sm:h-8 bg-indigo-600 rounded-full flex items-center justify-center shadow-sm"
                            >
                                <font-awesome-icon
                                    class="w-3 h-3 sm:w-4 sm:h-4 text-white"
                                    :icon="['fas', 'user']"
                                />
                            </div>
                            <span class="hidden lg:inline">{{
                                authStore.user.username
                            }}</span>
                            <font-awesome-icon
                                :icon="[
                                    'fas',
                                    activeDropdown === 'avatar'
                                        ? 'chevron-up'
                                        : 'chevron-down',
                                ]"
                                class="w-2 h-2 sm:w-4 sm:h-4 transition-transform"
                            />
                        </button>

                        <!-- Avatar Dropdown -->
                        <transition
                            enter-active-class="transition duration-100 ease-out"
                            enter-from-class="transform scale-95 opacity-0"
                            enter-to-class="transform scale-100 opacity-100"
                            leave-active-class="transition duration-75 ease-in"
                            leave-from-class="transform scale-100 opacity-100"
                            leave-to-class="transform scale-95 opacity-0"
                        >
                            <div
                                v-if="activeDropdown === 'avatar'"
                                class="absolute right-0 w-48 mt-2 bg-white rounded-md shadow-lg py-1 z-50 border border-gray-200"
                            >
                                <NuxtLink
                                    v-for="(item, index) in logoutMenu"
                                    :key="index"
                                    :to="resolveLink(item)"
                                    exact
                                    class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 transition-colors"
                                    :class="
                                        isActiveRoute(item.link, $route)
                                            ? 'bg-indigo-50 text-indigo-700'
                                            : ''
                                    "
                                    @click="
                                        item.name === 'Logout'
                                            ? handleLogout($event)
                                            : (activeDropdown = null)
                                    "
                                >
                                    <font-awesome-icon
                                        :icon="item.icon"
                                        class="mr-3 w-4 h-4 text-gray-400"
                                    />
                                    {{ item.name }}
                                </NuxtLink>
                            </div>
                        </transition>
                    </div>
                </div>

                <!-- Guest User -->
                <div
                    v-if="!authStore.isAuthenticated"
                    class="flex items-center lg:space-x-2"
                >
                    <!-- Desktop menu -->
                    <div class="hidden lg:flex items-center space-x-2">
                        <NuxtLink
                            v-for="(item, index) in topMenu"
                            :key="index"
                            :to="item.link"
                            exact
                            class="px-1 sm:px-3 py-2 rounded-md text-sm font-medium transition-colors"
                            :class="
                                isActiveRoute(item.link, $route)
                                    ? 'bg-indigo-100 text-indigo-700'
                                    : 'text-gray-700 hover:bg-gray-100'
                            "
                        >
                            {{ item.name }}
                        </NuxtLink>
                    </div>

                    <!-- Mobile three-dot -->
                    <div class="relative lg:hidden">
                        <button
                            id="moreButton"
                            class="text-gray-700 p-1.5 sm:p-2 rounded-md hover:bg-gray-100 transition-colors flex items-center"
                            @click="toggleDropdownMenu('more')"
                        >
                            <font-awesome-icon
                                :icon="['fas', 'ellipsis-h']"
                                class="h-4 w-4 sm:h-5 sm:w-5"
                            />
                        </button>
                        <transition
                            enter-active-class="transition duration-100 ease-out"
                            enter-from-class="transform scale-95 opacity-0"
                            enter-to-class="transform scale-100 opacity-100"
                            leave-active-class="transition duration-75 ease-in"
                            leave-from-class="transform scale-100 opacity-100"
                            leave-to-class="transform scale-95 opacity-0"
                        >
                            <div
                                v-if="activeDropdown === 'more'"
                                class="absolute right-0 w-40 mt-2 bg-white rounded-md shadow-lg py-1 z-50 border border-gray-200"
                            >
                                <NuxtLink
                                    v-for="(item, index) in topMenu"
                                    :key="index"
                                    :to="item.link"
                                    exact
                                    class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 transition-colors"
                                    @click="activeDropdown = null"
                                >
                                    <font-awesome-icon
                                        :icon="item.icon"
                                        class="mr-3 w-4 h-4 text-gray-400"
                                    />
                                    {{ item.name }}
                                </NuxtLink>
                            </div>
                        </transition>
                    </div>
                </div>

                <!-- Mobile Hamburger -->
                <div class="relative lg:hidden">
                    <button
                        id="menuButton"
                        class="text-gray-700 p-1.5 pr-0.5 sm:p-2 rounded-md hover:bg-gray-100 transition-colors flex items-center"
                        @click="toggleDropdownMenu('hamburger')"
                    >
                        <font-awesome-icon
                            :icon="[
                                'fas',
                                activeDropdown === 'hamburger'
                                    ? 'times'
                                    : 'bars',
                            ]"
                            class="h-4 w-4 sm:h-5 sm:w-5"
                        />
                    </button>
                    <!-- Hamburger Dropdown Menu (Main menu) -->
                    <transition
                        enter-active-class="transition duration-100 ease-out"
                        enter-from-class="transform scale-95 opacity-0"
                        enter-to-class="transform scale-100 opacity-100"
                        leave-active-class="transition duration-75 ease-in"
                        leave-from-class="transform scale-100 opacity-100"
                        leave-to-class="transform scale-95 opacity-0"
                    >
                        <div
                            v-if="activeDropdown === 'hamburger'"
                            class="absolute right-0 w-48 mt-2 bg-white rounded-md shadow-lg py-1 z-50 border border-gray-200"
                        >
                            <NuxtLink
                                v-for="(item, index) in mainMenu"
                                :key="index"
                                :to="item.link"
                                exact
                                class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 transition-colors"
                                :class="
                                    isActiveRoute(item.link, $route)
                                        ? 'bg-indigo-50 text-indigo-700'
                                        : ''
                                "
                                @click="activeDropdown = null"
                            >
                                <font-awesome-icon
                                    :icon="item.icon"
                                    class="mr-3 w-4 h-4 text-gray-400"
                                />
                                {{ item.name }}
                            </NuxtLink>
                        </div>
                    </transition>
                </div>
            </div>
        </div>
        <!-- Mobile Search -->
        <div class="container mx-auto mt-3 px-2 sm:px-4 md:hidden">
            <SearchBox />
        </div>
    </header>
</template>

<script setup>
import GoogleTranslateSelect from '@google-translate-select/vue3'
import { onMounted, onUnmounted, ref, watch, computed } from 'vue'
import { useRoute } from 'vue-router'
import { authService } from '~/services/authService'
import menuData from '~/static/menu.json'
import languagesList from '~/static/languages.json'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const activeDropdown = ref(null)

// Menu Computed
const topMenu = computed(() =>
    menuData.filter((item) =>
        authStore.isAuthenticated
            ? item.type === 'TopMenu' && item.onLogin !== 'hide'
            : item.type === 'TopMenu' && item.onLogin !== 'show'
    )
)

const mainMenu = computed(() =>
    menuData.filter(
        (item) => item.type === 'MainMenu' && item.onLogin !== 'hide'
    )
)

const logoutMenu = computed(() =>
    menuData.filter((item) =>
        authStore.isAuthenticated
            ? item.type === 'LogoutMenu' && item.onLogin !== 'hide'
            : item.type === 'LogoutMenu' && item.onLogin !== 'show'
    )
)

const resolveLink = (item) => {
    if (item.name === 'Logout') return '#'
    if (item.name === 'User Page')
        return `/user/page?username=${authStore.user.username}`
    return item.link
}

const toggleDropdownMenu = (menuType) => {
    activeDropdown.value = activeDropdown.value === menuType ? null : menuType
}

const isActiveRoute = (path, route) => {
    return route.path === path
}

const onClickOutside = (event) => {
    const buttons = ['logoutButton', 'menuButton', 'moreButton']
    if (
        !buttons.some((id) =>
            document.getElementById(id)?.contains(event.target)
        )
    ) {
        activeDropdown.value = null
    }
}

const handleLogout = async (event) => {
    event.preventDefault()
    try {
        const response = await authService.logout()
        if (response.success) {
            authStore.logout()
            localStorage.removeItem('token')
            activeDropdown.value = null
            router.push('/login')
        }
    } catch (error) {
        console.error(error.message)
        router.push('/login')
    }
}

onMounted(() => {
    document.addEventListener('click', onClickOutside)
})

onUnmounted(() => {
    document.removeEventListener('click', onClickOutside)
})

watch(route, () => {
    activeDropdown.value = null
})
</script>

<style>
.google-translate-select-dropdown__activator {
    padding: 1px 5px !important;
}
</style>
