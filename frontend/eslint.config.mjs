import withNuxt from './.nuxt/eslint.config.mjs'

export default withNuxt({
    rules: {
        'vue/multi-word-component-names': 'off', // Disable the rule
    },
})
