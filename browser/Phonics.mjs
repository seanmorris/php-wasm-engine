import {ResourceStore} from './ResourceStore.mjs';
import {PackageGraph, properties} from './PackageGraph.mjs';
import {SessionAssets} from './SessionAssets.mjs';
import {DomTexture} from './DomTexture.mjs';
import {SdlAudio} from './SdlAudio.mjs';
import {QuickControls} from './QuickControls.mjs';
import {GameLauncher} from './launcher/GameLauncher.mjs';
import {cacheProgramValidation} from './GlesPrograms.mjs';
import {cachePixelStoreQueries} from './GlesPixelStore.mjs';
import {readWorldResource} from './WorldDefinition.mjs';
import {InMemoryLobbyProvider} from './network/InMemoryLobbyProvider.mjs';
import {LobbyController} from './network/LobbyController.mjs';
import {LobbyOverlay} from './network/LobbyOverlay.mjs';
import {NetworkSession as BrowserNetworkSession} from './network/NetworkSession.mjs';
import {ResourceNegotiator} from './network/ResourceNegotiator.mjs';
import {ServiceRegistry} from './network/ServiceRegistry.mjs';
import {matrixConfiguration} from './network/Values.mjs';
import {WebRtcTransport} from './network/WebRtcTransport.mjs';
import {relayOAuthPopup} from './network/OAuthPopup.mjs';

const shared = new ResourceStore();
const oauthPopupCallback = relayOAuthPopup();

const quote = value => "'" + String(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'";

const decode = bytes => JSON.parse(new TextDecoder().decode(bytes));

const loadPhases = ['world','packages','code','runtime','assets','modules','game','ready'];

/** Immutable progress snapshot emitted while a world becomes playable. */
export class LoadProgress
{

	/**
	 * Describe one real loader phase and its completed unit count.
	 * @param {string} phase Stable phase identifier.
	 * @param {string} label Concise user-facing activity label.
	 * @param {object} [state] Optional unit counts and current resource identity.
	 * @param {number} [state.completed=0] Work units completed in this phase.
	 * @param {number} [state.total=0] Known work units, or zero while discovery is indeterminate.
	 * @param {string|null} [state.resource=null] Canonical resource currently being processed.
	 */
	constructor(phase, label, {completed = 0, total = 0, resource = null} = {})
	{
		const phaseIndex = loadPhases.indexOf(phase);

		if(phaseIndex < 0) throw new RangeError(`Unknown load phase: ${phase}`);

		if(typeof label !== 'string' || !label) throw new TypeError('Load progress requires a label');

		if(!Number.isFinite(completed) || !Number.isFinite(total) || completed < 0 || total < 0)
		throw new RangeError('Load progress counts must be finite and non-negative');

		Object.assign(this, {
			phase
			, label
			, completed
			, total
			, resource
			, phaseIndex
			, phaseCount: loadPhases.length
		});

		Object.freeze(this);
	}

	/** Return overall stage and unit progress from zero through one. */
	get fraction()
	{
		if(this.phase === 'ready') return 1;

		const within = this.total > 0 ? Math.min(1, this.completed / this.total) : 0;

		return (this.phaseIndex + within) / this.phaseCount;
	}
}

/**
 * Host controls for executable package origins.
 * @typedef {object} CodePolicy
 * @property {Iterable<string>} [codeOrigins] HTTP origins allowed to supply executable packages.
 * @property {string} [origin] Origin used when no explicit allow-list is supplied.
 * @property {Iterable<string>} [extensions] PHP runtime extensions available to package requirements.
 * @property {Iterable<string>} [signalingOrigins] Origins allowed to provide lobby signaling.
 * @property {Iterable<string>|"*"} [dataOrigins] Origins allowed to provide peer-negotiated data resources.
 * @property {RTCConfiguration['iceServers']} [iceServers] Host-approved WebRTC STUN/TURN servers.
 * @property {number} [maxResourceBytes] Maximum accepted peer-negotiated resource size.
 */

/**
 * PHP/SDL runtime selection or injection.
 * @typedef {object} RuntimeOptions
 * @property {string} [url] URL of the php-sdl-wasm ES module.
 * @property {string} [phpVersion] PHP version used while checking package requirements.
 * @property {function({canvas: HTMLCanvasElement, signal: AbortSignal}): Promise<object>} [create]
 * Custom runtime factory, primarily for hosts and tests.
 */

/**
 * Options accepted by {@link loadWorld}.
 * @typedef {object} LoadWorldOptions
 * @property {HTMLCanvasElement} [canvas] Rendering target; defaults to the document's first canvas.
 * @property {string} [baseUrl] Base URL used when worldUrl is relative and no document base is available.
 * @property {AbortSignal} [signal] Host cancellation signal combined with session disposal.
 * @property {ResourceStore} [resources] Shared canonical resource store.
 * @property {CodePolicy} [policy] Executable-code origin and runtime capability policy.
 * @property {RuntimeOptions} [runtime] Runtime URL, compatibility version, or injected factory.
 * @property {object} [network] Session-local lobby and transport configuration.
 * @property {function(LoadProgress): void} [onProgress] Synchronous observer for real loading phases and counts.
 */

/**
 * A ready world and the resources that remain owned until disposal.
 * @typedef {object} PhonicsSession
 * @property {string} url Canonical world URL.
 * @property {AbortSignal} signal Signal aborted by host cancellation or dispose().
 * @property {object} php Active php-sdl-wasm runtime.
 * @property {object} world Parsed neutral or legacy Tiled world document.
 * @property {PackageGraph} graph Resolved package and export graph.
 * @property {SessionAssets} assets Canonical URL to WASM mount adapter.
 * @property {string|object} start Canonical starting-map URL or neutral source/scope selector.
 * @property {GameLauncher} [launcher] Reusable world-declared resource and session-settings modal.
 * @property {function(): Promise<void>} dispose Stop the runtime and release every session-owned resource.
 */

/**
 * Values passed to an executable browser export's activate(context) function.
 * @typedef {object} BrowserModuleContext
 * @property {PhonicsSession} session Owning session.
 * @property {object} module Initialized php-sdl-wasm module and Phonics bridge functions.
 * @property {HTMLCanvasElement} canvas Rendering target.
 * @property {object} world Parsed neutral or legacy Tiled world document.
 * @property {Record<string, *>} config Tiled world properties keyed by name.
 * @property {string|object} start Canonical starting-map URL or neutral source/scope selector.
 * @property {SessionAssets} assets Session resource and mount adapter.
 * @property {function(string, string=): string} resolve Resolve an asset export or URL against its declarer.
 * @property {{SdlAudio: typeof SdlAudio, QuickControls: typeof QuickControls,
 * cacheProgramValidation: typeof cacheProgramValidation,
 * cachePixelStoreQueries: typeof cachePixelStoreQueries}} services Shared browser services.
 * @property {function(function(): (void|Promise<void>)): void} onClose Register reverse-order cleanup.
 * @property {function(string): Promise<object>} loadData Load a Tiled document and its declared dependencies.
 * @property {string} packageUrl Canonical manifest URL for the active browser export.
 */

/**
 * Load, verify, and activate a package manifest, neutral world, or legacy Tiled world.
 *
 * Imports and module dependencies resolve before executable code runs. Every call owns a fresh PHP code graph;
 * ordinary level changes stay inside that session. Dispose the returned session even after the host stops drawing.
 *
 * @param {string|URL} worldUrl Tiled world URL, resolved against options.baseUrl or document.baseURI.
 * @param {LoadWorldOptions} [options] Canvas, runtime, cancellation, cache, and code-origin settings.
 * @returns {Promise<PhonicsSession>} Ready session after both browser and PHP game entry points signal readiness.
 * @throws {Error} Reports the declaring resource and dependency chain for loading, policy, integrity,
 * or startup errors.
 */
export async function loadWorld(worldUrl, options = {})
{
	if(oauthPopupCallback) return new Promise(() => {});

	const url = new URL(worldUrl, options.baseUrl ?? globalThis.document?.baseURI).href;

	const report = (phase, label, state) => {
		const progress = new LoadProgress(phase, label, state);

		try
		{
			options.onProgress?.(progress);
		}
		catch(error)
		{
			console.error('Phonics load progress observer failed', error);
		}

		return progress;
	};

	const controller = new AbortController();
	const signal = options.signal ? AbortSignal.any([controller.signal, options.signal]) : controller.signal;
	const store = options.resources ?? shared, scope = store.scope(signal);
	const cleanup = [];
	let php, disposed = false;
	const session = {
		url
		, signal

		, async dispose() {
			if(disposed) return;

			disposed = true;
			controller.abort();
			let error;

			try { await php?.refresh(); } catch(failure) { error = failure; }

			for(const close of cleanup.reverse())
			{
				try { await close(); } catch(failure) { error ??= failure; }
			}

			scope.close();

			if(error) throw error;
		}
	};

	try
	{
		report('world', 'Reading world definition', {total: 1, resource: url});
		const launch = await readWorldResource(scope, url);
		report('world', 'World definition ready', {completed: 1, total: 1, resource: launch.url});
		const world = launch.world;
		const declaring = launch.url;
		const config = launch.legacy ? properties(world.properties) : {};

		const graph = new PackageGraph(scope, {
			origin: globalThis.location?.origin ?? new URL(url).origin
			, ...options.policy
			, phpVersion: options.runtime?.phpVersion ?? '8.4.0'
		});

		report('packages', 'Resolving package graph');
		await graph.manifest(new URL('../phonics.json', import.meta.url));

		if(launch.packageUrl) await graph.manifest(launch.packageUrl);

		await graph.imports(world, declaring);
		const gameReference = launch.legacy ? config['phonics.game'] : world.game;
		const game = await graph.resolve(gameReference, declaring);

		if(game.type !== 'game') throw new Error(`${declaring}: game must name a game export`);

		await graph.activateGraph(game);

		const modules = launch.legacy ? JSON.parse(config['phonics.modules'] ?? '[]') : (world.modules ?? []);

		for(const reference of modules) await graph.activateGraph(await graph.resolve(reference, declaring));
		let lobbyEntry = null, transportEntry = null;

		if(!launch.legacy && world.network)
		{
			lobbyEntry = await graph.resolve(world.network.lobby.provider, declaring);
			transportEntry = await graph.resolve(world.network.transport.use, declaring);

			if(lobbyEntry.type !== 'lobby-provider')
			throw new Error(`${declaring}: network lobby must name a lobby-provider export`);

			if(transportEntry.type !== 'transport')
			throw new Error(`${declaring}: network transport must name a transport export`);

			await graph.activateGraph(lobbyEntry);
			await graph.activateGraph(transportEntry);
		}

		let start, preloadStart = false, startData = null;

		if(launch.legacy)
		{
			const startReference = config['phonics.start'] ?? world.maps?.[0]?.fileName;

			if(!startReference) throw new Error(`${declaring}: missing phonics.start`);

			start = startReference.startsWith('@') || new URL(startReference, declaring).hash
				? await graph.resolve(startReference, declaring).then(entry => {
					if(entry.type !== 'asset') throw new Error(`${declaring}: phonics.start must be an asset`);

					return new URL(entry.path, entry.record.url).href;
				})
				: new URL(startReference, declaring).href;

			preloadStart = game.preloadStart !== false || graph.active.some(entry => entry.preloadStart === true);
			startData = preloadStart ? decode(await scope.read(start)) : null;
		}
		else
		{
			start = structuredClone(world.start);

			for(const source of Object.values(world.sources))
			{
				const entry = await graph.resolve(source.adapter, declaring);

				if(entry.type !== 'adapter')
				throw new Error(`${declaring}: source adapter must name an adapter export: ${source.adapter}`);

				await graph.activateGraph(entry);
				source.adapterReference = source.adapter;
				source.adapter = entry.id;
			}

			for(const slot of Object.values(world.resourceSlots ?? {}))
			for(const requirement of [slot.requirement, slot.stack?.first, slot.stack?.following].filter(Boolean))
				if(requirement.inspector)
				{
					const entry = await graph.resolve(requirement.inspector, declaring);

					if(entry.type !== 'resource-inspector')
					throw new Error(
						`${declaring}: resource requirement must name a resource-inspector export: ${requirement
							.inspector}`
					);

					await graph.activateGraph(entry);
					requirement.inspectorReference = requirement.inspector;
					requirement.inspector = entry.id;
				}

			for(const binding of world.bindings)
			{
				const entry = await graph.resolve(binding.use, declaring);

				if(entry.type !== 'system-set')
				throw new Error(`${declaring}: binding must name a system-set export: ${binding.use}`);

				await graph.activateGraph(entry);
				binding.useReference = binding.use;
				binding.use = entry.id;
			}
		}

		const runtimeWorld = structuredClone(world);

		if(!launch.legacy)
			for(const source of Object.values(runtimeWorld.sources))
			source.resources = source.resources.flatMap(resource => {
				if(!resource.slot) return [resource];

				const slot = runtimeWorld.resourceSlots[resource.slot];

				// Optional placements do not make the initial, single-player source unresolved.
				// NetworkSession.bindWorld() inserts them later when a match actually selected one.
				if(!slot?.defaultUrl) return slot?.required === false ? [] : [resource];

				const {slot: _slot, ...placement} = resource;

				return [{
					...placement
					, url: slot.defaultUrl
					, sourceUrl: slot.defaultUrl
					, ...(slot.integrity ? {integrity: slot.integrity} : {})
					, ...(slot.bytes === undefined ? {} : {bytes: slot.bytes})
					, defaultBinding: true
				}];
			});

		const visited = new Set();

		const visitDocument = async (data, source, chain = []) => {
			if(chain.includes(source)) throw new Error(`Tiled dependency cycle: ${[...chain, source].join(' -> ')}`);

			if(visited.has(source)) return;

			const visit = async value => {
				if(!value || typeof value !== 'object') return;

				const props = properties(value.properties);

				if(props['phonics.entity'])
				{
					const entry = await graph.resolve(props['phonics.entity'], props['phonics.source'] ?? source);

					if(!['factory','component'].includes(entry.type))
					throw new Error(`Expected entity factory: ${entry.id}`);

					await graph.activateGraph(entry);
				}

				for(const [key, child] of Object.entries(value))
				{
					if(['source', 'template'].includes(key) && typeof child === 'string')
					{
						const dependency = new URL(props['phonics.source.' + key] ?? child, source).href;
						await visitDocument(decode(await scope.read(dependency)), dependency, [...chain, source]);
					}
					else if(key !== 'properties') await visit(child);
				}
			};

			await visit(data);
			visited.add(source);
		};

		if(startData) await visitDocument(startData, start);

		const factoryExports = new Map();

		for(const record of graph.order)
			for(const [name, item] of Object.entries(record.data.exports ?? {}))
			{
				if(!['factory','component'].includes(item.type)) continue;

				const entry = {record,name,...item,id: record.url + '#' + name};
				const module = entry.module ? await graph.resolve(entry.module, record.url, record) : null;

				if(graph.activated.has(entry.id) || (module && graph.activated.has(module.id)))
				factoryExports.set(entry.id, entry);
			}

		report('packages', 'Package graph ready', {
			completed: graph.order.length
			, total: graph.order.length
			, resource: game.record.url
		});

		report('code', 'Planning executable files');

		const files = await graph.files(
			progress => report(
				'code'
				, progress.state === 'verified' ? 'Verified executable file' : 'Verifying executable files'
				, {completed: progress.completed, total: progress.total, resource: progress.url}
			)
		);

		signal.throwIfAborted();
		const canvas = options.canvas ?? globalThis.document?.querySelector('canvas');

		if(!canvas) throw new Error('Phonics requires a canvas');

		report('runtime', 'Loading PHP runtime', {total: 2});

		if(options.runtime?.create) php = await options.runtime.create({canvas, signal});
		else
		{
			const runtimeUrl = new URL(
				options.runtime?.url ?? '/runtime/php8.4-sdl.mjs'
				, globalThis.document?.baseURI ?? url
			);

			graph.policy(runtimeUrl);
			const {PhpSdl} = await import(/* webpackIgnore: true */ runtimeUrl.href);
			const zlibUrl = new URL('./extensions/zlib/php8.4-zlib.so', runtimeUrl);
			graph.policy(zlibUrl);

			php = new PhpSdl({
				canvas
				, persist: false
				, sharedLibs: [{name: 'php8.4-zlib.so', url: zlibUrl, ini: true}]
				, ini: 'display_errors=0\nlog_errors=1\nerror_log=/dev/stderr'
			});
		}

		session.php = php;
		report('runtime', 'Initializing PHP runtime', {completed: 1, total: 2});
		const diagnostics = [];

		const diagnostic = event => { diagnostics.push(event.detail.join('')); console.error(event.detail.join('')); };

		php.addEventListener('error', diagnostic);
		cleanup.push(() => php.removeEventListener('error', diagnostic));
		const module = await php.binary;
		report('runtime', 'PHP runtime ready', {completed: 2, total: 2});

		const resolve = (reference, owner = declaring) => graph.asset(reference, owner);
		const assets = new SessionAssets(module.FS, store, graph.order, signal, resolve);

		for(const file of files)
		{
			module.FS.mkdirTree(file.path.slice(0, file.path.lastIndexOf('/')));
			module.FS.writeFile(file.path, file.bytes);
			assets.bind(file.url, file.path);
		}

		const owned = assets.scope();
		cleanup.push(() => owned.close());

		const loadData = async (reference, chain = []) => {
			if(chain.includes(reference))
			throw new Error(`Tiled dependency cycle: ${[...chain, reference].join(' -> ')}`);

			await owned.load(reference);
			const data = decode(module.FS.readFile(assets.path(reference)));

			const visit = async value => {
				if(!value || typeof value !== 'object') return;

				const props = properties(value.properties);

				for(const property of value.properties ?? [])
					if(property.type === 'file' && property.value)
						await owned.load(
							new URL(
								props['phonics.source.property.' + property.name] ?? property.value
								, props['phonics.source'] ?? reference
							).href
						);

				for(const [key, child] of Object.entries(value))
				{
					if(['source', 'template', 'image'].includes(key) && typeof child === 'string')
					{
						const dependency = new URL(
							properties(value.properties)['phonics.source.' + key] ?? child
							, reference
						).href;

						if(['source', 'template'].includes(key)) await loadData(dependency, [...chain, reference]);
						else await owned.load(dependency);
					}
					else await visit(child);
				}
			};

			await visit(data);

			return data;
		};

		const sourceResourceCount = launch.legacy
			? 0
			: Object.values(runtimeWorld.sources)
			.reduce((count, source) => count + source.resources.filter(resource => resource.url).length, 0);

		const packagePreloads = graph.order.flatMap(
			record => (record.data.preload ?? []).map(path => new URL(path, record.url).href)
		);

		const assetTotal = 1 + Number(declaring !== url) + Number(preloadStart)
			+ sourceResourceCount + packagePreloads.length;

		let assetCompleted = 0;

		const loadAsset = async (label, resource, operation) => {
			report('assets', label, {completed: assetCompleted, total: assetTotal, resource});
			await operation();
			assetCompleted++;
			report('assets', label, {completed: assetCompleted, total: assetTotal, resource});
		};

		await loadAsset('Mounting launch resource', url, () => owned.load(url));

		if(declaring !== url)
		await loadAsset('Mounting world definition', declaring, () => owned.load(declaring));

		if(preloadStart) await loadAsset('Loading starting map', start, () => loadData(start));

		if(!launch.legacy)
			for(const [sourceId, source] of Object.entries(runtimeWorld.sources))
		for(const [index, resource] of source.resources.entries())
		{
			if(resource.slot) continue;

			const target = resolve(resource.url, resource.declaringUrl ?? declaring);

			try
			{
				await loadAsset(`Loading ${sourceId} resource`, target, async () => {
					if(resource.integrity) await scope.read(target, {integrity: resource.integrity});

					await owned.load(target);
				});
			}
			catch(error)
			{
				throw new Error(`${declaring}: source ${sourceId} resource ${index} (${target}): ${error.message}`, {
					cause: error
				});
			}

			resource.sourceUrl = target;
		}

		for(const resource of packagePreloads)
			await loadAsset('Loading package asset', resource, () => owned.load(resource));
		const dom = new DomTexture(canvas);
		cleanup.push(() => dom.dispose());
		const serviceRegistry = new ServiceRegistry;
		cleanup.push(() => serviceRegistry.close());

		if(lobbyEntry?.record.data.name === '@seanmorris/phonics' && lobbyEntry.name === 'memory-lobby')
		serviceRegistry.register('lobby-provider', lobbyEntry.id, value => new InMemoryLobbyProvider(value));

		if(transportEntry?.record.data.name === '@seanmorris/phonics' && transportEntry.name === 'webrtc')
		serviceRegistry.register('transport', transportEntry.id, value => new WebRtcTransport(value));

		const services = {
			SdlAudio
			, QuickControls
			, cacheProgramValidation
			, cachePixelStoreQueries
			, register: serviceRegistry.register.bind(serviceRegistry)
			, unregister: serviceRegistry.unregister.bind(serviceRegistry)
			, create: serviceRegistry.create.bind(serviceRegistry)
			, list: serviceRegistry.list.bind(serviceRegistry)
		};

		Object.assign(module, {
			resourceResolve: (reference, declaring) => resolve(reference, assets.source(declaring))
			, resourcePath: path => assets.path(path)
			, resourceSource: path => assets.source(path)
			, domTexture: dom
			, createAssetScope: () => {
				const result = assets.scope();
				cleanup.push(() => result.close());

				return result;
			}
			, phonicsWorld: JSON.stringify({...runtimeWorld, sourceUrl: declaring, launchUrl: url, start})
			, phonicsLoadMap: loadData
		});

		const browserContext = {
			session
			, module
			, canvas
			, world
			, config
			, start
			, assets
			, resolve
			, services
			, onClose: close => cleanup.push(close)
			, loadData
		};

		// Browser exports are verified self-contained bundles. No unverified second network import occurs.
		const browserEntries = graph.active.filter(entry => entry.browser);
		let browserCompleted = 0;

		report('modules', browserEntries.length ? 'Activating browser modules' : 'No browser modules required', {
			completed: browserEntries.length ? 0 : 1
			, total: browserEntries.length || 1
		});

		for(const entry of browserEntries)
		{
			const file = files.find(file => file.url === new URL(entry.browser, entry.record.url).href);
			const code = new TextDecoder().decode(file.bytes);

			if(/\bimport\s*(?:\(|[\s\S]*?\bfrom\s*['"]|['"])/.test(code))
				throw new Error(`Browser export must be a self-contained bundle: ${file.url}`);

			const blob = URL.createObjectURL(new Blob([code], {type: 'text/javascript'}));
			cleanup.push(() => URL.revokeObjectURL(blob));
			const loaded = await import(/* webpackIgnore: true */ blob);

			const close = await loaded.activate({
				...browserContext
				, packageUrl: entry.record.url
				, exportId: entry.id
				, exportType: entry.type
			});

			if(typeof close === 'function') cleanup.push(close);

			browserCompleted++;

			report('modules', 'Activated browser module', {
				completed: browserCompleted
				, total: browserEntries.length
				, resource: file.url
			});
		}

		if(world.network)
		{
			let providerOptions = {
				...world.network.lobby.options
				, ...options.network?.lobby
				, signal
			};

			if(lobbyEntry.record.data.name === '@seanmorris/phonics-matrix')
			providerOptions = {
				...providerOptions
				, ...matrixConfiguration(world.network.lobby.options, options.network?.lobby, options.policy)
				, authorizeHomeserver: homeserver => matrixConfiguration(
					world.network.lobby.options
					, {...options.network?.lobby, homeserver}
					, options.policy
				).homeserver
			};

			const provider = serviceRegistry.create('lobby-provider', lobbyEntry.id, providerOptions);

			const lobby = new LobbyController({
				provider
				, signal
				, transportFactory: values => serviceRegistry.create('transport', transportEntry.id, {
					...world.network.transport.options
					, ...options.network?.transport
					, iceServers: options.policy?.iceServers ?? world.network.transport.options.iceServers ?? []
					, ...values
				})
			});

			const resources = new ResourceNegotiator({
				world
				, store
				, signal
				, role: 'host'
				, policy: options.policy
				, inspectors: id => serviceRegistry.create('resource-inspector', id)
			});

			const network = new BrowserNetworkSession({
				controller: lobby
				, negotiator: resources
				, world
				, packages: graph.order.map(record => ({
					name: record.data.name
					, version: record.data.version
					, url: record.url
				}))
			});

			const frame = canvas.closest('#game-frame') ?? canvas.parentElement;
			let contentWorld = null;
			let contentLoad = null;

			const launcher = new GameLauncher({
				negotiator: resources
				, frame
				, world
				, prepareLocal: async values => {
					if(contentLoad) throw new Error('A local game is already loading');

					const pending = Promise.withResolvers();
					contentLoad = pending;
					pending.promise.catch(() => {});

					try
					{
						await network.prepareLocalWorld(values);
						await pending.promise;
					}
					finally
					{
						contentLoad = null;
					}
				}
				, openNetwork: () => open()
				, signal
			});

			const overlay = new LobbyOverlay({
				controller: lobby
				, launcher
				, frame
				, world
				, configuration: {homeserver: provider.homeserver ?? providerOptions.homeserver ?? 'Local'}
				, signal
			});

			let connecting = null;

			const open = async () => {
				overlay.open();

				if(['idle','failed'].includes(lobby.state))
				connecting ??= lobby.connect().finally(() => { connecting = null; });

				return connecting;
			};

			Object.assign(session, {
				network
				, launcher
				, lobby: {
					controller: lobby
					, overlay
					, open
					, openContent: () => launcher.openLocal()
					, close: () => overlay.close()
				}
			});

			Object.assign(module, {
				phonicsNetworkReceive: limit => network.bridge.receive(limit)
				, phonicsNetworkSend: (channel, family, tick, schema, payload) => network.sendGameplay(
					Number(family)
					, String(payload)
					, {channel: String(channel), tick: Number(tick), schema: Number(schema)}
				)
				, phonicsNetworkState: () => JSON.stringify(lobby.snapshot({
					bridge: network.bridge.diagnostics()
					, transport: network.transport?.diagnostics?.() ?? null
				}))
				, phonicsNetworkMatch: () => JSON.stringify(network.matchState())
				, phonicsNetworkLeave: () => network.leaveMatch()
				, phonicsNetworkWorld: () => network.boundWorld ? JSON.stringify(network.boundWorld) : ''
				, phonicsContentWorld: () => contentWorld ? JSON.stringify(contentWorld) : ''
				, phonicsContentReady: error => error
					? contentLoad?.reject(new Error(String(error)))
					: contentLoad?.resolve()
				, phonicsNetworkConfiguration: () => JSON.stringify(provider.configuration())
				, phonicsNetworkConfigure: value => {
					try
					{
						const configuration = provider.configure(JSON.parse(String(value)));

						return JSON.stringify({configuration});
					}
					catch(error)
					{
						return JSON.stringify({error: error instanceof Error ? error.message : String(error)});
					}
				}
			});

			network.addEventListener('resourcesready', async ({detail}) => {

				const current = () => !network.closed && network.boundWorld === detail.world;

				try
				{
					if(!current()) return;

					if(detail.role === 'local') contentWorld = detail.world;

					for(const source of Object.values(detail.world.sources))
					for(const resource of source.resources)
						if(resource.negotiated)
						{
							await scope.read(resource.sourceUrl, {integrity: resource.integrity});

							if(!current()) return;

							await owned.load(resource.sourceUrl);

							if(!current()) return;
						}

					network.dispatchEvent(new CustomEvent('resourcesmounted', {detail}));
				}
				catch(error)
				{
					if(!current()) return;

					if(detail.role === 'local') contentLoad?.reject(error);
					else network.fail(error);
				}
			});

			cleanup.push(() => {
				contentLoad?.reject(new Error('Game session closed'));
				delete module.phonicsNetworkReceive;
				delete module.phonicsNetworkSend;
				delete module.phonicsNetworkState;
				delete module.phonicsNetworkMatch;
				delete module.phonicsNetworkLeave;
				delete module.phonicsNetworkWorld;
				delete module.phonicsContentWorld;
				delete module.phonicsContentReady;
				delete module.phonicsNetworkConfiguration;
				delete module.phonicsNetworkConfigure;
				overlay.dispose();
				launcher.dispose();
				network.close();
			});
		}

		let ready, fail;
		const loaded = new Promise((resolve, reject) => { ready = resolve; fail = reject; });

		loaded.catch(() => {});

		const abort = () => fail(signal.reason);
		signal.addEventListener('abort', abort, {once: true});
		cleanup.push(() => signal.removeEventListener('abort', abort));

		module.sceneReady = error => error ? fail(new Error(error)) : ready();
		const core = graph.order.find(record => record.data.name === '@seanmorris/phonics');
		let code = `<?php require_once ${quote(core.mount + 'autoload.php')};\n`;

		for(const record of graph.order)
		{
			for(const bootstrap of record.data.php?.bootstrap ?? [])
			code += `require_once ${quote(record.mount + bootstrap)};\n`;
		}

		code += `$phonics = new \\Phonics\\Package\\Context(json_decode(vrzno_env('phonicsWorld'), true));\n`;
		code += `$phonics->factories->strictReferences = true;\n`;
		code += `$phonicsCleanup = null; $phonicsCleanup = static function() use ($phonics, &$phonicsCleanup): void { $phonics->close(); vrzno_env('onRefresh')->delete($phonicsCleanup); }; vrzno_env('onRefresh')->add($phonicsCleanup);\n`;

		for(const entry of graph.active.filter(entry => entry.type !== 'game'))
			if(entry.php)
			{
				const file = quote(entry.record.mount + entry.php);
				const id = quote(entry.id);

				if(entry.type === 'adapter')
				code += `$phonics->runtime->adapter(${id}, require ${file});\n`;
				else if(entry.type === 'system-set')
				code += `$phonics->runtime->systemSet(${id}, require ${file});\n`;
				else code += `$register = require ${file}; $register($phonics);\n`;
			}

		if(!launch.legacy)
		{
			code += `foreach($phonics->world['sources'] as $id => $source) { $phonics->runtime->source(new \\Phonics\\World\\SourceDefinition($id, $source['adapter'], $source['resources'], $source['options'] ?? [], $phonics->world['sourceUrl'])); }\n`;
			code += `foreach($phonics->world['bindings'] as $binding) { $phonics->runtime->bind($binding['id'], $binding['use'], $binding['on'] ?? [], $binding['options'] ?? [], $binding['after'] ?? []); }\n`;
		}

		for(const entry of factoryExports.values())
		{
			if(!entry.class) throw new Error(`Factory export has no class: ${entry.id}`);

			code += `$phonics->factories->alias(${quote(entry.id)}, ${quote(entry.class)});\n`;

			for(const [alias, record] of graph.aliases)
				if(record === entry.record)
				code += `$phonics->factories->alias(${quote(
					'@' + alias + '/' + entry.name
				)}, ${quote(
					entry.class
				)});\n`;
		}

		code += `require ${quote(game.record.mount + game.php)};\n`;
		report('game', 'Starting game code', {total: 2, resource: game.id});
		const exit = await php.run(code);

		if(exit !== 0) throw new Error(`PHP returned ${exit} activating ${game.id}: ${diagnostics.join(" ")}`);

		report('game', 'Waiting for the game scene', {completed: 1, total: 2, resource: game.id});

		const timeout = setTimeout(
			() => fail(new Error(`Game did not become ready: ${game.id}: ${diagnostics.join(' ')}`))
			, 45000
		);

		try { await loaded; } finally { clearTimeout(timeout); }

		signal.throwIfAborted();
		report('game', 'Game scene ready', {completed: 2, total: 2, resource: game.id});
		Object.assign(session, {world, graph, assets, start});

		if(world.network?.activation === 'auto') await session.lobby.open();

		report('ready', 'Ready', {completed: 1, total: 1});

		return session;
	}
	catch(error)
	{
		await session.dispose().catch(() => {});

		throw new Error(`${url}: ${error.message}`, {cause: error});
	}
}

/** Namespace-style default API for script hosts that prefer Phonics.loadWorld(). */
export const Phonics = {loadWorld, LoadProgress};
export default Phonics;
