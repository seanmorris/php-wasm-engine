import {PackageGraph, properties} from './PackageGraph.mjs';
import {SessionAssets} from './SessionAssets.mjs';
import {DomTexture} from './DomTexture.mjs';
import {SdlAudio} from './SdlAudio.mjs';
import {QuickControls} from './QuickControls.mjs';
import {SafeStorage} from './SafeStorage.mjs';
import {SamplePlayer} from './SamplePlayer.mjs';
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
 * @property {{SdlAudio: typeof SdlAudio, QuickControls: typeof QuickControls, SafeStorage: typeof SafeStorage,
 * SamplePlayer: typeof SamplePlayer,
 * cacheProgramValidation: typeof cacheProgramValidation,
 * cachePixelStoreQueries: typeof cachePixelStoreQueries}} services Shared browser services.
 * @property {function(function(): (void|Promise<void>)): void} onClose Register reverse-order cleanup.
 * @property {function(string): Promise<object>} loadData Load a Tiled document and its declared dependencies.
 * @property {string} packageUrl Canonical manifest URL for the active browser export.
 */


const decode = bytes => JSON.parse(new TextDecoder().decode(bytes));

/** Application-owned world composition. Activated explicitly by the executable PHP bootstrap. */
export function activate(host)
{
	const {session, module, canvas, signal, store, cleanup, report, options} = host;

	module.phonicsCompose = async json => {
		const request = JSON.parse(json);
		const url = host.archive.source(request.world);
		request.entry = host.archive.source(request.entry);
		const scope = store.scope(signal);
		cleanup.push(() => scope.close());
		report('world', 'Reading world definition', {total: 1, resource: url});
		const launch = await readWorldResource(scope, url, {game: request.entry, network: request.network});
		report('world', 'World definition ready', {completed: 1, total: 1, resource: launch.url});
		const world = launch.world;
		const declaring = launch.url;
		const config = launch.legacy ? properties(world.properties) : {};

		const graph = new PackageGraph(scope, {
			origin: globalThis.location?.origin ?? new URL(url).origin
			, ...options.policy
			, phpVersion: options.runtime?.phpVersion ?? '8.4.0'
			, extensions: ['sdl', 'vrzno', 'zlib', 'phar']
		});

		report('packages', 'Resolving package graph');
		graph.aliases.set('phonics', await graph.manifest(host.archive.coreUrl));

		if(launch.packageUrl) await graph.manifest(launch.packageUrl);

		await graph.imports(world, declaring);
		const gameReference = request.entry;
		world.game = host.archive.id;
		world.init = {...world.init, ...host.init};
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

		for(const record of graph.order)
		{
			const member = host.archive.member(record.url);

			if(member)
			record.mount = 'phar:///phonics/application.phar/' + member.slice(0, member.lastIndexOf('/') + 1);
		}

		const files = await graph.files(
			progress => report(
				'code'
				, progress.state === 'verified' ? 'Verified executable file' : 'Verifying executable files'
				, {completed: progress.completed, total: progress.total, resource: progress.url}
			)
		);

		// Remote modules are executable dependencies too. Their verified bytes must
		// agree before peers exchange simulation traffic, independently of WAD data.
		const remoteCode = files.filter(file => !file.path.startsWith('phar://'))
			.map(file => [file.record.data.name, file.path.slice(file.record.mount.length), file.integrity])
			.sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b)));

		const digest = new Uint8Array(
			await crypto.subtle.digest(
				'SHA-256'
				, new TextEncoder().encode(JSON.stringify([host.archive.code, remoteCode]))
			)
		);

		const application = {id: host.archive.id, code: 'sha256-' + btoa(String.fromCharCode(...digest))};
		session.application = application;


		const resolve = (reference, owner = declaring) => graph.asset(reference, owner);
		const assets = new SessionAssets(module.FS, store, graph.order, signal, resolve);

		for(const file of files)
		{
			if(!file.path.startsWith('phar://'))
			{
				module.FS.mkdirTree(file.path.slice(0, file.path.lastIndexOf('/')));
				module.FS.writeFile(file.path, file.bytes);
			}

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
			, SafeStorage
			, SamplePlayer
			, cacheProgramValidation
			, cachePixelStoreQueries
			, register: serviceRegistry.register.bind(serviceRegistry)
			, unregister: serviceRegistry.unregister.bind(serviceRegistry)
			, create: serviceRegistry.create.bind(serviceRegistry)
			, list: serviceRegistry.list.bind(serviceRegistry)
		};

		Object.assign(module, {
			resourceResolve: (reference, declaring) => resolve(reference, assets.source(declaring))
			, resourcePath: path => host.archive.stream(assets.source(path)) ?? assets.path(path)
			, resourceNativePath: path => assets.path(path)
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

			const loaded = await host.importModule(code);

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
				, application
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

		Object.assign(session, {world, graph, assets, start});

		if(world.network?.activation === 'auto') await session.lobby.open();

		return JSON.stringify({
			world: JSON.parse(module.phonicsWorld)
			, legacy: launch.legacy
			, bootstraps: graph.order.flatMap(
				record => (record.data.php?.bootstrap ?? []).map(file => record.mount + file)
			)
			, entries: graph.active.filter(entry => entry.type !== 'game' && entry.php)
				.map(entry => ({id: entry.id, type: entry.type, file: entry.record.mount + entry.php}))
			, factories: [...factoryExports.values()].flatMap(entry => [
				{id: entry.id, class: entry.class}
				, ...[...graph.aliases].filter(([, record]) => record === entry.record)
					.map(([alias]) => ({id: '@' + alias + '/' + entry.name, class: entry.class}))
			])
			, entry: game.record.mount + game.php
		});
	};
}
