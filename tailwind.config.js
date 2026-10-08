/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './*.php',
        './{customers,campaigns,inbox,activity,templates,mail-accounts,tags,tasks,calls,leads,settings,unsubscribe,includes}/**/*.php',
        './assets/js/**/*.js',
    ],
    theme: {
        extend: {
            fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
        },
    },
    plugins: [require('@tailwindcss/forms')],
};
