<template>
    <main class="w-full mx-auto max-w-2xl mt-6 bg-white rounded-2xl shadow-md overflow-hidden">
        <div
            class="py-8 px-6 text-center"
            :class="
                confirmed
                    ? 'bg-linear-to-r from-green-500 to-emerald-600'
                    : 'bg-linear-to-r from-indigo-600 to-purple-600'
            "
        >
            <div
                class="w-16 h-16 mx-auto mb-4 bg-white rounded-full flex items-center justify-center"
            >
                <font-awesome-icon
                    :icon="['fas', confirmed ? 'check-circle' : 'envelope']"
                    class="w-10 h-10"
                    :class="confirmed ? 'text-green-500' : 'text-indigo-500'"
                />
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-wide">
                {{ t('orgConfirm.title') }}
            </h1>
        </div>

        <div class="p-6 sm:p-8 text-center space-y-6">
            <div v-if="loading" class="flex justify-center py-4">
                <LoadingSpinner :text="t('orgConfirm.verifying')" />
            </div>

            <template v-else>
                <div
                    v-if="confirmed"
                    class="bg-green-50 border border-green-200 rounded-lg p-6"
                >
                    <p class="text-green-700 font-medium mb-1">
                        {{ t('orgConfirm.success', { org: orgName }) }}
                    </p>
                    <p class="text-gray-600 text-sm">{{ t('orgConfirm.successHint') }}</p>
                </div>

                <div v-else class="bg-red-50 border border-red-200 rounded-lg p-6">
                    <p class="text-red-700 font-medium mb-1">{{ t('orgConfirm.failed') }}</p>
                    <p class="text-gray-600 text-sm">{{ errorMessage }}</p>
                </div>

                <NuxtLink
                    to="/"
                    class="inline-flex items-center justify-center gap-2 px-8 py-3 rounded-lg bg-linear-to-r from-indigo-600 to-purple-600 text-white font-semibold hover:from-indigo-500 hover:to-purple-500 transition-colors shadow-md"
                >
                    <font-awesome-icon :icon="['fas', 'home']" class="w-4 h-4" />
                    {{ t('common.backToHome') }}
                </NuxtLink>
            </template>
        </div>
    </main>
</template>

<script setup>
    import { useI18n } from 'vue-i18n'
    import api from '~/config/apiConfig'

    const { t } = useI18n()
    const route = useRoute()

    const loading = ref(true)
    const confirmed = ref(false)
    const orgName = ref('')
    const errorMessage = ref('')

    useHead({ title: t('orgConfirm.title') })

    definePageMeta({
        // No auth — the recipient clicking the email link is not a platform user
    })

    onMounted(async () => {
        const token = route.query.token

        if (!token) {
            loading.value = false
            errorMessage.value = t('orgConfirm.missingToken')
            return
        }

        try {
            const response = await api.post('/organizations/confirm-email', { token })
            if (response.success) {
                confirmed.value = true
                orgName.value = response.data?.organization_name ?? ''
            } else {
                errorMessage.value = response.message || t('orgConfirm.failed')
            }
        } catch (error) {
            errorMessage.value = error.message || t('orgConfirm.failed')
        } finally {
            loading.value = false
        }
    })
</script>
