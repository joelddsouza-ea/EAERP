/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.vue',
    './resources/**/*.ts',
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          50: '#fef2f2',
          100: '#fde3e4',
          200: '#fbcbcd',
          300: '#f6a3a6',
          400: '#ee6b70',
          500: '#e03a40',
          600: '#c4161c',
          700: '#a51217',
          800: '#881317',
          900: '#721619',
          950: '#3f0709',
        },
        status: {
          draft: '#6b7280',
          received: '#3b82f6',
          'in-progress': '#f59e0b',
          qc: '#8b5cf6',
          ready: '#10b981',
          delivered: '#059669',
          cancelled: '#ef4444',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
