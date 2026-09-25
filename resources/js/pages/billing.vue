<template>
    <main class="w-full mx-auto max-w-5xl mt-6 bg-white rounded-2xl shadow-md overflow-hidden">
        <!-- Gradient Header -->
        <div class="bg-linear-to-r from-indigo-600 to-purple-600 py-6 px-6 text-center">
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white tracking-wide">
                {{ t('billing.title') }}
            </h1>
            <p class="text-base sm:text-lg text-indigo-100 mt-2">
                {{ t('billing.subtitle') }}
            </p>
        </div>

        <!-- Content -->
        <div class="container p-4 sm:p-6 md:p-8">
            <!-- Message -->
            <AlertMessage
                v-if="showAlert"
                :variant="alertVariant"
                :message="alertMessage"
                class="mb-6"
                @close="showAlert = false"
            />

            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                <!-- Section Header -->
                <div class="p-3 sm:p-4 md:p-6 border-b border-gray-200 bg-indigo-50 rounded-t-xl">
                    <h2 class="text-xl sm:text-2xl font-semibold text-indigo-700">
                        {{
                            currentCard ? t('billing.updatePaymentMethod') : t('billing.addNewCard')
                        }}
                    </h2>
                </div>

                <!-- Loading state -->
                <div v-if="isFetchingCard" class="p-6 sm:p-8 text-center">
                    <LoadingSpinner :text="t('billing.loadingCard')" />
                </div>

                <!-- Saved Card -->
                <div v-else-if="currentCard && !showCardForm" class="p-4 sm:p-6">
                    <div
                        class="bg-gray-50 p-4 rounded-lg border border-gray-200 flex flex-col sm:flex-row sm:items-center justify-between"
                    >
                        <div class="flex items-center mb-4 sm:mb-0">
                            <div
                                class="bg-indigo-600 px-3 py-2 rounded-md mr-4 text-white font-medium text-sm"
                            >
                                {{ cardBrand }}
                            </div>
                            <div>
                                <h3
                                    class="font-semibold text-gray-800 text-sm sm:text-base md:text-lg"
                                >
                                    **** **** **** {{ currentCard.last4 }}
                                </h3>
                                <p class="text-xs sm:text-sm text-gray-500">
                                    {{
                                        t('billing.expires', {
                                            month: currentCard.exp_month,
                                            year: currentCard.exp_year.toString().slice(-2),
                                        })
                                    }}
                                </p>
                            </div>
                        </div>
                        <button
                            class="inline-flex items-center justify-center bg-linear-to-r from-indigo-600 to-purple-600 text-white text-sm font-medium px-4 py-2 rounded-full shadow hover:from-indigo-500 hover:to-purple-500 transition"
                            @click="showEditForm"
                        >
                            {{ t('billing.edit') }}
                        </button>
                    </div>
                </div>

                <!-- Card Form -->
                <div v-else class="p-4 sm:p-6">
                    <!-- Stripe form loading -->
                    <div v-if="isCardElementMounting" class="text-center py-12">
                        <LoadingSpinner :text="t('billing.loadingPaymentForm')" />
                    </div>

                    <div v-show="!isCardElementMounting" class="space-y-6">
                        <div>
                            <label class="block text-gray-700 text-sm font-semibold mb-2">
                                {{ t('billing.cardNumber') }} <span class="text-red-500">*</span>
                            </label>
                            <div
                                id="card-number"
                                class="p-3 border border-gray-300 rounded-lg focus-within:ring-2 focus-within:ring-indigo-500"
                            />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-gray-700 text-sm font-semibold mb-2">
                                    {{ t('billing.expiryDate') }}
                                    <span class="text-red-500">*</span>
                                </label>
                                <div
                                    id="card-expiry"
                                    class="p-3 border border-gray-300 rounded-lg focus-within:ring-2 focus-within:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-gray-700 text-sm font-semibold mb-2">
                                    {{ t('billing.cvc') }} <span class="text-red-500">*</span>
                                </label>
                                <div
                                    id="card-cvc"
                                    class="p-3 border border-gray-300 rounded-lg focus-within:ring-2 focus-within:ring-indigo-500"
                                />
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex flex-col sm:flex-row gap-4 mt-8">
                            <FormSubmitButton
                                class="flex-1"
                                :text="isSaving ? t('billing.saving') : t('billing.saveCard')"
                                type="button"
                                variant="primary"
                                :disabled="isSaving"
                                @click="handleSubmit"
                            />
                            <FormSubmitButton
                                v-if="currentCard"
                                class="flex-1"
                                :text="t('billing.cancel')"
                                :disabled="isSaving"
                                type="button"
                                variant="secondary"
                                @click="cancelEdit"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</template>

<script setup>
    import { computed, nextTick, onMounted, ref, watch } from 'vue'
    import { useI18n } from 'vue-i18n'
    import AlertMessage from '~/components/AlertMessage.vue'
    import FormSubmitButton from '~/components/FormSubmitButton.vue'
    import LoadingSpinner from '~/components/LoadingSpinner.vue'
    import { paymentMethodService } from '~/services/paymentMethodService'
    import { useClientStripe } from '@/plugins/useClientStripe'

    const { t } = useI18n()

    useHead({
        title: t('billing.title'),
    })

    definePageMeta({
        middleware: 'auth',
    })

    const stripe = ref(null)

    // State
    const showAlert = ref(false)
    const alertVariant = ref('')
    const alertMessage = ref('')
    const isCardElementMounting = ref(true)
    const cardNumberElement = ref(null)
    const cardExpiryElement = ref(null)
    const cardCvcElement = ref(null)
    const isSaving = ref(false)
    const isFetchingCard = ref(true)
    const showCardForm = ref(false)
    const currentCard = ref(null)

    // Computed
    const cardBrand = computed(() => {
        if (!currentCard.value) return ''
        return currentCard.value?.brand?.toUpperCase() || ''
    })

    // Methods
    const showError = (message) => {
        showAlert.value = true
        alertVariant.value = 'error'
        alertMessage.value = message
    }

    const showSuccess = (message) => {
        showAlert.value = true
        alertVariant.value = 'success'
        alertMessage.value = message
    }

    const mountStripeElements = () => {
        if (!document.getElementById('card-number') || !stripe.value) {
            setTimeout(mountStripeElements, 100)
            return
        }

        // Clean up existing elements if any
        if (cardNumberElement.value) {
            cardNumberElement.value.destroy()
        }
        if (cardExpiryElement.value) {
            cardExpiryElement.value.destroy()
        }
        if (cardCvcElement.value) {
            cardCvcElement.value.destroy()
        }

        const elements = stripe.value.elements()
        const style = {
            base: {
                color: '#374151',
                fontFamily: '"Inter", sans-serif',
                fontSize: '16px',
                '::placeholder': {
                    color: '#9CA3AF',
                },
            },
            invalid: {
                color: '#EF4444',
            },
        }

        cardNumberElement.value = elements.create('cardNumber', {
            style,
            showIcon: true,
        })
        cardNumberElement.value.mount('#card-number')

        cardExpiryElement.value = elements.create('cardExpiry', { style })
        cardExpiryElement.value.mount('#card-expiry')

        cardCvcElement.value = elements.create('cardCvc', { style })
        cardCvcElement.value.mount('#card-cvc')

        isCardElementMounting.value = false
    }

    const showEditForm = async () => {
        showCardForm.value = true
        isCardElementMounting.value = true
        await nextTick()
        mountStripeElements()
    }

    const cancelEdit = () => {
        showCardForm.value = false
    }

    const handleSubmit = async (e) => {
        e.preventDefault()
        isSaving.value = true
        showAlert.value = false

        try {
            const { paymentMethod, error } = await stripe.value.createPaymentMethod({
                type: 'card',
                card: cardNumberElement.value,
            })

            if (error) {
                showError(error.message)
                isSaving.value = false
                return
            }

            const response = await paymentMethodService.saveCard({
                payment_method_id: paymentMethod?.id,
            })

            if (response.success) {
                currentCard.value = response.data
                showCardForm.value = false
                showSuccess(t('billing.cardSaved'))
            } else {
                if (response.errors && response.errors.length > 0) {
                    showError(response.errors.join(', ') || t('billing.validationError'))
                } else {
                    showError(response.message || t('billing.failedToSaveCard'))
                }
            }
        } catch (error) {
            if (error?.errors && error?.errors.length > 0) {
                // Also show in alert if you want
                showError(error.errors.join(', ') || t('billing.failedToSaveCard'))
            } else {
                // Fallback to generic error
                showError(error?.message || t('billing.failedToSaveCard'))
            }
        } finally {
            isSaving.value = false
        }
    }

    const fetchPaymentMethod = async () => {
        isFetchingCard.value = true
        try {
            const response = await paymentMethodService.getCard()
            if (response.success) {
                currentCard.value = response.data
                // Only hide the form if we actually got a card
                if (currentCard.value) {
                    showCardForm.value = false
                }
            } else {
                // If no card exists, show the form
                showCardForm.value = true
            }
        } catch (error) {
            if (error?.errors && error?.errors.length > 0) {
                // Also show in alert if you want
                showError(error.errors.join(', ') || t('billing.failedToLoadCard'))
            } else {
                // Fallback to generic error
                showError(error?.message || t('billing.failedToLoadCard'))
            }
            // On error, still show the form so user can add a card
            showCardForm.value = true
        } finally {
            isFetchingCard.value = false
            if (showCardForm.value) {
                await nextTick()
                mountStripeElements()
            }
        }
    }

    onMounted(async () => {
        stripe.value = await useClientStripe()
        await fetchPaymentMethod()

        // If no card exists, mount the form immediately
        if (!currentCard.value) {
            showCardForm.value = true
            await nextTick()
            mountStripeElements()
        }
    })

    // Watch for form visibility changes
    watch(showCardForm, async (newVal) => {
        if (newVal) {
            isCardElementMounting.value = true
            await nextTick()
            mountStripeElements()
        }
    })
</script>
