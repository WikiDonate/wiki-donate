<template>
    <span :title="isVerified ? t('formula.verifiedHint') : t('formula.needsVerificationHint')">
        <AdminBadge
            :variant="isVerified ? 'success' : 'danger'"
            :text="isVerified ? t('formula.verified') : t('formula.needsVerification')"
        />
    </span>
</template>

<script setup>
    import { computed } from 'vue'
    import { useI18n } from 'vue-i18n'
    import AdminBadge from '~/components/admin/AdminBadge.vue'

    const { t } = useI18n()

    const props = defineProps({
        verified: {
            type: Boolean,
            default: false,
        },
        status: {
            type: String,
            default: '',
        },
    })

    // Same green/red as the admin panel (AdminBadge success/danger).
    const isVerified = computed(() => (props.status ? props.status === 'verified' : props.verified))
</script>
