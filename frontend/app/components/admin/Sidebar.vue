<template>
    <aside
        class="w-60 bg-white border-r border-indigo-200 shadow-sm flex flex-col h-full"
    >
        <!-- Admin Header -->
        <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-4 py-4">
            <NuxtLink to="/admin" class="flex items-center gap-2">
                <div
                    class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center"
                >
                    <font-awesome-icon
                        :icon="['fas', 'shield-alt']"
                        class="w-4 h-4 text-white"
                    />
                </div>
                <span class="font-bold text-lg text-white">Admin Panel</span>
            </NuxtLink>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
            <template v-for="(item, index) in navItems" :key="index">
                <!-- Link item -->
                <NuxtLink
                    v-if="item.type === 'link'"
                    :to="item.link"
                    class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"
                    :class="
                        isActive(item.link)
                            ? 'bg-indigo-50 text-indigo-700 border-l-4 border-indigo-600 rounded-l-none'
                            : 'text-gray-700 hover:bg-gray-50 hover:text-indigo-600'
                    "
                >
                    <font-awesome-icon
                        :icon="item.icon"
                        class="w-4 h-4"
                        :class="
                            isActive(item.link)
                                ? 'text-indigo-600'
                                : 'text-gray-400'
                        "
                    />
                    {{ item.name }}
                </NuxtLink>

                <!-- Group item -->
                <div v-if="item.type === 'group'">
                    <button
                        class="flex items-center justify-between w-full px-3 py-2.5 text-sm font-medium rounded-lg transition-colors text-gray-700 hover:bg-gray-50 hover:text-indigo-600"
                        :class="{
                            'bg-indigo-50 text-indigo-700': isGroupActive(
                                item.children
                            ),
                        }"
                        @click="toggleGroup(index)"
                    >
                        <span class="flex items-center gap-3">
                            <font-awesome-icon
                                :icon="item.icon"
                                class="w-4 h-4"
                                :class="
                                    isGroupActive(item.children)
                                        ? 'text-indigo-600'
                                        : 'text-gray-400'
                                "
                            />
                            {{ item.name }}
                        </span>
                        <font-awesome-icon
                            :icon="[
                                'fas',
                                openGroups.includes(index)
                                    ? 'chevron-down'
                                    : 'chevron-right',
                            ]"
                            class="w-3 h-3 text-gray-400"
                        />
                    </button>

                    <div
                        v-show="openGroups.includes(index)"
                        class="ml-4 mt-0.5 space-y-0.5 border-l-2 border-indigo-100 pl-2"
                    >
                        <NuxtLink
                            v-for="(child, ci) in item.children"
                            :key="ci"
                            :to="child.link"
                            class="flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg transition-colors"
                            :class="
                                isActive(child.link)
                                    ? 'bg-indigo-50 text-indigo-700 border-l-4 border-indigo-600 rounded-l-none'
                                    : 'text-gray-600 hover:bg-gray-50 hover:text-indigo-600'
                            "
                        >
                            <font-awesome-icon
                                :icon="child.icon"
                                class="w-3.5 h-3.5"
                                :class="
                                    isActive(child.link)
                                        ? 'text-indigo-600'
                                        : 'text-gray-400'
                                "
                            />
                            {{ child.name }}
                        </NuxtLink>
                    </div>
                </div>
            </template>
        </nav>

        <!-- Back to Site -->
        <div class="border-t border-gray-200 p-3">
            <NuxtLink
                to="/main"
                class="flex items-center gap-2 px-3 py-2 text-sm text-gray-500 hover:text-indigo-600 rounded-lg hover:bg-gray-50 transition-colors"
            >
                <font-awesome-icon
                    :icon="['fas', 'arrow-left']"
                    class="w-4 h-4"
                />
                Back to Site
            </NuxtLink>
        </div>
    </aside>
</template>

<script setup>
import { ref } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

const navItems = [
    {
        type: 'link',
        name: 'Dashboard',
        link: '/admin',
        icon: ['fas', 'chart-pie'],
    },
    {
        type: 'link',
        name: 'Articles',
        link: '/admin/articles',
        icon: ['fas', 'newspaper'],
    },
    {
        type: 'group',
        name: 'Pages',
        icon: ['fas', 'file-alt'],
        children: [
            {
                name: 'How It Works',
                link: '/admin/how-it-works',
                icon: ['fas', 'book-open'],
            },
        ],
    },
]

const openGroups = ref([])

const isActive = (link) => route.path === link

const isGroupActive = (children) => children.some((c) => route.path === c.link)

const toggleGroup = (index) => {
    const i = openGroups.value.indexOf(index)
    if (i === -1) {
        openGroups.value.push(index)
    } else {
        openGroups.value.splice(i, 1)
    }
}
</script>
