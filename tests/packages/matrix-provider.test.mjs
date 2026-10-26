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

	const provider = new MatrixLobbyProvider({accessToken: 'token', fetch, storage, heartbeat: 60_000});

	assert.equal(provider.homeserver, 'https://matrix.org');
	await provider.connect();
	assert.equal(provider.profile().id, '@host:matrix.org');
	assert.equal(values.has('phonics.matrix.session:https://matrix.org'), true);
	assert.match(calls[0].options.headers.Authorization, /Bearer token/);
	provider.close();
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
