<template>
    <main class="w-full">
        <!-- Top bartitle -->
        <TopBarTitle :page-title="'Preferences'" />

        <!-- Message -->
        <AlertMessage
            v-if="showAlert"
            :variant="alertVariant"
            :message="alertMessage"
            @close="showAlert = false"
        />

        <!-- Tabs Section -->
        <section class="bg-white">
            <!-- Tabs -->
            <div class="flex border-b border-b-gray-300 items-center mb-2">
                <button
                    class="px-4 py-2 focus:outline-none text-sm"
                    :class="
                        activeTab === 'profile'
                            ? 'text-blue-500 border-b-2 border-blue-500'
                            : 'text-gray-800 hover:text-blue-500'
                    "
                    @click="activeTab = 'profile'"
                >
                    User Profile
                </button>

                <button
                    class="px-4 py-2 focus:outline-none text-sm"
                    :class="
                        activeTab === 'notifications'
                            ? 'text-blue-500 border-b-2 border-blue-500'
                            : 'text-gray-800 hover:text-blue-500'
                    "
                    @click="activeTab = 'notifications'"
                >
                    Notifications
                </button>
            </div>

            <!-- Tab Content -->
            <div>
                <!-- User Profile Tab -->
                <div v-if="activeTab === 'profile'" class="p-4">
                    <h2 class="font-bold text-md mb-2">Basic Information</h2>
                    <!-- Table for user information -->
                    <table class="table-auto w-full text-sm">
                        <tbody>
                            <tr>
                                <td class="py-2 pr-4 w-40">Username:</td>
                                <td class="py-2">
                                    {{ authStore.user.username }}
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 w-40">Email:</td>
                                <td class="py-2">{{ authStore.user.email }}</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 w-40">Member Of Group:</td>
                                <td class="py-2">
                                    {{ authStore.user.roles[0] }}
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 w-40">Number Of Edits:</td>
                                <td class="py-2">0</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 w-40">
                                    Registration Time:
                                </td>
                                <td class="py-2">
                                    {{ authStore.user.createdAt }}
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 w-40">Password:</td>
                                <td class="py-2">
                                    <span
                                        class="font-bold cursor-pointer"
                                        @click="openModal"
                                        >Change Password</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Notifications Tab -->
                <div v-if="activeTab === 'notifications'" class="p-4">
                    <table class="table-auto w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-left">
                                    <h2 class="font-bold text-md mb-2">
                                        Notify me about these events
                                    </h2>
                                </th>
                                <th class="text-left">Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="py-2 pr-4 w-80">
                                    Edit to my talk page
                                </td>
                                <td class="py-2">
                                    <Checkbox v-model="editTalkPage" />
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 w-80">
                                    Edit to my user page
                                </td>
                                <td class="py-2">
                                    <Checkbox v-model="editUserPage" />
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 w-80">Page review</td>
                                <td class="py-2">
                                    <Checkbox v-model="pageReview" />
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 w-80">
                                    Email from other user
                                </td>
                                <td class="py-2">
                                    <Checkbox v-model="emailFromOther" />
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 w-80">
                                    Successful mention
                                </td>
                                <td class="py-2">
                                    <Checkbox v-model="successfulMention" />
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <Button
                                        text="Save"
                                        variant="primary"
                                        class="w-[100px] mt-4"
                                        @click="saveNotifications()"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Modal for Changing Password -->
        <div
            v-if="isModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900 bg-opacity-50"
        >
            <div class="bg-white p-6 rounded-md w-full max-w-md">
                <h3 class="text-lg font-bold mb-4">Change Password</h3>

                <form @submit.prevent="submitChangePassword">
                    <AlertMessage
                        v-if="showAlertError"
                        variant="error"
                        :message="alertMessage"
                        @close="showAlertError = false"
                    />

                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1"
                            >New Password</label
                        >
                        <FormInput
                            v-model="password"
                            type="password"
                            placeholder="Enter your password"
                            class="mb-3"
                            v-bind="passwordProps"
                            :error-message="errors['password']"
                        />
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1"
                            >Confirm Password</label
                        >
                        <FormInput
                            v-model="confirmPassword"
                            type="password"
                            placeholder="Confirm password"
                            class="mb-3"
                            v-bind="confirmPasswordProps"
                            :error-message="errors['confirmPassword']"
                        />
                    </div>

                    <div class="flex justify-end space-x-2">
                        <button
                            type="button"
                            class="bg-gray-300 px-4 py-2 rounded-md"
                            @click="closeModal"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="bg-blue-600 text-white px-4 py-2 rounded-md"
                        >
                            Submit
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</template>

<script setup>
import { useForm } from 'vee-validate'
import { ref } from 'vue'
import * as yup from 'yup'
import { userService } from '~/services/userService'

definePageMeta({
    middleware: 'auth',
})

useHead({
    title: 'Preferences',
})

const authStore = useAuthStore()

// Active tab state
const activeTab = ref('profile')
// Alert state
const showAlert = ref(false)
const alertVariant = ref('success')
const alertMessage = ref('')
const showAlertError = ref(false)
// Preferences state
const editTalkPage = ref(false)
const editUserPage = ref(false)
const pageReview = ref(false)
const emailFromOther = ref(false)
const successfulMention = ref(false)

// Function to save preferences
const saveNotifications = async () => {
    // Reset alert message
    showAlert.value = false
    try {
        const response = await userService.updateNotifications({
            editTalkPage: editTalkPage.value ? 1 : 0,
            editUserPage: editUserPage.value ? 1 : 0,
            pageReview: pageReview.value ? 1 : 0,
            emailFromOther: emailFromOther.value ? 1 : 0,
            successfulMention: successfulMention.value ? 1 : 0,
        })

        if (!response.success) {
            alertVariant.value = 'error'
            alertMessage.value = response.errors[0]
            showAlert.value = true
            return
        }

        alertVariant.value = 'success'
        alertMessage.value = response.message
        showAlert.value = true
        loadNotifications()
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value = error.errors[0]
        showAlert.value = true
    }
}

// Function to load notification preferences
const loadNotifications = async () => {
    try {
        const response = await userService.getNotifications()

        if (Object.keys(response.data).length > 0) {
            editTalkPage.value = response.data.editTalkPage === 1
            editUserPage.value = response.data.editUserPage === 1
            pageReview.value = response.data.pageReview === 1
            emailFromOther.value = response.data.emailFromOther === 1
            successfulMention.value = response.data.successfulMention === 1
        }
    } catch (error) {
        alertVariant.value = 'error'
        alertMessage.value = error.errors[0]
        showAlert.value = true
    }
}

// Define validation schema
const validationSchema = yup.object({
    password: yup
        .string()
        .required('Password is required')
        .min(6, 'Password must be at least 6 characters'),
    confirmPassword: yup
        .string()
        .required('Confirm Password is required')
        .oneOf([yup.ref('password'), null], 'Passwords must match'),
})

// Setup VeeValidate
const { handleSubmit, defineField, resetForm, errors } = useForm({
    validationSchema,
})

// Define fields using defineField
const [password, passwordProps] = defineField('password')
const [confirmPassword, confirmPasswordProps] = defineField('confirmPassword')

// Function to handle password change
const submitChangePassword = handleSubmit(async (values) => {
    showAlert.value = false

    try {
        const response = await userService.changePassword({
            newPassword: values.password,
            confirmPassword: values.confirmPassword,
        })

        if (!response.success) {
            alertMessage.value = response.errors[0]
            showAlertError.value = true
            return
        }

        alertVariant.value = 'success'
        alertMessage.value = response.message
        showAlert.value = true
        closeModal()
    } catch (error) {
        alertMessage.value = error.errors[0]
        showAlertError.value = true
    }
})

// Modal state
const isModalOpen = ref(false)

// Function to open the modal
const openModal = () => {
    resetForm()
    isModalOpen.value = true
}

// Function to close the modal
const closeModal = () => {
    isModalOpen.value = false
    resetForm()
}

onMounted(() => {
    loadNotifications()
})
</script>
