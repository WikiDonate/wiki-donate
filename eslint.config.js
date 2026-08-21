import pluginVue from 'eslint-plugin-vue'
import prettierConfig from 'eslint-config-prettier'

export default [
    {
        ignores: ['public/build/**', 'vendor/**', 'node_modules/**'],
    },
    ...pluginVue.configs['flat/recommended'],
    prettierConfig,
    {
        files: ['resources/js/**/*.{js,vue}'],
        rules: {
            'vue/multi-word-component-names': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/html-self-closing': 'off',
        },
    },
]