import { createHash } from 'node:crypto';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

function sourceHash(): string {
  const hash = createHash('sha256');
  function walk(path: string) {
    for (const entry of readdirSync(path, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const file = join(path, entry.name);
      if (entry.isDirectory()) walk(file); else { hash.update(file); hash.update(readFileSync(file)); }
    }
  }
  for (const dir of ['resources', 'routes', 'app']) walk(dir);
  hash.update(readFileSync('package-lock.json'));
  return hash.digest('hex');
}
export default defineConfig({
  plugins: [
    laravel({ input: ['resources/js/app.ts'], refresh: true }), vue(), tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate', base: '/build/', scope: '/',
      manifest: { name: 'IKOMA Accueil', short_name: 'IKOMA', lang: 'fr', start_url: '/', scope: '/', display: 'standalone', theme_color: '#0F5AA6', background_color: '#f8fafc', icons: [{ src: '/icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' }] },
      workbox: {
        globPatterns: ['**/*.{js,css,woff2}'], navigateFallback: undefined,
        // Page publique provisoire et coquille publique du poste (/poste/shell : aucune session, aucun nom, aucune donnée).
        additionalManifestEntries: [{ url: '/', revision: sourceHash() }, { url: '/icon.svg', revision: sourceHash() }, { url: '/poste/shell', revision: sourceHash() }, { url: '/controle/shell', revision: sourceHash() }, { url: '/pilotage/evacuation/shell', revision: sourceHash() }],
        navigateFallbackDenylist: [/^\/build/],
        // /poste, /controle et /pilotage/evacuation passent toujours par le serveur (session, droits) ; sans réseau, on rend la coquille publique précachée. Jamais de contenu authentifié en cache.
        runtimeCaching: [{
          urlPattern: ({ request, url }: { request: Request; url: URL }) => request.mode === 'navigate' && ['/poste', '/controle', '/pilotage/evacuation'].includes(url.pathname),
          handler: 'NetworkOnly',
          options: { plugins: [{ handlerDidError: async ({ request }: { request: Request }) => caches.match(new URL(request.url).pathname + '/shell', { ignoreSearch: true }) }] },
        }],
      },
    }),
  ],
});
