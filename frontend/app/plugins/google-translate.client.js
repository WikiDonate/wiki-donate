// Google Translate integration plugin
// Loads the Google Translate Element script and provides a shared translate store
import { defineNuxtPlugin } from '#app'

export default defineNuxtPlugin(() => {
    // Only run in browser
    if (typeof window === 'undefined') return

    // Avoid duplicate loading
    if (document.getElementById('google-translate-script')) return

    const script = document.createElement('script')
    script.id = 'google-translate-script'
    script.src = '//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit'
    script.async = true

    // Google Translate Element initialization callback
    window.googleTranslateElementInit = () => {
        if (window.google?.translate?.TranslateElement) {
            new window.google.translate.TranslateElement(
                {
                    pageLanguage: 'en',
                    includedLanguages: 'en,es,fr,de,ar,zh-CN,ja,pt,ru,hi,cy,af,sq,he,gu,hy,az,eu,be,bg,ca,zh-TW,hr,cs,da,nl,et,tl,fi,el,hu,id,ga,it,ko,lt,ms,vi,no,pl,pt-PT,ro,sv,th,tr,uk',
                    layout: window.google.translate.TranslateElement.InlineLayout.HORIZONTAL,
                    autoDisplay: false,
                },
                'google_translate_element'
            )
        }
    }

    // Create a hidden container for Google Translate Element
    const translateContainer = document.createElement('div')
    translateContainer.id = 'google_translate_element'
    translateContainer.style.display = 'none'
    document.body.appendChild(translateContainer)

    document.head.appendChild(script)
})
