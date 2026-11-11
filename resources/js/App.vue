<template>
    <RouterView />
</template>

<script setup>
    import { useI18n } from 'vue-i18n'
    import { useHead } from '@vueuse/head'
    import { useRoute } from 'vue-router'

    const route = useRoute()
    const { t, te } = useI18n()

    const appName = import.meta.env.VITE_APP_NAME || 'Wiki Donate'

    const resolvePageTitle = () => {
        const key = route.meta?.titleKey
        if (!key) return ''

        if (typeof te === 'function' && te(key)) {
            return t(key)
        }

        return t(key)
    }

    useHead({
        title: () => {
            if (route.meta?.dynamicTitle) {
                return undefined
            }

            const pageTitle = resolvePageTitle()
            return pageTitle ? `${pageTitle} | ${appName}` : appName
        },
    })
</script>
