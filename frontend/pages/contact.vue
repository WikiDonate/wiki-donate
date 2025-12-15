<template>
    <main class="w-full mx-auto">
        <div class="container mx-auto p-4 md:p-6 w-full sm:w-2/3">
            <h1
                class="text-2xl lg:text-4xl font-bold text-gray-800 mb-6 text-center"
            >
                Contact Us
            </h1>

            <!-- Message -->
            <AlertMessage
                v-if="showAlert"
                :variant="success"
                :message="alertMessage"
                @close="showAlert = false"
            />

            <!-- Form -->
            <form @submit.prevent="onSubmit">
                <!-- Flex container for First Name and Last Name -->
                <div class="flex space-x-4">
                    <div class="flex-1">
                        <label
                            for="firstname"
                            class="block text-sm font-medium text-gray-700"
                            >First Name
                        </label>
                        <FormInput
                            v-model="firstname"
                            type="text"
                            placeholder="Enter your first name"
                            class="mb-3"
                            v-bind="firstnameProps"
                            :error-message="errors['firstname']"
                        />
                    </div>

                    <div class="flex-1">
                        <label
                            for="lastname"
                            class="block text-sm font-medium text-gray-700"
                            >Last Name
                        </label>
                        <FormInput
                            v-model="lastname"
                            type="text"
                            placeholder="Enter your last name"
                            class="mb-3"
                            v-bind="lastnameProps"
                            :error-message="errors['lastname']"
                        />
                    </div>
                </div>

                <!-- Email -->
                <label
                    for="email"
                    class="block text-sm font-medium text-gray-700"
                >
                    Email
                </label>
                <FormInput
                    v-model="email"
                    type="email"
                    placeholder="Enter your email"
                    class="mb-3"
                    v-bind="emailProps"
                    :error-message="errors['email']"
                />

                <!-- Subject -->
                <label
                    for="subject"
                    class="block text-sm font-medium text-gray-700"
                >
                    Subject
                </label>
                <FormInput
                    v-model="subject"
                    type="text"
                    placeholder="Enter your subject"
                    class="mb-3"
                    v-bind="subjectProps"
                    :error-message="errors['subject']"
                />

                <!-- Message Details (Textarea) -->
                <label
                    for="message"
                    class="block text-sm font-medium text-gray-700"
                >
                    Message Details
                </label>
                <FormTextarea
                    v-model="message"
                    placeholder="Enter your message details"
                    class="mb-3"
                    v-bind="messageProps"
                    :error-message="errors['message']"
                />

                <!-- Submit Button -->
                <div class="flex justify-center">
                    <FormSubmitButton
                        text="Submit"
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

useHead({
    title: 'Contact Us',
})

const showAlert = ref(false)
const alertVariant = ref('')
const alertMessage = ref('')

const validationSchema = yup.object({
    firstname: yup.string().required('First name is required'),
    lastname: yup.string().required('Last name is required'),
    subject: yup.string().required('Subject name is required'),
    message: yup.string().required('Message details is required'),
    email: yup
        .string()
        .required('Email is required')
        .email('Email must be a valid email'),
})

// Setup VeeValidate
const { handleSubmit, defineField, errors } = useForm({
    validationSchema,
})

// Define fields using defineField
const [firstname, firstnameProps] = defineField('firstname')
const [lastname, lastnameProps] = defineField('lastname')
const [subject, subjectProps] = defineField('subject')
const [message, messageProps] = defineField('message')
const [email, emailProps] = defineField('email')

const onSubmit = handleSubmit(async (values) => {
    console.log(values)
    showAlert.value = true
    alertVariant.value = 'success'
    alertMessage.value = 'Your message has been sent successfully!'
    showAlert.value = true
})
</script>
