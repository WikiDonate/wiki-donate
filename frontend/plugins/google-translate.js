export default defineNuxtPlugin(() => {
    if (import.meta.client) {
        const script = document.createElement('script')
        script.src =
            '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit'
        document.head.appendChild(script)

        window.googleTranslateElementInit = function () {
            new window.google.translate.TranslateElement(
                {
                    // pageLanguage: 'en',
                    // includedLanguages: 'es,fr,de,it', // Specify the languages you want to include
                    layout: google.translate.TranslateElement.InlineLayout
                        .SIMPLE,
                },
                'google_translate_element'
            )
        }
    }
})
