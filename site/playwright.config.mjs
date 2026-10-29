import {existsSync} from 'node:fs';
import {defineConfig} from '@playwright/test';

export default defineConfig({
	testDir: './tests'
	, testMatch: 'browser.spec.mjs'
	, timeout: 60000
	// A retry keeps a renderer crash from failing the run while its trace is kept as evidence.
	, retries: process.env.CI ? 1 : 0

	, use: {
		baseURL: 'http://127.0.0.1:9141'
		, launchOptions: {
			executablePath: existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined
			, args: ['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader']
		}
		, screenshot: 'only-on-failure'
		, trace: process.env.CI ? 'retain-on-failure' : 'off'
	}

	, webServer: {
		command: 'node bin/phonics.mjs serve docs/dist --port 9141'
		, cwd: '..'
		, url: 'http://127.0.0.1:9141/'
		, reuseExistingServer: !process.env.CI
	}
});
