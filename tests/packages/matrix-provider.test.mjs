import test from 'node:test';
import assert from 'node:assert/strict';
import {MatrixLobbyProvider} from '../../packages/phonics-matrix/browser/matrix.mjs';

const response = (value, status = 200) => new Response(JSON.stringify(value), {
	status
	, headers: {'Content-Type': 'application/json'}
});

test('Matrix provider defaults to Matrix.org and scopes stored sessions by homeserver', async () => {
	const values = new Map;
	const storage = {
		getItem: key => values.get(key) ?? null
		, setItem: (key, value) => values.set(key, value)
		, removeItem: key => values.delete(key)
	};

	const calls = [];

	const fetch = async (url, options) => {
		url = new URL(url);
		calls.push({url, options});

		if(url.pathname.endsWith('/account/whoami'))
		return response({user_id: '@host:matrix.org', device_id: 'DEVICE'});

		if(url.pathname.includes('/join/')) return response({room_id: '!room:matrix.org'});

		if(url.pathname.endsWith('/sync'))
		return new Promise((resolve, reject) => {
			options.signal.addEventListener('abort', () => reject(options.signal.reason), {once: true});
		});

		return response({event_id: '$event'});
	};

	const provider = new MatrixLobbyProvider({
		accessToken: 'token'
		, room: '#game:matrix.org'
		, fetch
		, storage
		, heartbeat: 60_000
	});

	assert.equal(provider.homeserver, 'https://matrix.org');
	assert.equal(provider.syncTimeout, 10_000);
	assert.equal(provider.syncMinInterval, 2_000);
	assert.equal(provider.syncEmptyDelay, 2_000);
	await provider.connect();
	assert.equal(provider.profile().id, '@host:matrix.org');
	assert.equal(values.has('phonics.matrix.session:https://matrix.org'), true);
	assert.match(calls[0].options.headers.Authorization, /Bearer token/);
	await provider.chat('ordinary room text');

	await provider.gameOffer({
		msgtype: 'm.text'
		, body: 'Join PHP Billiards: https://games.example/#phonics-game=test'
		, 'org.seanmorris.phonics.game.v1': {v: 1, kind: 'offer'}
	}, 'offer-test'
	);

	const messages = calls.filter(call => call.url.pathname.includes('/send/m.room.message/'));
	assert.equal(messages.length, 2);
	assert.equal(JSON.parse(messages[0].options.body).body, 'ordinary room text');
	assert.equal(JSON.parse(messages[1].options.body)['org.seanmorris.phonics.game.v1'].kind, 'offer');

	await provider.roomHistory({
		types: ['m.room.message', 'org.seanmorris.phonics.game.v1']
		, limit: 1000
	});

	const history = calls.filter(call => call.url.pathname.endsWith('/messages'));
	assert.equal(history.length, 1);

	assert.deepEqual(JSON.parse(history[0].url.searchParams.get('filter')).types, [
		'm.room.message', 'org.seanmorris.phonics.game.v1'
	]);

	provider.close();
});

test('Matrix provider rejects missing lobby aliases and Spaces with actionable errors', async () => {

	const createProvider = fetch => new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, room: '#billiards:matrix.example'
		, accessToken: 'token'
		, fetch
	});

	const missing = createProvider(async url => {
		url = new URL(url);

		if(url.pathname.endsWith('/account/whoami'))
		return response({user_id: '@host:matrix.example', device_id: 'DEVICE'});

		return response({errcode: 'M_NOT_FOUND', error: 'Room alias not found'}, 404);
	});

	await assert.rejects(() => missing.connect(), error => {
		assert.equal(error.code, 'matrix_lobby_missing');
		assert.match(error.message, /public chat room, not a Space/);

		return true;
	});

	missing.close();

	const space = createProvider(async url => {
		url = new URL(url);

		if(url.pathname.endsWith('/account/whoami'))
		return response({user_id: '@host:matrix.example', device_id: 'DEVICE'});

		if(url.pathname.includes('/join/')) return response({room_id: '!space:matrix.example'});

		if(url.pathname.endsWith('/state/m.room.create/')) return response({type: 'm.space'});

		return response({});
	});

	await assert.rejects(() => space.connect(), error => {
		assert.equal(error.code, 'matrix_lobby_space');
		assert.match(error.message, /is a Space.*chat room instead/);

		return true;
	});

	assert.equal(space.connected, false);
	space.close();
});

test('Matrix provider filters events by authenticated sender, recipient, and expiry', async () => {
	const provider = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example/path'
		, accessToken: 'token'
		, userId: '@guest:matrix.example'
		, fetch: async () => response({})
	});

	const invitations = [];
	const signals = [];
	const presence = [];

	provider.addEventListener('invitation', value => invitations.push(value.detail));
	provider.addEventListener('signal', value => signals.push(value.detail));
	provider.addEventListener('presence', value => presence.push(value.detail));

	provider.consume({
		type: 'org.seanmorris.phonics.presence.v1'
		, sender: '@host:matrix.example'
		, content: {peer: '@host:matrix.example', displayName: 'Host', expiresAt: Date.now() + 5000}
	});

	provider.consume({
		type: 'org.seanmorris.phonics.invitation.v1'
		, sender: '@host:matrix.example'
		, content: {
			id: 'invite'
			, from: '@host:matrix.example'
			, to: '@guest:matrix.example'
			, expiresAt: Date.now() + 5000
			, payload: {mode: 'co-op'}
		}
	});

	provider.consume({
		type: 'org.seanmorris.phonics.signal.v1'
		, sender: '@attacker:matrix.example'
		, content: {from: '@host:matrix.example', to: '@guest:matrix.example', payload: {description: {}}}
	});

	assert.equal(provider.homeserver, 'https://matrix.example');
	assert.equal(presence.at(-1).peers[1].id, '@host:matrix.example');
	assert.equal(invitations[0].invitation.payload.mode, 'co-op');
	assert.equal(signals.length, 0);
	assert.equal(provider.loginPassword, undefined);
	provider.close();
});

test('Matrix signals share one rate-limited event per batch and unpack in order', async () => {
	const sends = [];

	const provider = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, accessToken: 'token'
		, userId: '@host:matrix.example'
		, signalBatch: 20
		, fetch: async (url, options) => {
			sends.push({path: new URL(url).pathname, body: JSON.parse(options.body)});

			// Synapse refuses the first attempt, as rc_message does once a burst is spent.
			return sends.length === 1
				? response({errcode: 'M_LIMIT_EXCEEDED', error: 'Too Many Requests', retry_after_ms: 10}, 429)
				: response({event_id: '$signals'});
		}
	});

	Object.assign(provider, {connected: true, roomId: '!room:matrix.example'});
	const statuses = [];
	provider.addEventListener('signalingstatus', ({detail}) => statuses.push(detail));
	const candidates = Array.from({length: 5}, (_, index) => ({sessionId: 's', payload: {candidate: {index}}}));
	await Promise.all(candidates.map(value => provider.signalPeer('@guest:matrix.example', value)));

	assert.equal(sends.length, 2);
	assert.equal(sends[0].path, sends[1].path, 'the retry reuses the transaction');
	assert.match(sends[0].path, /\/send\/org\.seanmorris\.phonics\.signal\.v1\//);
	assert.deepEqual(sends[1].body.payloads, candidates);
	assert.equal(sends[1].body.to, '@guest:matrix.example');
	assert.equal(statuses[0].code, 'M_LIMIT_EXCEEDED');

	const received = [];
	const guest = new MatrixLobbyProvider({homeserver: 'https://matrix.example', userId: '@guest:matrix.example'});
	guest.addEventListener('signal', ({detail}) => received.push(detail));

	guest.consume({
		type: 'org.seanmorris.phonics.signal.v1'
		, sender: '@host:matrix.example'
		, content: sends[1].body
	});

	assert.deepEqual(received.map(value => value.payload), candidates);
	assert.ok(received.every(value => value.from === '@host:matrix.example'));
	provider.close();
	guest.close();
});

test('Matrix sync scopes events and backs off when a homeserver returns without long polling', async () => {
	const syncs = [];

	const fetch = async (url, options) => {
		url = new URL(url);

		if(url.pathname.endsWith('/account/whoami'))
		return response({user_id: '@host:matrix.example', device_id: 'DEVICE'});

		if(url.pathname.includes('/join/')) return response({room_id: '!room:matrix.example'});

		if(url.pathname.endsWith('/sync'))
		{
			syncs.push({at: Date.now(), query: url.searchParams});

			return response({next_batch: `sync-${syncs.length}`, rooms: {join: {}}});
		}

		return response({event_id: '$event'});
	};

	const provider = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, room: '#game:matrix.example'
		, accessToken: 'token'
		, fetch
		, heartbeat: 60_000
		, syncMinInterval: 20
		, syncEmptyDelay: 40
		, syncEmptyMaxDelay: 160
	});

	await provider.connect();
	await new Promise(resolve => setTimeout(resolve, 230));
	assert.ok(syncs.length >= 3 && syncs.length <= 4, `expected adaptive backoff, received ${syncs.length} syncs`);
	assert.ok(syncs[1].at - syncs[0].at >= 35, 'first empty response did not back off');
	assert.ok(syncs[2].at - syncs[1].at >= 70, 'repeated empty responses did not increase backoff');
	const filter = JSON.parse(syncs[0].query.get('filter'));
	assert.deepEqual(filter.room.rooms, ['!room:matrix.example']);
	assert.deepEqual(filter.presence.types, []);
	assert.deepEqual(filter.account_data.types, []);
	assert.ok(filter.room.timeline.types.includes('org.seanmorris.phonics.game.v1'));
	assert.ok(filter.room.timeline.types.includes('m.room.message'));
	assert.ok(filter.to_device.types.includes('org.seanmorris.phonics.claim.v1'));
	provider.close();
});

test('Matrix sync retains a hard floor when every response contains a room event', async () => {
	const syncs = [];

	const fetch = async url => {
		url = new URL(url);

		if(url.pathname.endsWith('/account/whoami'))
		return response({user_id: '@host:matrix.example', device_id: 'DEVICE'});

		if(url.pathname.includes('/join/')) return response({room_id: '!room:matrix.example'});

		if(url.pathname.endsWith('/sync'))
		{
			syncs.push(Date.now());

			return response({
				next_batch: `sync-${syncs.length}`
				, rooms: {
					join: {
						'!room:matrix.example': {
							timeline: {
								events: [{
									type: 'org.seanmorris.phonics.game.v1'
									, sender: '@guest:matrix.example'
									, content: {}
								}]
							}
						}
					}
				}
			});
		}

		return response({event_id: '$event'});
	};

	const provider = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, room: '#game:matrix.example'
		, accessToken: 'token'
		, fetch
		, heartbeat: 60_000
		, syncMinInterval: 40
		, syncEmptyDelay: 1
		, syncEmptyMaxDelay: 1
	});

	await provider.connect();
	await new Promise(resolve => setTimeout(resolve, 150));
	assert.ok(syncs.length >= 3 && syncs.length <= 4, `event responses hot-looped ${syncs.length} syncs`);

	for(let index = 1; index < syncs.length; index++)
	assert.ok(syncs[index] - syncs[index - 1] >= 35, 'event response bypassed the hard sync floor');

	provider.close();
});

test('Matrix sync applies the request floor to malformed and failed responses', async () => {
	const syncs = [];

	const fetch = async url => {
		url = new URL(url);

		if(url.pathname.endsWith('/account/whoami'))
		return response({user_id: '@host:matrix.example', device_id: 'DEVICE'});

		if(url.pathname.includes('/join/')) return response({room_id: '!room:matrix.example'});

		if(url.pathname.endsWith('/sync'))
		{
			syncs.push(Date.now());

			return syncs.length % 2
				? response({rooms: {join: {}}})
				: response({error: 'busy', retry_after_ms: 40}, 503);
		}

		return response({event_id: '$event'});
	};

	const provider = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, room: '#game:matrix.example'
		, accessToken: 'token'
		, fetch
		, heartbeat: 60_000
		, syncMinInterval: 40
		, syncEmptyDelay: 40
		, syncEmptyMaxDelay: 40
	});

	await provider.connect();
	await new Promise(resolve => setTimeout(resolve, 135));
	assert.ok(syncs.length >= 3 && syncs.length <= 4, `failures hot-looped ${syncs.length} syncs`);

	for(let index = 1; index < syncs.length; index++)
	assert.ok(syncs[index] - syncs[index - 1] >= 35, 'failure bypassed the hard sync floor');

	provider.close();
});

test('Matrix OAuth uses dynamic registration, PKCE, callback cleanup, and refresh tokens', async () => {
	const values = new Map;
	const storage = {
		getItem: key => values.get(key) ?? null
		, setItem: (key, value) => values.set(key, value)
		, removeItem: key => values.delete(key)
	};

	const calls = [];
	let callbackUrl = '';

	const fetch = async (url, options) => {
		url = new URL(url);
		calls.push({url, options});

		if(url.pathname.endsWith('/auth_metadata'))
		return response({
			authorization_endpoint: 'https://auth.example/authorize'
			, registration_endpoint: 'https://auth.example/register'
			, token_endpoint: 'https://auth.example/token'
			, revocation_endpoint: 'https://auth.example/revoke'
			, code_challenge_methods_supported: ['S256']
		});

		if(url.pathname === '/register') return response({client_id: 'public-client'}, 201);

		if(url.pathname === '/token')
		{
			const form = new URLSearchParams(options.body);

			if(form.get('grant_type') === 'refresh_token')
			return response({access_token: 'refreshed', refresh_token: 'refresh-2', expires_in: 300});

			assert.equal(form.get('code'), 'authorization-code');
			assert.equal(form.get('client_id'), 'public-client');
			assert.match(form.get('code_verifier'), /^[A-Za-z0-9_-]{43,128}$/);

			return response({access_token: 'oauth-access', refresh_token: 'refresh-1', expires_in: 300});
		}

		if(url.pathname.endsWith('/account/whoami'))
		return response({user_id: '@oauth:matrix.example', device_id: 'OAUTHDEVICE'});

		return response({});
	};

	const provider = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, room: '#game:matrix.example'
		, fetch
		, storage
		, oauthStorage: storage
		, location: {href: 'https://game.example/play?world=episode'}
		, history: {state: null, replaceState: (_state, _title, url) => { callbackUrl = url; }}
	});

	const authorization = new URL(await provider.beginOAuth());
	const state = authorization.searchParams.get('state');

	assert.equal(authorization.origin, 'https://auth.example');
	assert.equal(authorization.searchParams.get('response_type'), 'code');
	assert.equal(authorization.searchParams.get('response_mode'), 'fragment');
	assert.equal(authorization.searchParams.get('code_challenge_method'), 'S256');
	assert.equal(authorization.searchParams.has('code_verifier'), false);
	assert.match(authorization.searchParams.get('scope'), /urn:matrix:client:api:\*/);
	assert.equal(typeof provider.loginPassword, 'undefined');

	await provider.completeOAuth(`https://game.example/play?world=episode#state=${state}&code=authorization-code`);

	assert.equal(callbackUrl, 'https://game.example/play?world=episode');
	assert.equal(provider.accessToken, 'oauth-access');
	assert.equal(provider.refreshToken, 'refresh-1');
	assert.equal([...values.keys()].some(key => key.startsWith('phonics.matrix.oauth:')), false);

	provider.expiresAt = 0;
	await provider.request('GET', '/_matrix/client/v3/account/whoami');
	assert.equal(provider.accessToken, 'refreshed');
	assert.equal(provider.refreshToken, 'refresh-2');
	assert.equal(calls.some(({url}) => url.pathname === '/register'), true);
	provider.close();
});

test('Matrix popup completion leaves the opener history unchanged', async () => {
	const values = new Map;
	const storage = {
		getItem: key => values.get(key) ?? null
		, setItem: (key, value) => values.set(key, value)
		, removeItem: key => values.delete(key)
	};

	let replaced = false;

	const fetch = async (url, options) => {
		url = new URL(url);

		if(url.pathname.endsWith('/auth_metadata'))
		return response({
			authorization_endpoint: 'https://auth.example/authorize'
			, registration_endpoint: 'https://auth.example/register'
			, token_endpoint: 'https://auth.example/token'
			, revocation_endpoint: 'https://auth.example/revoke'
			, code_challenge_methods_supported: ['S256']
		});

		if(url.pathname === '/register') return response({client_id: 'popup-client'});

		if(url.pathname === '/token')
		{
			assert.equal(new URLSearchParams(options.body).get('code'), 'popup-code');

			return response({access_token: 'popup-access', refresh_token: 'popup-refresh', expires_in: 300});
		}

		return response({});
	};

	const provider = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, fetch
		, storage
		, oauthStorage: storage
		, location: {href: 'https://game.example/play?world=episode'}
		, history: {state: null, replaceState: () => { replaced = true; }}
	});

	const authorization = new URL(
		await provider.beginOAuth('https://game.example/play?world=episode&phonics.oauth.popup=popup-id')
	);

	const state = authorization.searchParams.get('state');

	await provider.completeOAuth(
		`https://game.example/play?world=episode&phonics.oauth.popup=popup-id#state=${state}&code=popup-code`
		, {replaceHistory: false}
	);

	assert.equal(replaced, false);
	assert.equal(provider.accessToken, 'popup-access');
	provider.close();
});

test('Matrix logout stops syncing, revokes OAuth credentials, clears storage, and remains reusable', async () => {

	const values = new Map;
	const sessionKey = 'phonics.matrix.session:https://matrix.example';

	values.set(sessionKey, JSON.stringify({
		accessToken: 'oauth-access'
		, refreshToken: 'oauth-refresh'
		, expiresAt: Date.now() + 300_000
		, clientId: 'public-client'
		, tokenEndpoint: 'https://auth.example/token'
		, revocationEndpoint: 'https://auth.example/revoke'
		, authApi: 'oauth'
		, userId: '@oauth:matrix.example'
		, deviceId: 'DEVICE'
	}));

	const storage = {
		getItem: key => values.get(key) ?? null
		, setItem: (key, value) => values.set(key, value)
		, removeItem: key => values.delete(key)
	};

	const calls = [];
	let syncAborted = false;

	const fetch = async (url, options) => {
		url = new URL(url);
		calls.push({url, options});

		if(url.pathname.endsWith('/account/whoami'))
		return response({user_id: '@oauth:matrix.example', device_id: 'DEVICE'});

		if(url.pathname.includes('/join/')) return response({room_id: '!room:matrix.example'});

		if(url.pathname.endsWith('/sync'))
		return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => {
			syncAborted = true;
			reject(options.signal.reason);
		}, {once: true}));

		return response({});
	};

	const provider = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, room: '#game:matrix.example'
		, fetch
		, storage
		, heartbeat: 60_000
	});

	await provider.connect();
	const result = await provider.logout();

	const revoked = calls.filter(({url}) => url.href === 'https://auth.example/revoke')
		.map(({options}) => Object.fromEntries(new URLSearchParams(options.body)));

	assert.equal(result.remote, true);
	assert.deepEqual(result.failures, []);
	assert.equal(syncAborted, true);
	assert.equal(provider.connected, false);
	assert.equal(provider.signal.aborted, false);
	assert.equal(provider.accessToken, null);
	assert.equal(provider.refreshToken, null);
	assert.equal(values.has(sessionKey), false);
	assert.equal(calls.some(({url}) => url.pathname.endsWith('/logout')), true);
	assert.deepEqual(revoked.map(value => value.token).sort(), ['oauth-access','oauth-refresh']);
	assert.deepEqual(revoked.map(value => value.client_id), ['public-client','public-client']);
	await assert.rejects(provider.connect(), {code: 'matrix_auth_required'});
	provider.close();
});
