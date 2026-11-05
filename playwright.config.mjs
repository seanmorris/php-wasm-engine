import {defineConfig} from '@playwright/test';
import fs from 'node:fs';
import {fileURLToPath} from 'node:url';

const port = Number(process.env.PORT || 9130);
const baseURL = `http://127.0.0.1:${port}`;

export default defineConfig({
	testDir: '.'
	, testMatch: ['tests/browser/**/*.spec.mjs', 'packages/*/tests/browser/**/*.spec.mjs']
	, testIgnore: ['site/.cache/**', '.worktrees/**'].map(path => fileURLToPath(new URL(path, import.meta.url)))
	, timeout: 90000
	, workers: 1
	, reporter: process.env.CI ? [['line'], ['github']] : 'list'
	, use: {baseURL,headless: true}
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
		, url: baseURL
		, reuseExistingServer: !process.env.CI
		, timeout: 600000
	}
});
