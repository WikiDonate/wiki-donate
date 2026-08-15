/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/js/components/**/*.{js,vue,ts}',
        './resources/js/layouts/**/*.vue',
        './resources/js/pages/**/*.vue',
        './resources/js/App.vue',
        './resources/views/**/*.blade.php',
    ],
    theme: {
        extend: {
            screens: {
                xs: '476px',
            },
        },
    },
    plugins: [],
}
