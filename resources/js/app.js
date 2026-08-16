import { createApp } from 'vue'
import { createPinia } from 'pinia'
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate'
import { createHead } from '@vueuse/head'

import { library } from '@fortawesome/fontawesome-svg-core'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'

import {
    faAngleLeft,
    faAngleRight,
    faArrowLeft,
    faBars,
    faBell,
    faBookOpen,
    faCalculator,
    faChartLine,
    faChartPie,
    faCheckCircle,
    faChevronDown,
    faCoins,
    faCog,
    faCreditCard,
    faDonate,
    faEdit,
    faEllipsisH,
    faExclamationTriangle,
    faEye,
    faEyeSlash,
    faFile,
    faFileAlt,
    faFlag,
    faGlobe,
    faHandHoldingHeart,
    faHandsPraying,
    faHandshake,
    faHeart,
    faHome,
    faInfoCircle,
    faLanguage,
    faLightbulb,
    faLink,
    faLock,
    faMagnifyingGlass,
    faNewspaper,
    faPen,
    faPlus,
    faPlusCircle,
    faRocket,
    faSearch,
    faShieldAlt,
    faSignInAlt,
    faSignOutAlt,
    faStar,
    faTimes,
    faTimesCircle,
    faTrash,
    faTrashAlt,
    faUser,
    faUserCog,
    faUserPlus,
    faUsers,
} from '@fortawesome/free-solid-svg-icons'

import { faGithub, faPaypal } from '@fortawesome/free-brands-svg-icons'

import App from './App.vue'
import router from './router'
import { initGoogleTranslate } from './plugins/googleTranslate'

import './assets/css/main.css'
import 'vue-toast-notification/dist/theme-default.css'

library.add(
    faAngleLeft,
    faAngleRight,
    faArrowLeft,
    faBars,
    faBell,
    faBookOpen,
    faCalculator,
    faChartLine,
    faChartPie,
    faCheckCircle,
    faChevronDown,
    faCoins,
    faCog,
    faCreditCard,
    faDonate,
    faEdit,
    faEllipsisH,
    faExclamationTriangle,
    faEye,
    faEyeSlash,
    faFile,
    faFileAlt,
    faFlag,
    faGithub,
    faGlobe,
    faHandHoldingHeart,
    faHandsPraying,
    faHandshake,
    faHeart,
    faHome,
    faInfoCircle,
    faLanguage,
    faLightbulb,
    faLink,
    faLock,
    faMagnifyingGlass,
    faNewspaper,
    faPaypal,
    faPen,
    faPlus,
    faPlusCircle,
    faRocket,
    faSearch,
    faShieldAlt,
    faSignInAlt,
    faSignOutAlt,
    faStar,
    faTimes,
    faTimesCircle,
    faTrash,
    faTrashAlt,
    faUser,
    faUserCog,
    faUserPlus,
    faUsers
)

const app = createApp(App)

// eslint-disable-next-line vue/component-definition-name-casing
app.component('font-awesome-icon', FontAwesomeIcon)

const pinia = createPinia()
pinia.use(piniaPluginPersistedstate)

app.use(pinia)
app.use(router)
app.use(createHead())

app.mount('#app')

initGoogleTranslate()
