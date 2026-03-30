import { library } from '@fortawesome/fontawesome-svg-core'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'

// Import all free icons
import { fab } from '@fortawesome/free-brands-svg-icons' // Brands icons
import { far } from '@fortawesome/free-regular-svg-icons' // Regular icons
import { fas } from '@fortawesome/free-solid-svg-icons' // Solid icons

// Add all icons to the library
library.add(fas, far, fab)

export default defineNuxtPlugin((nuxtApp) => {
    nuxtApp.vueApp.component('font-awesome-icon', FontAwesomeIcon)
})
