import { defineConfig } from '@playwright/test';
import base from './playwright.config';
export default defineConfig({ ...base, testDir: './tests/e2e-agent', use: { ...base.use, actionTimeout: 20_000, navigationTimeout: 30_000 }, outputDir: './test-results/agent-v2-traces', timeout: 240_000 });
