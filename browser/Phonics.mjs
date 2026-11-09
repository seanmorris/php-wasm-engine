import {ResourceStore} from './ResourceStore.mjs';
import {PackageGraph} from './PackageGraph.mjs';
import {ArchiveResources} from './ArchiveResources.mjs';
import {LoadProgress} from './LoadProgress.mjs';
import {GameShell} from './GameShell.mjs';
import {optimizeCanvasContexts} from './GlesPrograms.mjs';
import {QuickControls} from './QuickControls.mjs';
import {RegionButtons} from './RegionButtons.mjs';
import {SafeStorage} from './SafeStorage.mjs';
import {TouchPad} from './TouchPad.mjs';
import {relayOAuthPopup} from './network/OAuthPopup.mjs';

export {LoadProgress};
const shared = new ResourceStore();
const oauthPopupCallback = relayOAuthPopup();

const quote = value => "'" + String(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'";

/**
 * Browser options controlling application acquisition, policy, runtime creation, and progress reporting.
 *
 * @typedef {object} RunOptions
 * @property {HTMLCanvasElement} [canvas] Display target; defaults to the first canvas.
 * @property {object} [runtime] Runtime URL or a create({canvas, signal}) factory with Phar available.
 * @property {object} [policy] Allowed executable codeOrigins and optional content dataOrigins.
 * @property {object} [init] JSON application data, available to the authored PHP bootstrap.
 * @property {string} [integrity] SHA-256 SRI for the complete PHAR.
 * @property {ResourceStore} [resources] Shared resource cache with per-session ownership.
 * @property {AbortSignal} [signal] Cancels loading and application resource requests.
 * @property {function(LoadProgress):void} [onProgress] Immutable phase notifications.
 * @property {number} [timeout=45000] Milliseconds allowed for application readiness.
 * @property {string} [baseUrl] Base for relative application URLs.
 */

/**
 * Ready application runtime and the resources needed to inspect or dispose its browser session.
 *
 * @typedef {object} PhonicsSession
 * @property {string} url Application URL.
 * @property {AbortSignal} signal Session cancellation signal.
 * @property {object} php Browser PHP runtime.
 * @property {{id:string, code:string}} application Application identity and executable digest.
 * @property {object} [world] Content world, when the application uses composition.
 * @property {function():Promise<void>} dispose Idempotently release the application and its services.
 */

/**
 * Execute a self-contained PHP application in a fresh browser PHP/SDL runtime.
 * @param {string|URL} applicationUrl Executable PHAR URL.
 * @param {RunOptions} [options] Browser platform configuration.
 * @returns {Promise<PhonicsSession>} Ready application session with idempotent asynchronous disposal.
 */
export async function run(applicationUrl, options = {})
{
	if(oauthPopupCallback) return new Promise(() => {});

	const url = new URL(applicationUrl, options.baseUrl ?? globalThis.document?.baseURI).href;
	const controller = new AbortController();
	const signal = options.signal ? AbortSignal.any([controller.signal, options.signal]) : controller.signal;
	const store = options.resources ?? shared;
	const scope = store.scope(signal);
	const cleanup = [() => scope.close()];

	const policy = new PackageGraph(
		scope
		, {origin: globalThis.location?.origin ?? new URL(url).origin, ...options.policy}
	);

	const report = (phase, label, state) => {
		try
		{
			options.onProgress?.(new LoadProgress(phase, label, state));
		}
		catch(error)
		{
			console.error('Phonics progress observer failed', error);
		}
	};

	let php, disposed = false, runtimeReady = false, timer, restoreContexts = () => {};

	const stopped = new Promise((resolve, reject) => {

		const abort = () => reject(signal.reason);
		signal.addEventListener('abort', abort, {once: true});
		cleanup.push(() => signal.removeEventListener('abort', abort));

		if(signal.aborted) abort();
	});

	stopped.catch(() => {});

	const wait = promise => Promise.race([promise, stopped]);

	const releaseLateRuntime = instance => {
		Promise.resolve(instance.binary).then(() => instance.refresh()).catch(() => {});
	};

	const session = {
		url
		, signal

		, async dispose() {
			if(disposed) return;

			disposed = true;
			controller.abort(new Error('Application closed'));
			clearTimeout(timer);
			restoreContexts();
			let failure;

			try
			{
				if(php && runtimeReady) await php.refresh();
				else if(php) releaseLateRuntime(php);
			}
			catch(error)
			{
				failure = error;
			}

			for(const close of cleanup.reverse()) try { await close(); } catch(error) { failure ??= error; }

			if(failure) throw failure;
		}
	};

	try
	{
		signal.throwIfAborted();

		timer = setTimeout(
			() => controller.abort(new Error('Application did not become ready'))
			, options.timeout ?? 45000
		);

		policy.policy(url);
		report('application', 'Loading application', {total: 1, resource: url});
		const bytes = await wait(scope.read(url, {integrity: options.integrity}));
		signal.throwIfAborted();
		const canvas = options.canvas ?? globalThis.document?.querySelector('canvas');

		if(!canvas) throw new Error('Phonics requires a canvas');

		// Before PHP can create its SDL window, so the bridge caches cover every context made on this canvas.
		restoreContexts = optimizeCanvasContexts(canvas);

		report('runtime', 'Loading PHP runtime', {total: 2});

		if(options.runtime?.create)
		{
			await wait(Promise.resolve(options.runtime.create({canvas, signal})).then(instance => {
				if(disposed)
				{
					releaseLateRuntime(instance);
					signal.throwIfAborted();
				}

				php = instance;
			}));
		}
		else
		{
			const runtimeUrl = new URL(
				options.runtime?.url ?? '/runtime/php8.4-sdl.mjs'
				, globalThis.document?.baseURI ?? url
			);

			policy.policy(runtimeUrl);
			const {PhpSdl} = await wait(import(/* webpackIgnore: true */ runtimeUrl.href));
			signal.throwIfAborted();

			const sharedLibs = ['zlib','phar'].map(name => {
				const url = new URL(`./extensions/${name}/php8.4-${name}.so`, runtimeUrl);
				policy.policy(url);

				return {name: `php8.4-${name}.so`, url, ini: true};
			});

			php = new PhpSdl({
				canvas
				, persist: false
				, sharedLibs
				, ini: 'display_errors=0\nlog_errors=1\nerror_log=/dev/stderr\nphar.readonly=1\nphar.require_hash=1'
			});
		}

		session.php = php;
		const diagnostics = [];

		const diagnostic = event => {
			const text = event.detail.join('');
			diagnostics.push(text);
			console.error(text);
		};

		php.addEventListener('error', diagnostic);
		cleanup.push(() => php.removeEventListener('error', diagnostic));

		const module = await wait(Promise.resolve(php.binary).then(module => {
			runtimeReady = true;

			return module;
		}));

		signal.throwIfAborted();
		module.FS.mkdirTree('/phonics');
		module.FS.writeFile('/phonics/application.phar', bytes);
		let ready, fail;
		const loaded = new Promise((resolve, reject) => { ready = resolve; fail = reject; });

		loaded.catch(() => {});

		module.sceneReady = error => error ? fail(new Error(error)) : ready();
		const init = JSON.parse(JSON.stringify(options.init ?? {}));

		if(!init || typeof init !== 'object' || Array.isArray(init))
		throw new Error('Application init must be an object');

		// Direct application browser modules receive this host. Its services are the same shared corner controls and
		// guarded storage that composed applications get, so every game can offer mute and fullscreen shortcuts.
		const services = Object.freeze({QuickControls, SafeStorage, GameShell, RegionButtons, TouchPad});
		const host = {session, module, canvas, signal, cleanup, report, options, init, services};
		let reads = 0;

		host.importModule = async code => {
			signal.throwIfAborted();

			if(/\bimport\b\s*(?:\(|[\s\S]*?\bfrom\s*['"]|['"])/.test(code))
				throw new Error('Application browser module must be a self-contained bundle');

			const blob = URL.createObjectURL(new Blob([code], {type: 'text/javascript'}));
			cleanup.push(() => URL.revokeObjectURL(blob));
			const browser = await import(/* webpackIgnore: true */ blob);
			signal.throwIfAborted();

			return browser;
		};

		module.phonicsApplication = {
			abi: 1
			, init: JSON.stringify(init)
			, initialize: async (json, copy) => {
				if(host.archive) throw new Error('Application already initialized');

				signal.throwIfAborted();
				const descriptor = JSON.parse(json);

				const archive = new ArchiveResources({
					url
					, descriptor
					, store
					, signal
					, read: async member => {
						signal.throwIfAborted();
						const target = '/phonics/archive-read/' + (++reads);

						try
						{
							copy(member, target);

							return module.FS.readFile(target);
						}
						finally
						{
							try { module.FS.unlink(target); } catch { /* Failed copy has no file. */ } }
					}
				});

				host.archive = archive;
				host.store = archive.store;
				session.application = {
					id: archive.id
					, code: archive.code
					, fingerprint: String(options.integrity ?? '')
				};

				session.runtime = Object.freeze({
					id: String(options.runtime?.id ?? options.runtime?.url ?? 'custom-runtime')
				});

				cleanup.push(() => archive.close());
			}
			, activate: async code => {
				const browser = await host.importModule(code);
				const close = await browser.activate(host);

				if(typeof close === 'function') cleanup.push(close);
			}
		};

		report('game', 'Starting application', {total: 1});

		const archivePath = quote('/phonics/application.phar');

		const execution = php.run(
			"<?php if(!extension_loaded('phar')) throw new RuntimeException('Phar extension is required');"
			+ `new Phar(${archivePath}); require ${archivePath};`
		);

		const exit = await wait(Promise.race([execution, loaded.then(() => execution)]));

		if(exit !== 0) throw new Error(`PHP returned ${exit}: ${diagnostics.join(' ')}`);

		await wait(loaded);
		clearTimeout(timer);
		signal.throwIfAborted();
		report('ready', 'Ready', {completed: 1, total: 1});

		return session;
	}
	catch(error)
	{
		await session.dispose().catch(() => {});

		throw new Error(`${url}: ${error.message}`, {cause: error});
	}
}

/** Public browser entrypoints for running a packaged Phonics application and observing its loading progress. */
export const Phonics = {run, LoadProgress};

/** Default browser API namespace. */
export default Phonics;
