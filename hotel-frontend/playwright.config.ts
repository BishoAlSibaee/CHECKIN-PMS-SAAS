import { defineConfig, devices } from '@playwright/test';

const baseURL = 'http://127.0.0.1:4300';
const isCi = !!process.env['CI'];

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: isCi,
  retries: isCi ? 1 : 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'setup',
      testMatch: /.*\.setup\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        channel: 'chrome',
      },
    },
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // Prefer system Chrome when Playwright browser binaries are not installed.
        channel: 'chrome',
        storageState: 'e2e/.auth/user.json',
      },
      dependencies: ['setup'],
      testIgnore: /.*\.setup\.ts/,
    },
  ],
  webServer: isCi
    ? [
        {
          command: 'cd ../checking-Booking && php artisan serve --host=127.0.0.1 --port=8001',
          url: 'http://127.0.0.1:8001/api/users/setup-check',
          reuseExistingServer: false,
          timeout: 120_000,
        },
        {
          command: 'cd ../checking-Booking && php artisan reverb:start --host=127.0.0.1 --port=8080',
          url: 'http://127.0.0.1:8080',
          reuseExistingServer: false,
          timeout: 120_000,
        },
        {
          command: 'npm run start',
          url: baseURL,
          reuseExistingServer: false,
          timeout: 120_000,
        },
      ]
    : {
        command: 'npm run start',
        url: baseURL,
        reuseExistingServer: true,
        timeout: 120_000,
      },
});
