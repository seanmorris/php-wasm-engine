import {defineConfig} from '@playwright/test';
import fs from 'node:fs';
export default defineConfig({
	testDir: 'tests/browser'
	, timeout: 90000
	, workers: 1
	, use: {baseURL: 'http://127.0.0.1:9130',headless: true}
	, projects: [{
		name: 'chromium'
		, use: {
			browserName: 'chromium'
			, launchOptions: {
				...(fs.existsSync('/usr/bin/chromium') ? {executablePath: '/usr/bin/chromium'} : {})
				, args: ['--no-sandbox'
					, '--use-gl=angle'
					, '--use-angle=swiftshader'
					, '--enable-unsafe-swiftshader'
				]
			}
		}
	}]
	, webServer: {
		command: 'npm start'
		, url: 'http://127.0.0.1:9130'
		, reuseExistingServer: !process.env.CI
		, timeout: 120000
	}
});
