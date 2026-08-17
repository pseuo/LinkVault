const {defineConfig, devices} = require('@playwright/test');
const e2ePort = Number(process.env.LINKVAULT_E2E_PORT || 18080);
const e2eBaseUrl = `http://127.0.0.1:${e2ePort}`;

module.exports = defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: e2eBaseUrl,
        colorScheme: 'light',
        reducedMotion: 'reduce',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'node tests/e2e/server.mjs',
        url: `${e2eBaseUrl}/readyz`,
        reuseExistingServer: false,
        timeout: 30_000,
    },
    projects: [
        {name: 'desktop-chromium', use: {...devices['Desktop Chrome']}},
        {name: 'mobile-chromium', use: {...devices['Pixel 7']}},
    ],
});
