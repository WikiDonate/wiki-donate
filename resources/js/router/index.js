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
                component: () => import('@/pages/index.vue'),
            },
            {
                path: 'login',
                name: 'login',
                component: () => import('@/pages/login.vue'),
            },
            {
                path: 'create-account',
                name: 'create-account',
                component: () => import('@/pages/create-account.vue'),
            },
            {
                path: 'forgot-password',
                name: 'forgot-password',
                component: () => import('@/pages/forgot-password.vue'),
            },
            {
                path: 'verify-email',
                name: 'verify-email',
                component: () => import('@/pages/verify-email.vue'),
            },
            {
                path: 'contact',
                name: 'contact',
                component: () => import('@/pages/contact.vue'),
            },
            {
                path: 'how-it-works',
                name: 'how-it-works',
                component: () => import('@/pages/how-it-works.vue'),
            },
            {
                path: 'main',
                name: 'main',
                component: () => import('@/pages/main.vue'),
            },
            {
                path: 'profile',
                name: 'profile',
                component: () => import('@/pages/profile.vue'),
                meta: { requiresAuth: true },
            },
            {
                path: 'preferences',
                name: 'preferences',
                component: () => import('@/pages/preferences.vue'),
                meta: { requiresAuth: true },
            },
            {
                path: 'billing',
                name: 'billing',
                component: () => import('@/pages/billing.vue'),
                meta: { requiresAuth: true },
            },
            {
                path: 'report/donations',
                name: 'donation-report',
                component: () => import('@/pages/report.vue'),
                meta: { requiresAuth: true },
            },
            {
                path: 'article/my',
                name: 'article-my',
                component: () => import('@/pages/article/my.vue'),
                meta: { requiresAuth: true },
            },
            {
                path: 'article/new',
                name: 'article-new',
                component: () => import('@/pages/article/new.vue'),
            },
            {
                path: 'article',
                name: 'article',
                component: () => import('@/pages/article/index.vue'),
            },
            {
                path: 'payment/success',
                name: 'payment-success',
                component: () => import('@/pages/payment/success.vue'),
            },
            {
                path: 'payment/cancel',
                name: 'payment-cancel',
                component: () => import('@/pages/payment/cancel.vue'),
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
            },
            {
                path: 'donations',
                name: 'admin-donations',
                component: () => import('@/pages/admin/donations.vue'),
            },
            {
                path: 'articles',
                name: 'admin-articles',
                component: () => import('@/pages/admin/articles.vue'),
            },
            {
                path: 'how-it-works',
                name: 'admin-how-it-works',
                component: () => import('@/pages/admin/how-it-works.vue'),
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
