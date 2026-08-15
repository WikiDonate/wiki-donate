import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import path from 'path'
import { fileURLToPath } from 'url'

const root = path.dirname(fileURLToPath(import.meta.url))

export default defineConfig({
    root,
    base: '/build/',
    publicDir: false,
    build: {
        outDir: path.join(root, 'public/build'),
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: path.join(root, 'resources/js/app.js'),
            output: {
                manualChunks: {
                    vue: ['vue', 'vue-router', 'pinia'],
                    fontawesome: [
                        '@fortawesome/fontawesome-svg-core',
                        '@fortawesome/vue-fontawesome',
                        '@fortawesome/free-solid-svg-icons',
                        '@fortawesome/free-regular-svg-icons',
                        '@fortawesome/free-brands-svg-icons',
                    ],
                    quill: [
                        '@vueup/vue-quill',
                        'quill',
                        'diff-match-patch',
                        'interactjs',
                    ],
                    stripe: ['@stripe/stripe-js'],
                    vendor: [
                        'axios',
                        'vee-validate',
                        'yup',
                        'vue-toast-notification',
                        '@vee-validate/rules',
                        'vue3-recaptcha-v2',
                    ],
                },
            },
        },
    },
    resolve: {
        alias: {
            '~': path.join(root, 'resources/js'),
            '@': path.join(root, 'resources/js'),
        },
    },
    plugins: [
        vue(),
        AutoImport({
            imports: [
                'vue',
                'vue-router',
                { from: '@vueuse/head', imports: ['useHead'] },
                {
                    from: path.join(root, 'resources/js/shims.js'),
                    imports: ['definePageMeta', 'navigateTo'],
                },
                {
                    from: path.join(root, 'resources/js/plugins/googleTranslate.js'),
                    imports: ['useGoogleTranslate'],
                },
            ],
            dirs: [
                path.join(root, 'resources/js/stores/**'),
                path.join(root, 'resources/js/composables/**'),
            ],
            dts: false,
            eslintrc: false,
        }),
        Components({
            dirs: [path.join(root, 'resources/js/components')],
            extensions: ['vue'],
            dts: false,
        }),
    ],
    server: {
        proxy: {
            '/api': 'http://localhost:8000',
        },
    },
})
