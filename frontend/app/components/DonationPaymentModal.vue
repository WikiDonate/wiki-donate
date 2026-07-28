<template>
    <Modal
        :model-value="modelValue"
        title="Donate via Formula"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div class="space-y-5">
            <div class="bg-indigo-50 rounded-lg p-4 border border-indigo-100">
                <h4 class="font-semibold text-indigo-700 text-sm mb-3">
                    Formula Breakdown
                </h4>
                <div class="space-y-2">
                    <div
                        v-for="(item, i) in formula"
                        :key="i"
                        class="flex justify-between items-center text-sm"
                    >
                        <span class="text-gray-700">{{
                            item.organization
                        }}</span>
                        <span class="font-semibold text-indigo-600"
                            >{{ item.percentage }}%</span
                        >
                    </div>
                    <div
                        class="flex justify-between items-center pt-2 border-t border-indigo-200 font-bold text-gray-800"
                    >
                        <span>Total</span>
                        <span class="text-indigo-700">100%</span>
                    </div>
                </div>
                <p
                    v-if="details"
                    class="mt-2 pt-2 border-t border-indigo-200 text-xs text-gray-500 italic"
                >
                    {{ details }}
                </p>
            </div>

            <!-- Login prompt for non-authenticated users -->
            <div
                v-if="!authStore.isAuthenticated"
                class="bg-amber-50 rounded-lg p-5 border border-amber-200 text-center"
            >
                <font-awesome-icon
                    :icon="['fas', 'lock']"
                    class="w-8 h-8 text-amber-500 mb-3"
                />
                <p class="text-gray-700 text-sm font-medium mb-3">
                    Please log in to make a donation.
                </p>
                <NuxtLink
                    to="/login"
                    class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors"
                >
                    <font-awesome-icon
                        :icon="['fas', 'sign-in-alt']"
                        class="w-4 h-4"
                    />
                    Log In
                </NuxtLink>
            </div>

            <!-- Donation form for authenticated users -->
            <template v-else>
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Donation Amount ($)
                        <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="amount"
                        type="number"
                        min="1"
                        step="0.01"
                        placeholder="Enter amount"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                        :disabled="isDonating"
                    />
                    <div class="flex flex-wrap gap-2 mt-3">
                        <button
                            v-for="quickAmount in quickAmounts"
                            :key="quickAmount"
                            type="button"
                            class="px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors"
                            :class="{
                                'bg-indigo-100 text-indigo-700 font-medium':
                                    Number(amount) === quickAmount,
                            }"
                            @click="amount = quickAmount"
                        >
                            ${{ quickAmount }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="alertMessage"
                    class="flex items-start p-3 rounded-lg border text-sm"
                    :class="alertClass"
                >
                    <span class="flex-1">{{ alertMessage }}</span>
                    <button
                        class="ml-2 text-gray-400 hover:text-gray-600"
                        @click="alertMessage = ''"
                    >
                        <font-awesome-icon
                            :icon="['fas', 'times']"
                            class="w-3 h-3"
                        />
                    </button>
                </div>

                <button
                    class="w-full flex items-center justify-center gap-3 px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold hover:from-indigo-500 hover:to-purple-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed shadow-md"
                    :disabled="
                        isDonating ||
                        !amount ||
                        Number(amount) <= 0
                    "
                    @click="handleStripe"
                >
                    <font-awesome-icon
                        :icon="['fab', 'cc-stripe']"
                        class="w-5 h-5"
                    />
                    <span>{{ isDonating ? 'Processing...' : 'Stripe' }}</span>
                </button>

            </template>
        </div>

        <template #footer>
            <Button
                variant="secondary"
                text="Cancel"
                width="auto"
                class="px-6"
                @click="$emit('update:modelValue', false)"
            />
        </template>
    </Modal>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { donateService } from '~/services/donateService'

const authStore = useAuthStore()

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    formula: {
        type: Array,
        default: () => [],
    },
    details: {
        type: String,
        default: '',
    },
})

const emit = defineEmits([
    'update:modelValue',
    'paymentSuccess',
    'paymentError',
])

const quickAmounts = [10, 25, 50, 100, 250, 500]

const amount = ref('')
const isDonating = ref(false)
const alertMessage = ref('')
const alertVariant = ref('error')

const alertClass = computed(() => {
    const map = {
        success: 'bg-green-100 border-green-400 text-green-700',
        error: 'bg-red-100 border-red-400 text-red-700',
    }
    return map[alertVariant.value] || ''
})

async function handleStripe() {
    if (!amount.value || Number(amount.value) <= 0) return

    isDonating.value = true
    alertMessage.value = ''
    try {
        const params = {
            amount: Number(amount.value),
            donor_name: authStore.user?.username || '',
        }
        const response = await donateService.createCheckoutSession(params)
        if (response.success && response.data?.checkout_url) {
            // Redirect user to Stripe Checkout
            window.location.href = response.data.checkout_url
        } else {
            alertVariant.value = 'error'
            alertMessage.value =
                response.errors?.[0] ||
                response.message ||
                'Failed to create checkout session'
            isDonating.value = false
        }
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value =
            error?.errors?.[0] || error?.message || 'Failed to process donation'
        isDonating.value = false
    }
}

watch(
    () => props.modelValue,
    (val) => {
        if (val) {
            amount.value = ''
            alertMessage.value = ''
            isDonating.value = false
        }
    }
)
</script>
