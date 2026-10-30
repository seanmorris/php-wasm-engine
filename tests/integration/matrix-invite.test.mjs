import test from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {chromium} from '@playwright/test';
import {MessageFamily} from '../../browser/network/Protocol.mjs';

const homeserver = process.env.MATRIX_TEST_SERVER;
const root = fileURLToPath(new URL('../../', import.meta.url));

async function api(path, body, token, server = homeserver)
{
	const response = await fetch(server + path, {
		method: body ? 'POST' : 'GET'
		, headers: {'Content-Type': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {})}
		, body: body ? JSON.stringify(body) : undefined
	});

	const value = await response.json();

	if(!response.ok) throw new Error(`${response.status}: ${value.errcode}: ${value.error}`);

	return value;
}

test('real Synapse guest admission, two-browser TURN relay, ICE restart, outage and bilateral redaction', {
	skip: !homeserver
	, timeout: 180_000
}, async t => {
	const http = createServer(async (req, res) => {
		try
		{
			const pathname = new URL(req.url, 'http://localhost').pathname;

			if(pathname === '/')
			return res.end(
				'<!doctype html><title>Private invitation fixture</title><button id="join">Join game</button>'
			);

			if(!pathname.endsWith('.mjs') || pathname.includes('..'))
			{
				res.writeHead(404);

				return res.end();
			}

			res.setHeader('Content-Type', 'text/javascript');
			res.end(await readFile(root + pathname));
		}
		catch
		{
			res.writeHead(404);
			res.end();
		}
	});

	await new Promise(resolve => http.listen(0, '127.0.0.1', resolve));
	t.after(() => new Promise(resolve => http.close(resolve)));
	const base = `http://localhost:${http.address().port}/`;

	const browser = await chromium.launch({
		executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium'
		, headless: true
		, args: ['--no-sandbox', '--disable-dev-shm-usage']
	});

	t.after(async () => {
		const pages = browser.contexts().flatMap(context => context.pages());
		await Promise.allSettled(pages.map(page => page.evaluate(() => window.lobby?.close())));

		await browser.close();
	});

	const hostContext = await browser.newContext(), guestContext = await browser.newContext();
	const host = await hostContext.newPage(), guest = await guestContext.newPage();
	const errors = [];

	for(const page of [host, guest]) page.on('pageerror', error => errors.push(error.message));

	const account = await api('/_matrix/client/v3/login', {
		type: 'm.login.password'
		, identifier: {type: 'm.id.user', user: process.env.MATRIX_TEST_USER ?? 'host'}
		, password: process.env.MATRIX_TEST_PASSWORD
	});

	const room = await api('/_matrix/client/v3/createRoom', {visibility: 'private'}, account.access_token);

	t.after(async () => {
		await api(`/_matrix/client/v3/rooms/${encodeURIComponent(room.room_id)}/leave`, {}, account.access_token);
		await api(`/_matrix/client/v3/rooms/${encodeURIComponent(room.room_id)}/forget`, {}, account.access_token);
	});

	async function initialize(page, isGuest)
	{
		await page.evaluate(async ({homeserver, account, room, isGuest}) => {
			const {MatrixLobbyProvider} = await import('/packages/phonics-matrix/browser/matrix.mjs');
			const {LobbyController} = await import('/browser/network/LobbyController.mjs');
			const {WebRtcTransport} = await import('/browser/network/WebRtcTransport.mjs');
			window.messages = [];
			window.failures = [];
			window.recoveries = [];

			window.provider = new MatrixLobbyProvider({
				homeserver
				, room: room.room_id
				, guest: isGuest
				, ...(isGuest ? {} : {accessToken: account.access_token, deviceId: account.device_id})
			});

			window.lobby = new LobbyController({
				provider
				, prepareInvitation: async payload => ({publicPayload: {game: 'fixture'}, privatePayload: payload})
				, transportFactory: async options => new WebRtcTransport({
					...options
					, timeout: 30_000
					, iceTransportPolicy: 'relay'
					, iceServers: await provider.iceServers()
					, refreshIceServers: () => provider.iceServers({refresh: true})
				})
			});

			lobby.addEventListener(
				'message'
				, e => messages.push({channel: e.detail.channel, data: Array.from(e.detail.data)})
			);

			lobby.addEventListener('recovery', e => recoveries.push(e.detail));
			lobby.addEventListener('statechange', e => { if(e.detail.error) failures.push(e.detail.error.message); });

			if(!isGuest) await lobby.connect();
			else

			document.querySelector('#join').onclick = () => {
				window.joined = lobby.joinInviteLink(location.href, async () => {
					window.resolvingResources = true;
					await new Promise(resolve => window.resourcesReady = resolve);

					return {};
				});

				joined.catch(error => failures.push(error.message));
			};
		}, {homeserver, account: isGuest ? {} : account, room, isGuest});
	}

	await host.goto(base);
	await initialize(host, false);
	const invite = await host.evaluate(() => lobby.createInviteLink({map: 'E1M1', mode: 'co-op'}));
	const privateRoom = await host.evaluate(() => provider.linkState.roomId);
	let guestMutations = 0;

	guest.on(
		'request'
		, request => {
			if(request.url().startsWith(homeserver) && request.method() !== 'GET' && request.method() !== 'OPTIONS')
guestMutations++;
		}
	);

	await guest.goto(invite.url);
	await initialize(guest, true);
	assert.equal(guestMutations, 0, 'opening/unfurling the link must not claim it');
	assert.equal(await guest.evaluate(() => localStorage.length), 0);
	await guest.click('#join');
	await guest.waitForFunction(() => window.resolvingResources, {timeout: 30_000});
	assert.equal(await host.evaluate(() => lobby.transport === null), true, 'no SDP before resources are ready');
	const guestId = await guest.evaluate(() => provider.userId);
	assert.notEqual(guestId, account.user_id);
	await guest.evaluate(() => resourcesReady());

	await Promise.all(
		[host.waitForFunction(() => lobby.state === 'connected')
			, guest.waitForFunction(() => lobby.state === 'connected')
		]
	);

	const selected = await host.evaluate(async () => {
		const stats = await lobby.transport.pc.getStats();

		const pair = [...stats.values()].find(
			value => value.type === 'candidate-pair' && value.state === 'succeeded' && value.nominated
		);

		return {
			local: stats.get(pair.localCandidateId).candidateType
			, remote: stats.get(pair.remoteCandidateId).candidateType
		};
	});

	assert.deepEqual(selected, {local: 'relay', remote: 'relay'});

	await host.evaluate(
		() => {
			lobby.transport.send('control', new Uint8Array([1, 2]));
			lobby.transport.send('realtime', new Uint8Array([3, 4]));
		}
	);

	await guest.waitForFunction(() => messages.length === 2);

	assert.deepEqual(
		await guest.evaluate(() => messages.map(message => message.channel).sort())
		, ['control', 'realtime']
	);

	assert.equal(await guest.evaluate(() => localStorage.length), 0, 'guest credentials are memory-only');

	await host.evaluate(
		() => { lobby.markReady(); lobby.markStarting(); lobby.markPlaying(); lobby.transport.beginRecovery(true); }
	);

	await guest.waitForFunction(() => recoveries.some(value => value.state === 'resynchronizing'));
	await host.waitForFunction(() => recoveries.some(value => value.state === 'resynchronizing'));

	await Promise.all(
		[host.evaluate(() => lobby.transport.finishRecovery()), guest.evaluate(() => lobby.transport.finishRecovery())]
	);

	await hostContext.route(`${homeserver}/**`, route => route.abort());
	await guest.evaluate(() => lobby.transport.send('control', new Uint8Array([5])));
	await host.waitForFunction(() => messages.some(value => value.data[0] === 5));

	assert.equal(
		await host.evaluate(() => lobby.transport.closed)
		, false
		, 'Matrix outage must not kill healthy WebRTC'
	);

	await hostContext.unroute(`${homeserver}/**`);

	const events = await api(
		`/_matrix/client/v3/rooms/${encodeURIComponent(privateRoom)}/messages?dir=b&limit=100`
		, null
		, account.access_token
	);

	const signals = events.chunk.filter(value => value.type.startsWith('org.seanmorris.phonics.'));
	assert.ok(signals.some(value => value.sender === guestId));
	assert.ok(signals.some(value => value.sender === account.user_id));
	assert.ok(signals.every(value => value.content.ciphertext && !JSON.stringify(value.content).includes('v=0')));
	await host.evaluate(() => lobby.close());
	await guest.waitForFunction(() => !provider.linkState);

	for(const event of signals)
	{
		const redacted = await api(
			`/_matrix/client/v3/rooms/${encodeURIComponent(privateRoom)}/event/${encodeURIComponent(event.event_id)}`
			, null
			, account.access_token
		);

		assert.deepEqual(redacted.content, {});
	}

	assert.deepEqual(await host.evaluate(() => provider.cleanupJournal), {});
	await guest.evaluate(() => lobby.close());
	assert.deepEqual(errors, []);

	t.diagnostic(
		'Two isolated Chromium contexts connected via TURN-only ICE, restarted ICE, kept data flowing during a Matrix outage, and redacted host and guest events.'
	);
});

test('real guest-disabled homeserver gives a bounded unsupported-server error', {
	skip: !process.env.MATRIX_TEST_DISABLED_SERVER
	, timeout: 15_000
}, async () => {
	const {MatrixLobbyProvider} = await import('../../packages/phonics-matrix/browser/matrix.mjs');
	const server = process.env.MATRIX_TEST_DISABLED_SERVER;
	const provider = new MatrixLobbyProvider({homeserver: server, guest: true});

	try
	{
		await assert.rejects(
			provider.joinInviteLink({
				v: 1
				, provider: 'matrix'
				, server
				, host: '@host:disabled.test'
				, device: 'HOST'
				, id: crypto.randomUUID()
				, secret: Buffer.alloc(32, 1).toString('base64url')
				, expiresAt: Date.now() + 60_000
			})
			, {code: 'matrix_guest_unavailable'}
		);
	}
	finally
	{
		await provider.close();
	}
});

test('built Doom page joins a private link after activation and resumes the same match after ICE recovery', {
	skip: !homeserver || !process.env.MATRIX_TEST_SITE
	, timeout: 180_000
}, async t => {
	const browser = await chromium.launch({
		executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium'
		, headless: true
		, args: ['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader']
	});

	const host = await (await browser.newContext()).newPage();
	const guest = await (await browser.newContext()).newPage();

	t.after(async () => {
		await Promise.allSettled([host, guest].map(page => page.evaluate(() => window.previewSession?.dispose())));
		await browser.close();
	});

	const failures = [];

	for(const page of [host, guest])
	{
		page.on('pageerror', error => failures.push(error.message));
		page.on('console', message => { if(message.type() === 'error') failures.push(message.text()); });
	}

	const account = await api('/_matrix/client/v3/login', {
		type: 'm.login.password'
		, identifier: {type: 'm.id.user', user: process.env.MATRIX_TEST_USER ?? 'host'}
		, password: process.env.MATRIX_TEST_PASSWORD
	});

	const room = await api('/_matrix/client/v3/createRoom', {visibility: 'private'}, account.access_token);

	t.after(async () => {
		await api(`/_matrix/client/v3/rooms/${encodeURIComponent(room.room_id)}/leave`, {}, account.access_token);
		await api(`/_matrix/client/v3/rooms/${encodeURIComponent(room.room_id)}/forget`, {}, account.access_token);
	});

	const url = new URL('games/doom-phonics/', process.env.MATRIX_TEST_SITE).href;
	await host.goto(url);

	const startup = await host.evaluate(async ({homeserver, account, room}) => {
		const embed = document.querySelector('[data-game-embed]');
		const {mountPreview} = await import(new URL(embed.dataset.host, location.href).href);
		const runtime = embed.querySelector('[data-game-runtime]');
		runtime.hidden = false;
		embed.querySelector('.game-embed-poster').remove();

		const preview = mountPreview(runtime, {
			network: {
				lobby: {
					homeserver
					, room: room.room_id
					, accessToken: account.access_token
					, deviceId: account.device_id
				}
			}
		});

		await preview.launch(new URL(embed.dataset.application, location.href).href);

		return {ready: !!window.previewSession?.lobby, status: document.querySelector('#status')?.textContent};
	}, {homeserver, account, room});

	assert.equal(startup.ready, true, JSON.stringify({startup, failures}));

	const invite = await host.evaluate(async () => {
		await previewSession.lobby.open();

		return previewSession.lobby.createInviteLink({settings: {mode: 'cooperative', skill: '2'}});
	});

	let mutations = 0;

	guest.on('request', request => {
		if(request.url().startsWith(homeserver) && !['GET', 'OPTIONS'].includes(request.method())) mutations++;
	});

	await guest.goto(invite.url);
	assert.equal(await guest.locator('.game-embed-poster').innerText(), 'Join game');
	assert.equal(mutations, 0);
	assert.equal(await guest.evaluate(() => !!window.previewSession), false);
	await guest.getByRole('button', {name: 'Join game', exact: true}).click();

	try
	{
		await Promise.all(
			[host, guest].map(
				page => page.waitForFunction(
					() => window.previewSession?.lobby.controller.state === 'playing'
					, undefined
					, {timeout: 90_000}
				)
			)
		);
	}
	catch(error)
	{
		t.diagnostic(
			JSON.stringify({
				failures
				, states: await Promise.all([host, guest].map(page => page.evaluate(() => ({
					status: document.querySelector('#status')?.textContent
					, state: window.previewSession?.lobby.controller.state
					, text: document.querySelector('.phonics-lobby')?.textContent
				}))))
			})
		);

		throw error;
	}

	assert.equal(new URL(guest.url()).hash, '');

	const matches = await Promise.all(
		[host, guest].map(page => page.evaluate(() => previewSession.network.match.sessionId))
	);

	await Promise.all([host, guest].map(page => page.evaluate(() => {
		window.recoveryEvents = [];

		for(const [source, target] of Object.entries(
			{
				network: previewSession.network
				, lobby: previewSession.lobby.controller
				, transport: previewSession.lobby.controller.transport
			}
		))
		for(const name of ['error', 'recovery', 'disconnect', 'matchend', 'close'])
		target.addEventListener(
			name
			, ({detail}) => recoveryEvents.push(
				{
					source
					, name
					, detail: {
						state: detail?.state
						, generation: detail?.generation
						, reason: detail?.reason
						, code: detail?.code
						, message: detail?.message
					}
				}
			)
		);
	})));

	await host.evaluate(snapshotFamily => {
		const network = previewSession.network;
		const send = network.sendGameplay.bind(network);
		// Hold the authoritative correction to exercise menus while recovery is paused.

		network.sendGameplay = (...args) => {
			if(network.recovery && args[0] === snapshotFamily)
			{

				window.resumeSnapshot = () => {
					network.sendGameplay = send;
					send(...args);
				};

				return true;
			}

			return send(...args);
		};

		previewSession.lobby.controller.transport.beginRecovery(true);
	}, MessageFamily.snapshot
	);

	await guest.waitForFunction(() => !!previewSession.network.recovery);
	await guest.locator('canvas').focus();
	await guest.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
	await guest.keyboard.press('Escape');
	await guest.waitForFunction(() => previewSession.doomState?.menu.open === true);
	assert.equal(await guest.evaluate(() => previewSession.doomState.paused), true);
	await guest.keyboard.press('Escape');
	await guest.waitForFunction(() => previewSession.doomState?.menu.open === false);
	await host.waitForFunction(() => typeof window.resumeSnapshot === 'function');
	await host.evaluate(() => window.resumeSnapshot());

	await Promise.all([host, guest].map(page => page.waitForFunction(() => {
		const network = window.previewSession?.network;

		return network?.recovery === null && network?.controller.transport.recovering === false;
	}, undefined, {timeout: 30_000}))).catch(async error => {
		t.diagnostic(
			JSON.stringify({
				failures
				, recovery: await Promise.all([host, guest].map(page => page.evaluate(() => ({
					events: window.recoveryEvents
					, state: previewSession?.lobby.controller.state
					, recovery: previewSession?.network.recovery
					, connection: previewSession?.lobby.controller.transport?.pc.connectionState
					, doom: previewSession?.doomState
					, bridge: previewSession?.network.bridge.diagnostics()
				}))))
			})
		);

		throw error;
	});

	assert.deepEqual(
		await Promise.all([host, guest].map(page => page.evaluate(() => previewSession.network.match.sessionId)))
		, matches
	);

	await Promise.all([host, guest].map(page => page.waitForFunction(() => {
		const state = previewSession.doomState;

		return state?.paused === false && state.frame.simulationSteps > 0;
	})));

	assert.deepEqual(failures, []);

	t.diagnostic(
		'Built PHARs and the real documentation poster joined a cooperative Doom match without guest onboarding, then recovered the same match.'
	);
});
