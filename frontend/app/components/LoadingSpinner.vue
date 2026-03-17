<template>
    <div class="flex flex-col items-center">
        <div
            class="inline-block animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-indigo-600"
        />
        <p v-if="text" class="mt-2 text-gray-600">
            {{ text }}
        </p>
    </div>
</template>

<script>
export default {
    props: {
        text: {
            type: String,
            default: '',
        },
    },
}
</script>
