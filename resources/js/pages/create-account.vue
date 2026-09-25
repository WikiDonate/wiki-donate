<template>
    <main class="w-full bg-white py-8">
        <div class="container mx-auto px-2 sm:px-4 max-w-lg">
            <!-- Card Container -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-200">
                <!-- Header -->
                <div
                    class="p-6 text-center bg-linear-to-r from-indigo-600 to-purple-600 text-white"
                >
                    <h1 class="text-2xl md:text-3xl font-bold">{{ t('auth.createTitle') }}</h1>
                    <p class="mt-2 text-sm">{{ t('auth.createSubtitle') }}</p>
                </div>

                <div class="p-3 sm:p-4 md:p-6">
                    <!-- Success Message after registration -->
                    <div v-if="registrationSuccess" class="space-y-4">
                        <div class="flex justify-center">
                            <div
                                class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center"
                            >
                                <svg
                                    class="w-8 h-8 text-green-500"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                                    ></path>
                                </svg>
                            </div>
                        </div>
                        <h2 class="text-xl font-semibold text-gray-800 text-center">
                            {{ t('auth.checkYourEmail') }}
                        </h2>
                        <p class="text-gray-600 text-sm text-center">
                            {{ t('auth.verificationSent', { email: registeredEmail }) }}
                        </p>
                        <p class="text-gray-500 text-xs text-center">
                            {{ t('auth.didntReceive') }}
                            <button
                                type="button"
                                class="font-medium text-indigo-600 hover:text-indigo-500 hover:underline"
                                :disabled="resendCooldown > 0"
                                @click="resendVerification"
                            >
                                {{
                                    resendCooldown > 0
                                        ? t('auth.resendIn', { n: resendCooldown })
                                        : t('auth.resendVerificationEmail')
                                }}
                            </button>
                        </p>
                        <div class="pt-4 text-center">
                            <NuxtLink
                                to="/login"
                                class="inline-block bg-linear-to-r from-indigo-600 to-purple-600 text-white font-medium px-6 py-3 rounded-lg hover:from-indigo-700 hover:to-purple-700 transition-all duration-200 shadow-md hover:shadow-lg"
                            >
                                {{ t('auth.goToLogin') }}
                            </NuxtLink>
                        </div>
                    </div>

                    <!-- Message -->
                    <AlertMessage
                        v-else-if="showAlert"
                        :variant="alertVariant"
                        :message="alertMessage"
                        class="mb-6"
                        @close="showAlert = false"
                    />

                    <!-- Form -->
                    <form v-if="!registrationSuccess" class="space-y-4" @submit.prevent="onSubmit">
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ t('auth.username') }} <span class="text-red-500">*</span>
                        </label>
                        <FormInput
                            v-model="username"
                            type="text"
                            :placeholder="t('auth.usernamePlaceholder')"
                            v-bind="usernameProps"
                            :error-message="errors['username']"
                        />
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ t('auth.password') }} <span class="text-red-500">*</span>
                        </label>
                        <FormInput
                            v-model="password"
                            type="password"
                            :placeholder="t('auth.passwordPlaceholder')"
                            v-bind="passwordProps"
                            :error-message="errors['password']"
                        />
                        <label
                            for="confirmPassword"
                            class="block text-sm font-medium text-gray-700 mb-1"
                        >
                            {{ t('auth.confirmPassword') }}
                            <span class="text-red-500">*</span>
                        </label>
                        <FormInput
                            v-model="confirmPassword"
                            type="password"
                            :placeholder="t('auth.confirmPasswordPlaceholder')"
                            v-bind="confirmPasswordProps"
                            :error-message="errors['confirmPassword']"
                        />
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                            {{ t('auth.email') }} <span class="text-red-500">*</span>
                        </label>
                        <FormInput
                            v-model="email"
                            type="email"
                            :placeholder="t('auth.emailPlaceholder')"
                            v-bind="emailProps"
                            :error-message="errors['email']"
                        />

                        <!-- <div>
                        <RecaptchaV2
                            @error-callback="handleErrorCallback"
                            @expired-callback="handleExpiredCallback"
                            @load-callback="handleLoadCallback"
                        />
                    </div> -->

                        <!-- Submit Button -->
                        <div class="flex justify-center mt-4">
                            <FormSubmitButton
                                :text="isLoading ? t('auth.creating') : t('auth.createAccount')"
                                type="submit"
                                variant="primary"
                                :disabled="isLoading"
                            />
                        </div>

                        <!-- Login Link -->
                        <div class="text-center text-sm text-gray-600 mt-6">
                            {{ t('auth.alreadyHaveAccount') }}
                            <NuxtLink
                                to="/login"
                                class="font-medium text-indigo-600 hover:text-indigo-500 hover:underline ml-1"
                            >
                                {{ t('auth.signIn') }}
                            </NuxtLink>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</template>

<script setup>
    import { useForm } from 'vee-validate'
    import * as yup from 'yup'
    import { useI18n } from 'vue-i18n'
    import { userService } from '~/services/userService'

    const { t } = useI18n()

    useHead({
        title: t('auth.createTitle'),
    })

    const showAlert = ref(false)
    const alertVariant = ref('')
    const alertMessage = ref('')
    const registrationSuccess = ref(false)
    const registeredEmail = ref('')
    const resendCooldown = ref(0)
    let resendTimer = null

    const validationSchema = yup.object({
        username: yup
            .string()
            .required(t('auth.usernameRequired'))
            .min(3, t('auth.minChars', { n: 3 })),
        password: yup
            .string()
            .required(t('auth.passwordRequired'))
            .min(6, t('auth.minChars', { n: 6 })),
        confirmPassword: yup
            .string()
            .required(t('auth.confirmPasswordRequired'))
            .oneOf([yup.ref('password'), null], t('auth.passwordsMustMatch')),
        email: yup.string().required(t('auth.emailRequired')).email(t('auth.emailInvalid')),
    })

    // Setup VeeValidate
    const { handleSubmit, defineField, errors, resetForm } = useForm({
        validationSchema,
    })

    // Define fields using defineField
    const [username, usernameProps] = defineField('username')
    const [password, passwordProps] = defineField('password')
    const [confirmPassword, confirmPasswordProps] = defineField('confirmPassword')
    const [email, emailProps] = defineField('email')

    const isLoading = ref(false)

    const startResendCooldown = () => {
        resendCooldown.value = 60
        resendTimer = setInterval(() => {
            resendCooldown.value--
            if (resendCooldown.value <= 0) {
                clearInterval(resendTimer)
            }
        }, 1000)
    }

    const resendVerification = async () => {
        if (resendCooldown.value > 0) return

        try {
            const response = await userService.resendVerificationEmail({
                email: registeredEmail.value,
            })
            if (response.success) {
                alertVariant.value = 'success'
                alertMessage.value = t('auth.verificationEmailSent')
                showAlert.value = true
                startResendCooldown()
            } else {
                alertVariant.value = 'error'
                alertMessage.value =
                    response.errors?.[0] || response.message || t('auth.failedToResend')
                showAlert.value = true
            }
        } catch (error) {
            alertVariant.value = 'error'
            alertMessage.value = error.errors?.[0] || error.message || t('auth.failedToResend')
            showAlert.value = true
        }
    }

    const onSubmit = handleSubmit(async (values) => {
        // Reset alert visibility
        showAlert.value = false
        isLoading.value = true

        try {
            const response = await userService.register({
                ...values,
            })
            if (!response.success) {
                setTimeout(() => {
                    alertVariant.value = 'error'
                    alertMessage.value = response.errors[0]
                    showAlert.value = true
                }, 0)
                isLoading.value = false
                return
            }

            registeredEmail.value = values.email
            resetForm()
            registrationSuccess.value = true
            startResendCooldown()
        } catch (error) {
            setTimeout(() => {
                alertVariant.value = 'error'
                alertMessage.value = error.errors?.[0] || t('auth.registrationFailed')
                showAlert.value = true
            }, 0)
        } finally {
            isLoading.value = false
        }
    })

    onUnmounted(() => {
        if (resendTimer) clearInterval(resendTimer)
    })
</script>
