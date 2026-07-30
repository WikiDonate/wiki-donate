<template>
    <Modal
        :model-value="modelValue"
        title="Donation Details"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div v-if="donation" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-semibold text-gray-500 uppercase">Donor</label>
                    <p class="text-gray-800 font-medium">{{ donation.user || '—' }}</p>
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500 uppercase">Email</label>
                    <p class="text-gray-800">{{ donation.email || '—' }}</p>
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500 uppercase">Source</label>
                    <AdminBadge
                        :variant="donation.source === 'stripe_checkout' ? 'info' : 'purple'"
                        :text="donation.source === 'stripe_checkout' ? 'Stripe Checkout' : 'Stripe Card'"
                    />
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500 uppercase">Session ID</label>
                    <p class="text-gray-800 text-xs font-mono break-all">{{ donation.stripe_session_id || '—' }}</p>
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500 uppercase">Amount</label>
                    <p class="text-gray-800 font-semibold text-lg">{{ donation.currency }} {{ Number(donation.amount).toFixed(2) }}</p>
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500 uppercase">Status</label>
                    <AdminBadge
                        :variant="statusVariant(donation.status)"
                        :text="donation.status"
                    />
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-500 uppercase">Date</label>
                    <p class="text-gray-800">{{ donation.date }}</p>
                </div>
            </div>

            <!-- Formula Breakdown -->
            <div v-if="donation.formula && donation.formula.length" class="border-t pt-4">
                <h4 class="font-semibold text-gray-800 mb-3">Distribution Formula</h4>
                <div class="space-y-2">
                    <div
                        v-for="(item, i) in donation.formula"
                        :key="i"
                        class="flex items-center justify-between bg-gray-50 rounded-lg px-4 py-3"
                    >
                        <div>
                            <p class="text-sm font-medium text-gray-800">{{ item.organization || item.name || 'Organization' }}</p>
                            <p class="text-xs text-gray-500">{{ Number(item.percentage).toFixed(1) }}%</p>
                        </div>
                        <p class="text-sm font-semibold text-indigo-600">
                            {{ donation.currency }} {{ (Number(donation.amount) * Number(item.percentage) / 100).toFixed(2) }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Details -->
            <div v-if="donation.details" class="border-t pt-4">
                <h4 class="font-semibold text-gray-800 mb-2">Details</h4>
                <p class="text-gray-600 text-sm">{{ donation.details }}</p>
            </div>
        </div>

        <template #footer>
            <button
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                @click="$emit('update:modelValue', false)"
            >
                Close
            </button>
        </template>
    </Modal>
</template>

<script setup>
defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    donation: {
        type: Object,
        default: null,
    },
})

defineEmits(['update:modelValue'])

function statusVariant(status) {
    if (status === 'completed' || status === 'succeeded') return 'success'
    if (status === 'pending') return 'warning'
    return 'danger'
}
</script>
