import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import path from 'path'
import { fileURLToPath } from 'url'

const root = path.dirname(fileURLToPath(import.meta.url))

export default defineConfig({
    resolve: {
        alias: {
            '~': path.join(root, 'resources/js'),
            '@': path.join(root, 'resources/js'),
        },
    },
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
        AutoImport({
            imports: [
                'vue',
                'vue-router',
                'vue-i18n',
                { from: '@vueuse/head', imports: ['useHead'] },
                {
                    from: path.join(root, 'resources/js/shims.js'),
                    imports: ['definePageMeta', 'navigateTo'],
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
            // Scan nested folders (ui/, layout/, forms/, article/, …).
            deep: true,
            dts: false,
        }),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) return
                    if (/[\\/](vue|vue-router|pinia)[\\/]/.test(id)) {
                        return 'vue'
                    }
                    if (id.includes('@fortawesome')) return 'fontawesome'
                    if (id.includes('@stripe')) return 'stripe'
                    if (
                        id.includes('axios') ||
                        id.includes('vee-validate') ||
                        id.includes('yup') ||
                        id.includes('vue-toast-notification')
                    ) {
                        return 'vendor'
                    }
                },
            },
        },
    },
    server: {
        proxy: {
            '/api': 'http://localhost:8000',
        },
    },
})
