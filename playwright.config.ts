import { defineConfig, devices } from '@playwright/test';

const port = process.env.ENGAGEMENT_BROWSER_PORT ?? '8098';

export default defineConfig({
  testDir: './tests/browser',
  globalSetup: './tests/browser/global-setup.ts',
  // One shared Craft server + database, so specs run serially.
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  retries: 0,
  reporter: 'list',
  outputDir: 'tests/browser/artifacts',
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ...devices['Desktop Chrome'],
  },
});
