<template>
    <main class="w-full mx-auto">
        <div class="container mx-auto p-4 md:p-6 w-full sm:w-2/3">
            <h1 class="text-2xl lg:text-4xl font-bold text-gray-800 mb-6 text-center">
                {{ t('contact.title') }}
            </h1>

            <!-- Message -->
            <AlertMessage
                v-if="showAlert"
                :variant="alertVariant"
                :message="alertMessage"
                @close="showAlert = false"
            />

            <!-- Form -->
            <form @submit.prevent="onSubmit">
                <!-- Flex container for First Name and Last Name -->
                <div class="flex space-x-4">
                    <div class="flex-1">
                        <label for="firstname" class="block text-sm font-medium text-gray-700"
                            >{{ t('contact.firstName') }}
                        </label>
                        <FormInput
                            v-model="firstname"
                            type="text"
                            :placeholder="t('contact.firstNamePlaceholder')"
                            class="mb-3"
                            v-bind="firstnameProps"
                            :error-message="errors['firstname']"
                        />
                    </div>

                    <div class="flex-1">
                        <label for="lastname" class="block text-sm font-medium text-gray-700"
                            >{{ t('contact.lastName') }}
                        </label>
                        <FormInput
                            v-model="lastname"
                            type="text"
                            :placeholder="t('contact.lastNamePlaceholder')"
                            class="mb-3"
                            v-bind="lastnameProps"
                            :error-message="errors['lastname']"
                        />
                    </div>
                </div>

                <!-- Email -->
                <label for="email" class="block text-sm font-medium text-gray-700">
                    {{ t('contact.email') }}
                </label>
                <FormInput
                    v-model="email"
                    type="email"
                    :placeholder="t('contact.emailPlaceholder')"
                    class="mb-3"
                    v-bind="emailProps"
                    :error-message="errors['email']"
                />

                <!-- Subject -->
                <label for="subject" class="block text-sm font-medium text-gray-700">
                    {{ t('contact.subject') }}
                </label>
                <FormInput
                    v-model="subject"
                    type="text"
                    :placeholder="t('contact.subjectPlaceholder')"
                    class="mb-3"
                    v-bind="subjectProps"
                    :error-message="errors['subject']"
                />

                <!-- Message Details (Textarea) -->
                <label for="message" class="block text-sm font-medium text-gray-700">
                    {{ t('contact.message') }}
                </label>
                <FormTextarea
                    v-model="message"
                    :placeholder="t('contact.messagePlaceholder')"
                    class="mb-3"
                    v-bind="messageProps"
                    :error-message="errors['message']"
                />

                <!-- Submit Button -->
                <div class="flex justify-center">
                    <FormSubmitButton
                        :text="t('common.submit')"
                        type="submit"
                        variant="primary"
                        @click="onSubmit"
                    />
                </div>
            </form>
        </div>
    </main>
</template>

<script setup>
    import { useForm } from 'vee-validate'
    import * as yup from 'yup'
    import api from '~/config/apiConfig'

    const { t } = useI18n()

    useHead({
        title: t('contact.title'),
    })

    const showAlert = ref(false)
    const alertVariant = ref('')
    const alertMessage = ref('')

    const validationSchema = yup.object({
        firstname: yup.string().required(t('contact.firstNameRequired')),
        lastname: yup.string().required(t('contact.lastNameRequired')),
        subject: yup.string().required(t('contact.subjectRequired')),
        message: yup.string().required(t('contact.messageRequired')),
        email: yup.string().required(t('contact.emailRequired')).email(t('contact.emailInvalid')),
    })

    // Setup VeeValidate
    const { handleSubmit, defineField, errors, resetForm } = useForm({
        validationSchema,
    })

    // Define fields using defineField
    const [firstname, firstnameProps] = defineField('firstname')
    const [lastname, lastnameProps] = defineField('lastname')
    const [subject, subjectProps] = defineField('subject')
    const [message, messageProps] = defineField('message')
    const [email, emailProps] = defineField('email')

    const onSubmit = handleSubmit(async (values) => {
        try {
            const response = await api.post('/contact', values)
            if (response.success) {
                alertVariant.value = 'success'
                alertMessage.value = t('contact.sentSuccess')
                resetForm()
            } else {
                alertVariant.value = 'error'
                alertMessage.value = response.errors?.[0] || t('contact.failedToSend')
            }
        } catch (error) {
            alertVariant.value = 'error'
            alertMessage.value = error?.errors?.[0] || error?.message || t('contact.failedToSend')
        } finally {
            showAlert.value = true
        }
    })
</script>
