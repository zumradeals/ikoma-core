import { defineConfig } from '@playwright/test';
import base from './playwright.config';
export default defineConfig({ ...base, testDir: './tests/e2e-agent', outputDir: './test-results/agent-v2-traces', timeout: 240_000 });
