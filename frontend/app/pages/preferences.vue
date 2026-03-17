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
            <div
                class="flex border-b border-b-indigo-300 items-center mb-2 py-2"
            >
                <div class="flex space-x-2 sm:space-x-4 text-sm flex-shrink-0">
                    <button
                        class="px-3 py-1.5 rounded-full font-medium transition-all duration-200"
                        :class="
                            activeTab === 'profile'
                                ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-sm'
                                : 'text-gray-700 hover:text-indigo-600 hover:bg-indigo-50'
                        "
                        @click="activeTab = 'profile'"
                    >
                        User Profile
                    </button>

                    <button
                        class="px-3 py-1.5 rounded-full font-medium transition-all duration-200"
                        :class="
                            activeTab === 'notifications'
                                ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-sm'
                                : 'text-gray-700 hover:text-indigo-600 hover:bg-indigo-50'
                        "
                        @click="activeTab = 'notifications'"
                    >
                        Notifications
                    </button>
                </div>
            </div>

            <!-- Tab Content -->
            <div class="p-4 sm:p-5">
                <!-- User Profile Tab -->
                <div v-if="activeTab === 'profile'">
                    <h2
                        class="font-semibold text-lg text-gray-900 mb-4 border-b pb-2"
                    >
                        Basic Information
                    </h2>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
                        <div>
                            <dt class="text-sm text-gray-500">Username</dt>
                            <dd class="font-medium text-gray-800">
                                {{ authStore.user.username }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Email</dt>
                            <dd class="font-medium text-gray-800">
                                {{ authStore.user.email }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">
                                Member of Group
                            </dt>
                            <dd class="font-medium text-gray-800">
                                {{ authStore.user.roles[0] }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">
                                Number of Edits
                            </dt>
                            <dd class="font-medium text-gray-800">0</dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">
                                Registration Time
                            </dt>
                            <dd class="font-medium text-gray-800">
                                {{ authStore.user.createdAt }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Password</dt>
                            <dd>
                                <span
                                    class="text-indigo-600 font-semibold cursor-pointer hover:underline"
                                    @click="openModal"
                                    >Change Password</span
                                >
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Notifications Tab -->
                <div v-if="activeTab === 'notifications'">
                    <h2
                        class="font-semibold text-lg text-gray-900 mb-4 border-b pb-2"
                    >
                        Notification Preferences
                    </h2>
                    <!-- loader -->
                    <div
                        v-if="loading"
                        class="flex items-center justify-center py-12"
                    >
                        <LoadingSpinner text="Loading Preferences" />
                    </div>

                    <div v-else class="space-y-4">
                        <div
                            class="flex items-center justify-between border-b pb-2"
                        >
                            <span class="text-gray-700 text-sm"
                                >Edit to my talk page</span
                            >
                            <Checkbox v-model="editTalkPage" />
                        </div>
                        <div
                            class="flex items-center justify-between border-b pb-2"
                        >
                            <span class="text-gray-700 text-sm"
                                >Edit to my user page</span
                            >
                            <Checkbox v-model="editUserPage" />
                        </div>
                        <div
                            class="flex items-center justify-between border-b pb-2"
                        >
                            <span class="text-gray-700 text-sm"
                                >Page review</span
                            >
                            <Checkbox v-model="pageReview" />
                        </div>
                        <div
                            class="flex items-center justify-between border-b pb-2"
                        >
                            <span class="text-gray-700 text-sm"
                                >Email from other user</span
                            >
                            <Checkbox v-model="emailFromOther" />
                        </div>
                        <div
                            class="flex items-center justify-between border-b pb-2"
                        >
                            <span class="text-gray-700 text-sm"
                                >Successful mention</span
                            >
                            <Checkbox v-model="successfulMention" />
                        </div>

                        <div class="pt-4">
                            <FormSubmitButton
                                :text="
                                    submittingNotifications
                                        ? 'Saving...'
                                        : 'Save Preferences'
                                "
                                :disabled="submittingNotifications"
                                class="w-full sm:w-[160px]"
                                @click="saveNotifications()"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Modal for Changing Password -->
        <div
            v-if="isModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900 bg-opacity-50 p-4"
        >
            <div
                class="bg-white rounded-xl shadow-lg w-full max-w-md p-6 relative"
            >
                <h3
                    class="text-lg font-bold mb-4 bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-purple-600"
                >
                    Change Password
                </h3>

                <form @submit.prevent="submitChangePassword">
                    <AlertMessage
                        v-if="showAlertError"
                        variant="error"
                        :message="alertMessage"
                        class="mb-3"
                        @close="showAlertError = false"
                    />

                    <div class="mb-4">
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >New Password</label
                        >
                        <FormInput
                            v-model="password"
                            type="password"
                            placeholder="Enter your password"
                            v-bind="passwordProps"
                            :error-message="errors['password']"
                        />
                    </div>

                    <div class="mb-4">
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >Confirm Password</label
                        >
                        <FormInput
                            v-model="confirmPassword"
                            type="password"
                            placeholder="Confirm password"
                            v-bind="confirmPasswordProps"
                            :error-message="errors['confirmPassword']"
                        />
                    </div>

                    <div class="flex justify-end space-x-2">
                        <button
                            type="button"
                            class="bg-gray-200 px-4 py-2 rounded-lg text-gray-700 hover:bg-gray-300 disabled:opacity-50 disabled:cursor-not-allowed"
                            :disabled="submittingPassword"
                            @click="closeModal"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="bg-gradient-to-r from-indigo-600 to-purple-600 transition-all duration-300 text-white hover:bg-indigo-700 hover:from-indigo-500 hover:to-purple-500 px-4 py-2 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
                            :disabled="submittingPassword"
                        >
                            {{
                                submittingPassword ? 'Submitting...' : 'Submit'
                            }}
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
const loading = ref(false)
const submittingNotifications = ref(false)

// Modal state
const isModalOpen = ref(false)
const submittingPassword = ref(false)

// Function to save preferences
const saveNotifications = async () => {
    if (submittingNotifications.value) return
    submittingNotifications.value = true
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
            throw new Error(
                response.errors?.[0] || 'Failed to save preferences'
            )
        }

        alertVariant.value = 'success'
        alertMessage.value = response.message
        showAlert.value = true
        await loadNotifications()
    } catch (error) {
        console.error(error)
        alertVariant.value = 'error'
        alertMessage.value =
            error.errors[0] || error.message || 'Unexpected error'
        showAlert.value = true
    } finally {
        submittingNotifications.value = false
    }
}

// Function to load notification preferences
const loadNotifications = async () => {
    loading.value = true
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
        console.error(error)
        alertVariant.value = 'error'
        alertMessage.value =
            error.errors[0] || error.message || 'Unexpected error'
        showAlert.value = true
    } finally {
        loading.value = false
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
    if (submittingPassword.value) return
    submittingPassword.value = true
    showAlert.value = false

    try {
        const response = await userService.changePassword({
            newPassword: values.password,
            confirmPassword: values.confirmPassword,
        })

        if (!response.success) {
            throw new Error(response.errors?.[0] || 'Failed to change password')
        }

        alertVariant.value = 'success'
        alertMessage.value = response.message
        showAlert.value = true
        closeModal()
    } catch (error) {
        console.error(error)
        alertVariant.value = 'error'
        alertMessage.value =
            error.errors?.[0] || error.message || 'Unexpected error'
        showAlertError.value = true
    } finally {
        submittingPassword.value = false
    }
})

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
