<template>
    <RouterLink v-bind="linkProps">
        <slot />
    </RouterLink>
</template>

<script setup>
import { computed, useAttrs } from 'vue'
import { RouterLink } from 'vue-router'

const attrs = useAttrs()

const linkProps = computed(() => {
    const { exact, ...rest } = attrs
    return rest
})
</script>
