<template>
    <div
        v-if="modelValue"
        class="fixed inset-0 z-50 flex items-center justify-center overflow-x-hidden overflow-y-auto outline-none focus:outline-none"
    >
        <div
            class="fixed inset-0 bg-black opacity-50"
            @click="$emit('update:modelValue', false)"
        ></div>
        <div class="relative w-full max-w-2xl mx-auto my-6 z-50">
            <div
                class="relative flex flex-col w-full bg-white border-0 rounded-lg shadow-lg outline-none focus:outline-none"
            >
                <!-- Header -->
                <div
                    class="flex items-start justify-between p-5 border-b border-solid border-gray-200 rounded-t"
                >
                    <h3 class="text-2xl font-semibold">{{ title }}</h3>
                    <button
                        class="p-1 ml-auto bg-transparent border-0 text-gray-400 float-right text-3xl leading-none font-semibold outline-none focus:outline-none hover:text-gray-600"
                        @click="$emit('update:modelValue', false)"
                    >
                        <span
                            class="bg-transparent text-gray-400 h-6 w-6 text-2xl block outline-none focus:outline-none"
                        >
                            ×
                        </span>
                    </button>
                </div>
                <!-- Body -->
                <div class="relative p-6 flex-auto">
                    <slot></slot>
                </div>
                <!-- Footer -->
                <div
                    v-if="$slots.footer"
                    class="flex items-center justify-end p-6 border-t border-solid border-gray-200 rounded-b gap-2"
                >
                    <slot name="footer"></slot>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: 'Modal Title',
    },
})

defineEmits(['update:modelValue'])
</script>
