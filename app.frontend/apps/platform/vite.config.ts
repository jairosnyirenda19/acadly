import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// In development the API is served by Laravel Herd at https://api.acadly-iq-x.test.
// The dev server passes /api to it, so the browser sees one origin.
// Herd signs its own certificate, so the proxy does not check it (development only).
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    proxy: {
      '/api': { target: process.env.ACADLY_API ?? 'https://api.acadly-iq-x.test', changeOrigin: true, secure: false },
    },
  },
})
