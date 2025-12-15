<template>
    <div
        v-if="isVisible"
        class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center"
    >
        <div class="bg-white p-4 rounded-lg shadow-lg w-full max-w-lg">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-xl">Reply</h3>
                <button
                    class="text-gray-600 hover:text-gray-900"
                    @click="closeModal"
                >
                    &times;
                </button>
            </div>
            <form @submit.prevent="submitReply">
                <textarea
                    v-model="replyText"
                    class="w-full border p-2 rounded-md"
                    rows="4"
                    placeholder="Write your reply..."
                />
                <div class="mt-4 flex justify-end space-x-2">
                    <button
                        type="button"
                        class="bg-gray-300 px-4 py-2 rounded-md"
                        @click="closeModal"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="bg-blue-500 text-white px-4 py-2 rounded-md"
                    >
                        Submit
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'

const isVisible = ref(false)
const replyText = ref('')

// Close the modal
const closeModal = () => {
    isVisible.value = false
    replyText.value = '' // Clear input after closing
}

// Emit reply and close the modal
const submitReply = () => {
    console.log('Submitted reply:', replyText.value)
    closeModal()
}

// Function to open the modal
const openModal = () => {
    isVisible.value = true
}
</script>
