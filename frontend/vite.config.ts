/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// SPA e API na mesma origem (ADR-003): o Vite faz proxy de /api para o Laravel local.
const api = process.env.SIGARB_API_URL ?? 'http://127.0.0.1:18480'

export default defineConfig({
  plugins: [react()],
  server: { port: Number(process.env.PORT ?? 18481), strictPort: true, host: '127.0.0.1', proxy: { '/api': { target: api, changeOrigin: false } } },
  preview: { port: 18481, strictPort: true, host: '127.0.0.1', proxy: { '/api': { target: api, changeOrigin: false } } },
  test: { environment: 'jsdom', setupFiles: ['./src/test/setup.ts'], include: ['src/**/*.test.{ts,tsx}'], css: false },
})
