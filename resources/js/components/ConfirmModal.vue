<template>
    <Modal
        :model-value="modelValue"
        :title="tx(title)"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div class="flex flex-col items-center text-center p-4">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mb-4">
                <font-awesome-icon
                    :icon="['fas', 'exclamation-triangle']"
                    class="text-red-500 text-3xl"
                />
            </div>
            <h4 class="text-xl font-bold text-gray-900 mb-2">
                {{ tx(messageTitle) }}
            </h4>
            <p class="text-gray-600">{{ tx(message) }}</p>
        </div>

        <template #footer>
            <Button
                variant="secondary"
                :text="tx(cancelText)"
                width="auto"
                class="px-6"
                @click="$emit('update:modelValue', false)"
            />
            <Button
                variant="primary"
                :text="tx(confirmText)"
                width="auto"
                class="px-6 !bg-red-600 hover:!bg-red-700 border-none"
                :disabled="isLoading"
                @click="$emit('confirm')"
            />
        </template>
    </Modal>
</template>

<script setup>
    import { useI18n } from 'vue-i18n'

    const { t } = useI18n()

    // Callers pass either i18n keys ('confirmModal.title') or pre-resolved
    // / interpolated strings (`Title: ${...}`). Only translate key-shaped
    // values — re-translating a resolved string warns ("Not found ... key")
    // and freezes the modal in the wrong language.
    const isKey = (v) => typeof v === 'string' && /^[a-z]+\.[a-zA-Z.]+$/.test(v)
    const tx = (v) => (isKey(v) ? t(v) : (v ?? ''))

    defineProps({
        modelValue: {
            type: Boolean,
            default: false,
        },
        title: {
            type: String,
            default: 'confirmModal.title',
        },
        messageTitle: {
            type: String,
            default: 'confirmModal.messageTitle',
        },
        message: {
            type: String,
            default: 'confirmModal.message',
        },
        confirmText: {
            type: String,
            default: 'confirmModal.confirm',
        },
        cancelText: {
            type: String,
            default: 'confirmModal.cancel',
        },
        isLoading: {
            type: Boolean,
            default: false,
        },
    })

    defineEmits(['update:modelValue', 'confirm'])
</script>
