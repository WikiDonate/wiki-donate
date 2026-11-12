<template>
    <span :title="title">
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
        /**
         * Free-text entry: the donor typed a name instead of picking a row
         * from the admin-curated registry. Custom entries are never
         * "verified" and get their own hint (they are emailed to the user
         * and admins for verification).
         */
        custom: {
            type: Boolean,
            default: false,
        },
    })

    // Registry is the source of truth: a row is verified only when the live
    // organizations registry says so (`status`), or when the caller already
    // resolved the live flag (`verified`). Nothing else grants green — an EIN
    // alone no longer implies verified, since custom entries may carry one.
    const isVerified = computed(() =>
        props.status ? props.status === 'verified' : props.verified === true,
    )

    // Three-state hint: verified / registry-but-pending / custom free-text.
    const title = computed(() => {
        if (isVerified.value) return t('formula.verifiedHint')
        if (props.custom) return t('formula.customOrgHint')

        return t('formula.needsVerificationHint')
    })
</script>
