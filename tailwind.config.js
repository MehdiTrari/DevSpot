/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./assets/**/*.js",
    "./templates/**/*.html.twig",
  ],
  theme: {
    extend: {
      animation: {
        // Le mot magique ici est "both" au lieu de "forwards"
        'fade-in': 'fadeIn 0.8s cubic-bezier(0.16, 1, 0.3, 1) both',
        'slide-up': 'slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) both',
        'slide-up-delay-1': 'slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.15s both',
        'slide-up-delay-2': 'slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.3s both',
      },
      keyframes: {
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        slideUp: {
          '0%': { opacity: '0', transform: 'translateY(24px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
      },
    },
  },
  plugins: [],
}