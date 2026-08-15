<template>
    <div id="nuxt_translate_element" />
</template>

<script setup>
// This component hosts the hidden Google Translate widget.
// The visible UI is provided by LanguageSelect.vue.
</script>

<style scoped>
#nuxt_translate_element {
    height: 0;
    overflow: hidden;
    visibility: hidden;
    width: 0;
}

:global(body),
:global(html) {
    margin: 0 !important;
    top: 0 !important;
}

:global(.goog-te-banner-frame),
:global(.goog-te-balloon-frame),
:global(.goog-te-combo),
:global(.goog-te-gadget),
:global(.goog-te-gadget-icon),
:global(.goog-te-gadget-simple),
:global(.goog-te-menu-value),
:global(.goog-te-spinner),
:global(.skiptranslate),
:global(.skiptranslate > *),
:global(iframe.VIpgJd-ZVi9od-ORHb-OEVmcd) {
    display: none !important;
    pointer-events: none !important;
}
</style>
