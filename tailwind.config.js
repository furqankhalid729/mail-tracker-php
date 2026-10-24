/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './*.php',
        './{customers,campaigns,ai-campaigns,inbox,activity,templates,mail-accounts,tags,tasks,calls,leads,settings,unsubscribe,includes,employees,leaves,wiki,reports,inspector,payroll,expenses}/**/*.php',
        './assets/js/**/*.js',
    ],
    theme: {
        extend: {
            fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
        },
    },
    plugins: [require('@tailwindcss/forms')],
};
