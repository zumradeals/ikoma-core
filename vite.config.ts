import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  plugins: [
    laravel({ input: ['resources/js/app.ts'], refresh: true }), vue(), tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate', base: '/build/', scope: '/',
      manifest: { name: 'IKOMA Accueil', short_name: 'IKOMA', lang: 'fr', start_url: '/', scope: '/', display: 'standalone', theme_color: '#0F5AA6', background_color: '#f8fafc', icons: [{ src: '/icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' }] },
      workbox: {
        globPatterns: ['**/*.{js,css,woff2}'], navigateFallback: undefined,
        // L0 uniquement : seule la page publique provisoire est mise en cache.
        additionalManifestEntries: [{ url: '/', revision: 'l0' }, { url: '/icon.svg', revision: 'l0' }],
        navigateFallbackDenylist: [/^\/build/],
      },
    }),
  ],
});
