import { defineConfig } from '@playwright/test';
export default defineConfig({ testDir: './tests/e2e', use: { baseURL: process.env.BASE_URL || 'http://localhost:8080', serviceWorkers: 'allow' }, projects: [{ name: 'chromium', use: { browserName: 'chromium' } }], reporter: 'list' });
