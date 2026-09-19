<template>
    <footer class="py-6">
        <div class="max-w-6xl mx-auto px-4 flex flex-col items-center">
            <!-- Logo -->
            <div class="mb-3">
                <Logo size="md" />
            </div>

            <!-- Copyright -->
            <p class="text-gray-500 text-sm">
                {{ t('footer.copyright', { year: new Date().getFullYear() }) }}
            </p>
        </div>
    </footer>
</template>

<script setup>
    import { useI18n } from 'vue-i18n'

    const { t } = useI18n()
</script>
