import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import path from 'path'
import { fileURLToPath } from 'url'
import fs from 'fs'

const root = path.dirname(fileURLToPath(import.meta.url))

const hotFile = path.join(root, 'public/hot')

const laravelHotFilePlugin = {
    name: 'laravel-hot-file',
    configureServer(server) {
        fs.writeFileSync(hotFile, 'http://localhost:5173/build')
        server.httpServer?.once('close', () => {
            if (fs.existsSync(hotFile)) fs.unlinkSync(hotFile)
        })
    },
}

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
                manualChunks(id) {
                    if (!id.includes('node_modules')) return
                    if (/[\\/](vue|vue-router|pinia)[\\/]/.test(id)) {
                        return 'vue'
                    }
                    if (id.includes('@fortawesome')) return 'fontawesome'
                    if (
                        id.includes('quill') ||
                        id.includes('diff-match-patch') ||
                        id.includes('interactjs')
                    ) {
                        return 'quill'
                    }
                    if (id.includes('@stripe')) return 'stripe'
                    if (
                        id.includes('axios') ||
                        id.includes('vee-validate') ||
                        id.includes('yup') ||
                        id.includes('vue-toast-notification') ||
                        id.includes('vue3-recaptcha-v2')
                    ) {
                        return 'vendor'
                    }
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
        laravelHotFilePlugin,
        vue(),
        tailwindcss(),
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
