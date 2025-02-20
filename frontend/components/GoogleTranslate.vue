<!-- components/GoogleTranslate.vue -->
<template>
    <div id="translate-div" class="translate-container">
        <div id="google_translate_element" class="translate-widget" />
    </div>
</template>

<script setup>
onMounted(() => {
    //first hide widget;
    const ltrans = document.querySelector('#translate-div')
    ltrans.style.display = 'none'

    const modifyGoogleTranslate = () => {
        // Select the image and hide it
        const image = document.querySelector('.goog-te-gadget-icon')

        if (image) {
            image.style.display = 'none'
        }

        // Add flex to the dynamically generated class
        const translateElement = document.querySelector(
            '.VIpgJd-ZVi9od-xl07Ob-lTBxed'
        )
        if (translateElement) {
            translateElement.style.display = 'flex'
            translateElement.style.alignItems = 'center'
        }

        // Show the widget after a short delay
        setTimeout((ltrans.style.display = 'block'), 1000)
    }

    // Apply modifications after the widget loads
    setTimeout(modifyGoogleTranslate, 1000)
})
</script>

<!-- extra css include here -->

<style scoped>
.translate-container[data-v-63187f89] {
    position: relative;
    width: 100px !important;
    max-width: none !important;
    /* margin: 0 auto; */
    padding: 0px !important;
    background-color: #fff !important;
    border-radius: None !important;
    box-shadow: none !important;
}

.translate-widget {
    font-family: Arial, sans-serif; /* Consistent font */
}

.goog-te-gadget {
    font-size: 14px; /* Adjust font size */
}

select.goog-te-combo {
    padding: 8px; /* Padding for dropdown */
    border-radius: 4px; /* Rounded corners for dropdown */
    border: 1px solid #ccc; /* Border color */
}

/* Custom styles for the dropdown options */
select.goog-te-combo option {
    background-color: #fff; /* Background color for options */
}

/* Hide Google branding if necessary */
.goog-logo-link,
.goog-te-banner-frame {
    display: none !important;
}

/* Responsive Design */
@media (max-width: 600px) {
    .translate-container {
        width: calc(100% - 20px); /* Full width on smaller screens */
        padding: 5px;
        margin: auto;
    }
}
</style>
