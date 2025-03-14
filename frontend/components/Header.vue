<template>
    <header>
        <!-- google translate -->
        <div
            class="container mb-2 mx-auto mt-1 flex justify-center sm:justify-end items-baseline"
        >
            <GoogleTranslateSelect
                ref="translateSelect"
                default-language-code="en"
                default-page-language-code="en"
                :fetch-browser-language="false"
                trigger="click"
                display-mode="flag"
            />
        </div>

        <div class="container mx-auto flex items-center justify-between pl-4">
            <!-- Logo -->
            <div class="flex items-center mr-1">
                <span class="font-bold text-2xl lg:text-4xl lg:pl-4"
                    >WikiDonate</span
                >
            </div>

            <!-- Search box -->
            <div class="flex justify-center flex-grow">
                <div class="w-full lg:w-4/5">
                    <SearchBox />
                </div>
            </div>

            <!-- Desktop Menu -->
            <div class="hidden lg:flex items-center space-x-3 pr-4">
                <NuxtLink
                    v-for="(item, index) in topMenu"
                    :key="index"
                    :to="item.link"
                    exact
                    :class="
                        isActiveRoute(item.link, $route)
                            ? 'block underline text-blue-500'
                            : 'text-gray-800 hover:text-blue-500'
                    "
                >
                    {{ item.name }}
                </NuxtLink>

                <!-- Logout Button with Dropdown -->
                <div v-if="authStore.isAuthenticated" class="relative">
                    <button
                        id="logoutButton"
                        class="text-gray-800"
                        @click="toggleDropdownMenu"
                    >
                        <span class="mr-1">{{ authStore.user.username }}</span>
                        <font-awesome-icon :icon="['fas', 'user']" />
                    </button>

                    <!-- Logout Dropdown Menu -->
                    <transition name="fade">
                        <div
                            v-if="isDropdownMenuOpen"
                            class="absolute right-0 w-48 bg-white shadow-lg rounded-md mt-2 p-4 z-50"
                        >
                            <NuxtLink
                                v-for="(item, index) in logoutMenu"
                                :key="index"
                                :to="item.name === 'Logout' ? '#' : item.link"
                                exact
                                :class="
                                    isActiveRoute(item.link, $route)
                                        ? 'block underline text-blue-500'
                                        : 'block text-gray-800 hover:text-blue-500 py-2'
                                "
                                @click="
                                    item.name === 'Logout'
                                        ? handleLogout($event)
                                        : null
                                "
                            >
                                {{ item.name }}
                            </NuxtLink>
                        </div>
                    </transition>
                </div>
            </div>

            <!-- Mobile and Tablet Menu Icon -->
            <div class="ml-2 flex items-center lg:hidden pr-2">
                <button
                    id="menuButton"
                    class="text-gray-800"
                    @click="toggleMenu"
                >
                    <font-awesome-icon :icon="['fas', 'bars']" />
                </button>
            </div>
        </div>

        <!-- Mobile & Tablet Dropdown Menu -->
        <transition name="fade">
            <div
                v-if="isMobileMenuOpen"
                class="lg:hidden absolute right-0 w-48 bg-white shadow-lg rounded-md mt-2 p-4 z-50"
            >
                <NuxtLink
                    v-for="(item, index) in mobileMenu"
                    :key="index"
                    :to="item.link"
                    exact
                    :class="
                        isActiveRoute(item.link, $route)
                            ? 'block underline text-blue-500'
                            : 'block text-gray-800 hover:text-blue-500 py-2'
                    "
                >
                    {{ item.name }}
                </NuxtLink>
            </div>
        </transition>
    </header>
</template>

<script setup>
import GoogleTranslateSelect from '@google-translate-select/vue3'
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { authService } from '~/services/authService'
import menuData from '~/static/menu.json'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const isMobileMenuOpen = ref(false)
const isDropdownMenuOpen = ref(false) // For the logout dropdown
const topMenu = ref([]) // To store top menu items
const mobileMenu = ref([]) // To store mobile menu items
const logoutMenu = ref([]) // To store logout dropdown menu items

const toggleMenu = () => {
    isMobileMenuOpen.value = !isMobileMenuOpen.value
}

const toggleDropdownMenu = () => {
    isDropdownMenuOpen.value = !isDropdownMenuOpen.value
}

const isActiveRoute = (path, route) => {
    return route.path === path
}

const onClickOutside = (event) => {
    // logout menu focus out when click the outside
    const dropdown = document.getElementById('logoutButton')
    if (isDropdownMenuOpen.value && !dropdown.contains(event.target)) {
        isDropdownMenuOpen.value = false
    }
    // mobile menu focus out when click the outside
    const mobileDropdown = document.getElementById('menuButton')
    if (isMobileMenuOpen.value && !mobileDropdown.contains(event.target)) {
        isMobileMenuOpen.value = false
    }
}

const handleMenu = () => {
    topMenu.value = menuData.filter((item) => {
        if (authStore.isAuthenticated) {
            return (
                (item.type === 'TopMenu' || item.type === '') &&
                item.onLogin !== 'hide'
            )
        } else {
            return (
                item.type === 'TopMenu' ||
                (item.type === '' && item.onLogin !== 'show')
            )
        }
    })

    mobileMenu.value = menuData.filter((item) => {
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

    logoutMenu.value = menuData.filter((item) => {
        if (authStore.isAuthenticated) {
            return item.type === 'LogoutMenu' && item.onLogin !== 'hide'
        } else {
            return item.type === 'LogoutMenu' && item.onLogin !== 'show'
        }
    })
}

const handleLogout = async (event) => {
    event.preventDefault()
    try {
        const response = await authService.logout()
        if (response.success) {
            authStore.logout()
            localStorage.removeItem('token')
            handleMenu()
            router.push('/login')
        }
    } catch (error) {
        console.error(error.message)
        router.push('/login')
    }
}

onMounted(() => {
    handleMenu()
    document.addEventListener('click', onClickOutside)
})

onUnmounted(() => {
    document.removeEventListener('click', onClickOutside)
})

watch(route, () => {
    handleMenu()
    isMobileMenuOpen.value = false
    isDropdownMenuOpen.value = false
})
</script>
