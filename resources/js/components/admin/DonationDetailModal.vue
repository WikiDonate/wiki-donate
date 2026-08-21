<template>
    <Modal
        :model-value="modelValue"
        title="Donation Details"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div v-if="donation" class="space-y-6">
            <!-- Amount Summary -->
            <div class="bg-linear-to-r from-indigo-600 to-purple-600 rounded-xl p-6 text-center">
                <p class="text-indigo-100 text-sm font-medium mb-1">Total Amount</p>
                <p class="text-white text-3xl font-bold">
                    {{ donation.currency || 'USD' }}
                    {{ Number(donation.amount).toFixed(2) }}
                </p>
                <div class="flex items-center justify-center gap-2 mt-3">
                    <AdminBadge
                        :variant="donation.paypal_order_id ? 'warning' : 'info'"
                        :text="donation.source"
                    />
                    <AdminBadge :variant="statusVariant(donation.status)" :text="donation.status" />
                </div>
            </div>

            <!-- Info Grid -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-gray-50 rounded-lg p-3">
                    <span class="text-xs text-gray-500 uppercase tracking-wide">Donor</span>
                    <p class="text-gray-800 font-medium text-sm mt-0.5">
                        {{ donation.user || '—' }}
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <span class="text-xs text-gray-500 uppercase tracking-wide">Email</span>
                    <p class="text-gray-800 text-sm mt-0.5 truncate">
                        {{ donation.email || '—' }}
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <span class="text-xs text-gray-500 uppercase tracking-wide">Date</span>
                    <p class="text-gray-800 text-sm mt-0.5">
                        {{ donation.date }}
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <span class="text-xs text-gray-500 uppercase tracking-wide">Payment ID</span>
                    <p class="text-gray-800 text-xs font-mono mt-0.5 truncate">
                        {{ donation.payment_id || donation.stripe_session_id || '—' }}
                    </p>
                </div>
            </div>

            <!-- Formula Breakdown -->
            <div
                v-if="donation.formula && donation.formula.length"
                class="border border-gray-100 rounded-xl overflow-hidden"
            >
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-100">
                    <h4 class="text-sm font-semibold text-gray-700">Distribution Formula</h4>
                </div>
                <div class="divide-y divide-gray-50">
                    <div
                        v-for="(item, i) in donation.formula"
                        :key="i"
                        class="px-4 py-3 flex items-center gap-4"
                    >
                        <div
                            class="w-10 h-10 rounded-full bg-indigo-50 flex items-center justify-center shrink-0"
                        >
                            <span class="text-xs font-bold text-indigo-600"
                                >{{ Number(item.percentage).toFixed(0) }}%</span
                            >
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 truncate">
                                {{ item.organization || item.name || 'Organization' }}
                            </p>
                            <div class="mt-1 w-full bg-gray-100 rounded-full h-1.5">
                                <div
                                    class="bg-indigo-500 h-1.5 rounded-full"
                                    :style="{ width: `${item.percentage}%` }"
                                />
                            </div>
                        </div>
                        <p class="text-sm font-semibold text-gray-700 shrink-0">
                            {{ donation.currency || 'USD' }}
                            {{
                                ((Number(donation.amount) * Number(item.percentage)) / 100).toFixed(
                                    2
                                )
                            }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Details -->
            <div v-if="donation.details" class="border border-gray-100 rounded-xl overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-100">
                    <h4 class="text-sm font-semibold text-gray-700">Additional Details</h4>
                </div>
                <div class="px-4 py-3">
                    <p class="text-gray-600 text-sm">{{ donation.details }}</p>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center justify-between gap-3">
                <NuxtLink
                    v-if="formulaUrl"
                    :to="formulaUrl"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition-colors"
                    @click="$emit('update:modelValue', false)"
                >
                    <font-awesome-icon :icon="['fas', 'link']" class="w-3.5 h-3.5" />
                    View Formula
                </NuxtLink>
                <button
                    class="ml-auto px-6 py-2 text-sm font-medium text-white bg-linear-to-r from-indigo-600 to-purple-600 rounded-lg hover:from-indigo-500 hover:to-purple-500 transition-colors"
                    @click="$emit('update:modelValue', false)"
                >
                    Close
                </button>
            </div>
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
    formulaUrl: {
        type: String,
        default: '',
    },
})

defineEmits(['update:modelValue'])

function statusVariant(status) {
    if (status === 'completed' || status === 'succeeded') return 'success'
    if (status === 'expired') return 'amber'
    return 'danger'
}
</script>
