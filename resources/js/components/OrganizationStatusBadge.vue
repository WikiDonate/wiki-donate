<template>
    <span
        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold"
        :class="isVerified ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
        :title="isVerified ? t('formula.verifiedHint') : t('formula.needsVerificationHint')"
    >
        <font-awesome-icon
            :icon="['fas', isVerified ? 'check-circle' : 'exclamation-circle']"
            class="w-3 h-3"
        />
        {{ isVerified ? t('formula.verified') : t('formula.needsVerification') }}
    </span>
</template>

<script setup>
    import { computed } from 'vue'
    import { useI18n } from 'vue-i18n'

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

    const isVerified = computed(() => (props.status ? props.status === 'verified' : props.verified))
</script>
