import { createRouter, createWebHistory } from 'vue-router'
import DefaultLayout from '@/layouts/default.vue'
import AdminLayout from '@/layouts/admin.vue'
import { useAuthStore } from '@/stores/authStore'

const routes = [
    {
        path: '/',
        component: DefaultLayout,
        children: [
            {
                path: '',
                name: 'home',
                component: () => import('@/pages/public/index.vue'),
                meta: { titleKey: 'pageTitles.home' },
            },
            {
                path: 'login',
                name: 'login',
                component: () => import('@/pages/auth/login.vue'),
                meta: { titleKey: 'pageTitles.login' },
            },
            {
                path: 'create-account',
                name: 'create-account',
                component: () => import('@/pages/auth/create-account.vue'),
                meta: { titleKey: 'pageTitles.createAccount' },
            },
            {
                path: 'forgot-password',
                name: 'forgot-password',
                component: () => import('@/pages/auth/forgot-password.vue'),
                meta: { titleKey: 'pageTitles.forgotPassword' },
            },
            {
                path: 'verify-email',
                name: 'verify-email',
                component: () => import('@/pages/auth/verify-email.vue'),
                meta: { titleKey: 'pageTitles.verifyEmail' },
            },
            {
                path: 'contact',
                name: 'contact',
                component: () => import('@/pages/public/contact.vue'),
                meta: { titleKey: 'pageTitles.contact' },
            },
            {
                path: 'how-it-works',
                name: 'how-it-works',
                component: () => import('@/pages/public/how-it-works.vue'),
                meta: { titleKey: 'pageTitles.howItWorks' },
            },
            {
                path: 'main',
                name: 'main',
                component: () => import('@/pages/public/main.vue'),
                meta: { titleKey: 'pageTitles.main' },
            },
            {
                path: 'profile',
                name: 'profile',
                component: () => import('@/pages/account/profile.vue'),
                meta: { requiresAuth: true, titleKey: 'pageTitles.profile' },
            },
            {
                path: 'preferences',
                name: 'preferences',
                component: () => import('@/pages/account/preferences.vue'),
                meta: { requiresAuth: true, titleKey: 'pageTitles.preferences' },
            },
            {
                path: 'billing',
                name: 'billing',
                component: () => import('@/pages/account/billing.vue'),
                meta: { requiresAuth: true, titleKey: 'pageTitles.billing' },
            },
            {
                path: 'my-donates',
                name: 'my-donations',
                component: () => import('@/pages/account/donations.vue'),
                meta: { requiresAuth: true, titleKey: 'pageTitles.myDonations' },
            },
            {
                path: 'article/my',
                name: 'article-my',
                component: () => import('@/pages/articles/my.vue'),
                meta: { requiresAuth: true, titleKey: 'pageTitles.myArticles' },
            },
            {
                path: 'article/new',
                name: 'article-new',
                component: () => import('@/pages/articles/new.vue'),
                meta: { titleKey: 'pageTitles.newArticle' },
            },
            {
                path: 'article',
                name: 'article',
                component: () => import('@/pages/articles/index.vue'),
                meta: { titleKey: 'pageTitles.article', dynamicTitle: true },
            },
            {
                path: 'payment/success',
                name: 'payment-success',
                component: () => import('@/pages/payment/success.vue'),
                meta: { titleKey: 'pageTitles.paymentSuccess', dynamicTitle: true },
            },
            {
                path: 'payment/cancel',
                name: 'payment-cancel',
                component: () => import('@/pages/payment/cancel.vue'),
                meta: { titleKey: 'pageTitles.paymentCancel' },
            },
        ],
    },
    {
        path: '/admin',
        component: AdminLayout,
        meta: { requiresAuth: true, requiresAdmin: true },
        children: [
            { path: '', redirect: '/admin/index' },
            {
                path: 'index',
                name: 'admin-index',
                component: () => import('@/pages/admin/index.vue'),
                meta: { titleKey: 'pageTitles.adminDashboard' },
            },
            {
                path: 'transactions',
                name: 'admin-transactions',
                component: () => import('@/pages/admin/transactions.vue'),
                meta: { titleKey: 'pageTitles.adminTransactions' },
            },
            {
                path: 'donations',
                name: 'admin-donations',
                component: () => import('@/pages/admin/donations.vue'),
                meta: { titleKey: 'pageTitles.adminDonations' },
            },
            {
                path: 'articles',
                name: 'admin-articles',
                component: () => import('@/pages/admin/articles.vue'),
                meta: { titleKey: 'pageTitles.adminArticles' },
            },
            {
                path: 'how-it-works',
                name: 'admin-how-it-works',
                component: () => import('@/pages/admin/how-it-works.vue'),
                meta: { titleKey: 'pageTitles.adminHowItWorks' },
            },
            {
                path: 'payouts',
                name: 'admin-payouts',
                component: () => import('@/pages/admin/payouts.vue'),
                meta: { titleKey: 'pageTitles.adminPayouts' },
            },
            {
                path: 'organizations',
                name: 'admin-organizations',
                component: () => import('@/pages/admin/organizations.vue'),
                meta: { titleKey: 'pageTitles.adminOrganizations' },
            },
            {
                path: 'transaction-logs',
                name: 'admin-transaction-logs',
                component: () => import('@/pages/admin/transaction-logs.vue'),
                meta: { titleKey: 'pageTitles.adminTransactionLogs' },
            },
        ],
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/',
    },
]

const router = createRouter({
    history: createWebHistory(),
    routes,
})

router.beforeEach((to) => {
    const authStore = useAuthStore()

    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        return { name: 'login' }
    }

    if (to.meta.requiresAdmin) {
        if (!authStore.roles?.includes('Admin')) {
            return { name: 'main' }
        }
    }
})

export default router
