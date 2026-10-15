import './site.css';
import {AssetLoader} from '../browser/AssetLoader.mjs';
import {DemoControls} from './controls.mjs';

const status = document.querySelector('#status');
const canvas = document.querySelector('canvas');
const start = document.querySelector('#start');
const preloader = document.querySelector('#preloader');
const progress = document.querySelector('#preload-progress');
const preloadLabel = document.querySelector('#preload-label');
const base = new URL('./engine/', document.baseURI);
const runtimeBase = new URL('./runtime/', document.baseURI);
const controls = new DemoControls();
window.demoControls = controls;
let php, runtimeKey, pending;
let queue = Promise.resolve();
let state = 'idle';

canvas.addEventListener('webglcontextlost', () => {
	if(state === 'running')
	{
		status.textContent = 'Graphics paused. Waiting for the browser to restore WebGL…';
	}
});

canvas.addEventListener('webglcontextrestored', () => {
	if(state === 'running') status.textContent = 'Restoring graphics…';
});

/**
* Load one demo, reusing its WASM runtime when the PHP version/extensions match.
* @param {object} options Scene, movement profile, PHP version, and fixture options.
* @returns {Promise<object>} Ready PHP instance, or a rejection on failure/cancellation.
*/
export function boot(
	{
		scene = controls.scene
		, profile = controls.profile
		, run = true
		, zlib = false
		, version = '8.4'
		, intro = false
	} = {}
){
	if(!/^8\.[0-5]$/.test(version)
		|| !['world', 'platformer', 'badniks', 'grinding', 'loop', 'breakables', 'infinite', 'isometric'].includes(
			scene
		)
		|| !['runner', 'glider', 'flyer'].includes(profile)
	){
		return Promise.reject(Error('Unsupported PHP version, scene, or movement profile'));
	}

	pending?.abort();
	const request = pending = new AbortController();
	const {signal} = request;
	controls.scene = scene;
	controls.profile = profile;
	setState('loading');
	progress.removeAttribute('value');
	preloadLabel.textContent = 'Downloading and initializing PHP SDL…';
	status.textContent = 'Loading PHP SDL…';

	const task = queue.then(async () => {
		signal.throwIfAborted();

		if(php) await php.refresh();

		signal.throwIfAborted();

		for(const key of ['frames'
			, 'body'
			, 'menu'
			, 'world'
			, 'breakables'
			, 'badniks'
			, 'scene'
			, 'profile'
			, 'loading', 'levelError', 'intro', 'hud', 'combo', 'debug', 'sequences'
		]){
			delete canvas.dataset[key];
		}

		const key = version + ':' + Boolean(zlib);

		if(!php || runtimeKey !== key)
		{
			const {PhpSdl} = await import(/* webpackIgnore: true */
				new URL('php' + version + '-sdl.mjs', runtimeBase)
			);

			const sharedLibs = zlib
				? [await import(/* webpackIgnore: true */
					new URL('extensions/zlib/' + version + '.mjs', runtimeBase)
				)]
				: [];

			signal.throwIfAborted();

			php = new PhpSdl({
				canvas
				, sharedLibs
				, persist: false
				, ini: 'display_errors=0\nlog_errors=1\nerror_log=/dev/stderr'
			});

			window.enginePhp = php;
			const instance = php;

			php.addEventListener('output', event => {
				if(php === instance) status.textContent = event.detail.join('');
			});

			php.addEventListener('error', event => {
				if(php === instance) status.textContent = event.detail.join('');

				console.error(event.detail.join(''));
			});

			controls.attach(await php.binary);
			runtimeKey = key;
		}

		const module = await php.binary;
		signal.throwIfAborted();
		Object.assign(module, {scene, profile, demoControls: controls});
		const response = await fetch(new URL('engine-files.json', base), {signal});

		if(!response.ok) throw Error('Engine manifest: ' + response.status);

		const files = await response.json();

		const needed = files.filter(
			path => !run || !path.startsWith(
				'examples/assets/'
			) || /^examples\/assets\/(font|buttons)\.(tsj|png)$/.test(path)
		);

		let mounted = 0;
		progress.max = needed.length;
		progress.value = 0;
		preloadLabel.textContent = `Loading engine and font files: 0 / ${needed.length}`;

		await Promise.all(needed.map(async path => {
			const response = await fetch(new URL(path, base), {cache: 'no-store', signal});

			if(!response.ok) throw Error('Asset ' + path + ': ' + response.status);

			const bytes = new Uint8Array(await response.arrayBuffer());
			signal.throwIfAborted();
			const full = '/engine/' + path;
			module.FS.mkdirTree(full.slice(0, full.lastIndexOf('/')));
			module.FS.writeFile(full, bytes);
			progress.value = ++mounted;
			preloadLabel.textContent = `Loading engine and font files: ${mounted} / ${needed.length}`;
		}));

		if(run)
		{
			const assets = new AssetLoader(module.FS, base);

			module.createAssetScope = () => assets.scope();

			const close = () => {
				assets.close();
				module.onRefresh.delete(close);
				signal.removeEventListener('abort', close);
			};

			module.onRefresh.add(close);
			signal.addEventListener('abort', close, {once: true});
		}

		signal.throwIfAborted();

		if(run)
		{
			let ready, fail;
			const loaded = new Promise((resolve, reject) => { ready = resolve; fail = reject; });

			// Stop must settle this wait before its queued PHP cleanup can run.

			const aborted = () => fail(signal.reason);

			module.sceneReady = error => error ? fail(Error(error)) : ready();
			signal.addEventListener('abort', aborted, {once: true});
			loaded.catch(() => {});

			try
			{
				const entry = intro ? 'intro' : 'game';
				const code = await php.run(`<?php require "/engine/examples/${entry}.php";`);

				if(code !== 0) throw Error('PHP returned ' + code);

				await loaded;
			}
			finally
			{
				module.sceneReady = null;
				signal.removeEventListener('abort', aborted);
			}
		}

		signal.throwIfAborted();

		if(!run) canvas.dataset.scene = scene;

		canvas.dataset.profile = profile;
		setState(run ? 'running' : 'idle');

		if(run) canvas.focus({preventScroll: true});

		return php;
	}).catch(async error => {
		const cancelled = signal.aborted;
		request.abort();

		try
		{
			if(php) await php.refresh();
		}
		catch
		{
			// A failed native initialization cannot be refreshed. Let Run create a new instance.
			php = window.enginePhp = null;
			runtimeKey = null;
		}

		if(pending === request)
		{
			setState('idle');

			if(!cancelled) status.textContent = error.message;
		}

		throw cancelled ? signal.reason : error;
	});

	queue = task.catch(() => {});

	return task;
}

/**
* Report a failed start while treating a requested cancellation as normal control flow.
* @param {Error} error Failed runtime or asset request.
* @returns {void} Logs actionable failures for the browser's developer console.
*/
function report(error)
{
	if(error.name !== 'AbortError') console.error(error);
}

/**
* Stop active PHP work or cancel a download, then show the centered Run button.
* @returns {Promise<void>} Settles after frame callbacks and streaming requests are released.
*/
export function stopEngine()
{
	pending?.abort();
	pending = null;
	setState('stopping');

	const task = queue.then(async () => {
		try
		{
			await php?.refresh();

			if(!pending) status.textContent = 'Stopped. Run to restart.';
		}
		finally
		{
			// A newer scripted boot owns its own loading UI.
			if(!pending) setState('idle');
		}
	});

	queue = task.catch(() => {});

	return task;
}

/**
* Keep the centered startup control visible only while no demo is running.
* @param {string} next Idle, loading, stopping, or running state of the demo host.
* @returns {void} Updates the button and a script-readable canvas state.
*/
function setState(next)
{
	state = next;
	canvas.dataset.state = state;
	start.hidden = state === 'running';
	preloader.hidden = state !== 'loading';
	start.disabled = state === 'loading' || state === 'stopping';
	start.textContent = state === 'loading' ? 'Loading PHP…' : state === 'stopping' ? 'Stopping…' : 'Run PHP';
}

window.bootEngine = boot;
window.stopEngine = stopEngine;
start.addEventListener('click', () => boot({intro: true}).catch(report));
