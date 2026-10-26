const PRESENCE = 'org.seanmorris.phonics.presence.v1';
const INVITATION = 'org.seanmorris.phonics.invitation.v1';
const INVITATION_STATE = 'org.seanmorris.phonics.invitation_state.v1';
const SIGNAL = 'org.seanmorris.phonics.signal.v1';
const CHAT = 'org.seanmorris.phonics.chat.v1';
const CONFIGURATION_KEY = 'phonics.matrix.configuration';
const OAUTH_PENDING_PREFIX = 'phonics.matrix.oauth:';

const event = (type, detail) => new CustomEvent(type, {detail});

class MatrixLobbyError extends Error
{

	constructor(code, message, {cause, status = null, details = {}} = {})
	{
		super(message, {cause});
		this.name = 'MatrixLobbyError';
		this.code = code;
		this.status = status;
		this.details = details;
	}
}

const cleanServer = value => {
	const url = new URL(value ?? 'https://matrix.org');

	if(!['https:', 'http:'].includes(url.protocol) || url.username || url.password)
	throw new MatrixLobbyError('matrix_config', 'Matrix homeserver must be HTTP(S) without credentials');

	return url.origin;
};

const peer = value => {
	if(typeof value !== 'string' || !/^@[^:]+:.+$/.test(value))
	throw new MatrixLobbyError('matrix_user', `Invalid Matrix user ID: ${value}`);

	return value;
};

const readStorage = (storage, key) => {
	try { return JSON.parse(storage?.getItem(key) ?? 'null'); } catch { return null; }
};

const base64Url = bytes => {
	let binary = '';

	for(const byte of bytes) binary += String.fromCharCode(byte);

	return btoa(binary).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/, '');
};

const randomValue = size => {
	const bytes = new Uint8Array(size);
	crypto.getRandomValues(bytes);

	return base64Url(bytes);
};

const pkceChallenge = async verifier => base64Url(
	new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier)))
);

const callbackParameters = value => {
	const url = new URL(value);
	const fragment = new URLSearchParams(url.hash.startsWith('#') ? url.hash.slice(1) : '');
	const source = fragment.has('code') || fragment.has('error') || fragment.has('state')
		? fragment
		: url.searchParams;

	return {url, source};
};

/** Matrix Client-Server API implementation of the Phonics lobby provider contract. */
export class MatrixLobbyProvider extends EventTarget
{

	constructor(
		{
			homeserver = 'https://matrix.org'
			, room = '#phonics:matrix.org'
			, accessToken = null
			, userId = null
			, deviceId = null
			, displayName = null
			, fetch: fetcher = globalThis.fetch
			, storage = globalThis.localStorage
			, oauthStorage = globalThis.sessionStorage ?? storage
			, location: browserLocation = globalThis.location
			, history: browserHistory = globalThis.history
			, clientName = 'Phonics'
			, clientUri = null
			, authorizeHomeserver = cleanServer
			, signal
			, presenceTtl = 45_000
			, heartbeat = 15_000
			, syncTimeout = 30_000
			, invitationTtl = 30_000
		} = {}
	) {
		super();

		this.fetch = (...args) => fetcher(...args);
		this.storage = storage;
		this.oauthStorage = oauthStorage;
		this.location = browserLocation;
		this.history = browserHistory;
		this.clientName = String(clientName);
		this.clientUri = clientUri;

		this.authorizeHomeserver = value => cleanServer(authorizeHomeserver(value));
		this.defaults = Object.freeze({homeserver: this.authorizeHomeserver(homeserver), room: String(room)});
		const configured = readStorage(storage, CONFIGURATION_KEY) ?? {};
		this.homeserver = this.authorizeHomeserver(configured.homeserver ?? homeserver);
		this.room = String(configured.room ?? room);
		this.storageKey = `phonics.matrix.session:${this.homeserver}`;
		const saved = readStorage(storage, this.storageKey) ?? {};

		this.accessToken = accessToken ?? saved.accessToken ?? null;
		this.userId = userId ?? saved.userId ?? null;
		this.deviceId = deviceId ?? saved.deviceId ?? null;
		this.displayName = displayName ?? saved.displayName ?? this.userId;
		this.refreshToken = saved.refreshToken ?? null;
		this.expiresAt = saved.expiresAt ?? 0;
		this.clientId = saved.clientId ?? null;
		this.tokenEndpoint = saved.tokenEndpoint ?? null;
		this.revocationEndpoint = saved.revocationEndpoint ?? null;
		this.authApi = saved.authApi ?? (this.refreshToken ? 'oauth' : 'legacy');
		this.presenceTtl = presenceTtl;
		this.heartbeat = heartbeat;
		this.syncTimeout = syncTimeout;
		this.invitationTtl = invitationTtl;
		this.controller = new AbortController;
		this.signal = signal ? AbortSignal.any([signal, this.controller.signal]) : this.controller.signal;
		this.roomId = null;
		this.since = null;
		this.connected = false;
		this.closed = false;
		this.peers = new Map;
		this.invitations = new Map;
		this.lastInvite = new Map;
		this.transaction = 0;
	}

	profile()
	{
		return Object.freeze(
			{id: this.userId, displayName: this.displayName ?? this.userId, homeserver: this.homeserver}
		);
	}

	configuration()
	{
		return Object.freeze({homeserver: this.homeserver, room: this.room});
	}

	/** Persist user-selected lobby defaults without accepting credentials. */
	configure({homeserver = this.homeserver, room = this.room} = {})
	{
		const nextHomeserver = this.authorizeHomeserver(homeserver);
		const nextRoom = String(room).trim();

		if(!nextRoom) throw new MatrixLobbyError('matrix_config', 'Matrix lobby channel is required');

		try
		{
			this.storage?.setItem(CONFIGURATION_KEY, JSON.stringify({
				homeserver: nextHomeserver
				, room: nextRoom
			}));
		}
		catch
		{
		}

		if(this.connected)
		return Object.freeze({homeserver: nextHomeserver, room: nextRoom, pending: true});

		this.homeserver = nextHomeserver;
		this.room = nextRoom;

		this.storageKey = `phonics.matrix.session:${this.homeserver}`;
		const saved = readStorage(this.storage, this.storageKey) ?? {};
		this.accessToken = saved.accessToken ?? null;
		this.refreshToken = saved.refreshToken ?? null;
		this.expiresAt = saved.expiresAt ?? 0;
		this.clientId = saved.clientId ?? null;
		this.tokenEndpoint = saved.tokenEndpoint ?? null;
		this.revocationEndpoint = saved.revocationEndpoint ?? null;
		this.authApi = saved.authApi ?? (this.refreshToken ? 'oauth' : 'legacy');
		this.userId = saved.userId ?? null;
		this.deviceId = saved.deviceId ?? null;
		this.displayName = saved.displayName ?? this.userId;

		return this.configuration();
	}

	async loginFlows()
	{
		const result = await this.request('GET', '/_matrix/client/v3/login', {auth: false});

		return result.flows ?? [];
	}

	async loginToken(token, deviceName = 'Phonics browser')
	{
		const result = await this.request('POST', '/_matrix/client/v3/login', {
			auth: false
			, body: {
				type: 'm.login.token'
				, token: String(token)
				, initial_device_display_name: deviceName
			}
		});

		return this.useSession(result);
	}

	async oauthMetadata()
	{
		const metadata = await this.request('GET', '/_matrix/client/v1/auth_metadata', {auth: false});
		const required = [
			'authorization_endpoint'
			, 'registration_endpoint'
			, 'token_endpoint'
			, 'revocation_endpoint'
		];

		for(const name of required)
		if(typeof metadata[name] !== 'string')
		throw new MatrixLobbyError('matrix_oauth_metadata', `Matrix OAuth metadata has no ${name}`);

		if(!metadata.code_challenge_methods_supported?.includes('S256'))
		throw new MatrixLobbyError('matrix_oauth_metadata', 'Matrix OAuth server does not support PKCE S256');

		return metadata;
	}

	/** Begin Matrix OAuth 2.0 authorization-code login with dynamic registration and PKCE. */
	async beginOAuth(returnUrl = this.location?.href)
	{
		if(!returnUrl) throw new MatrixLobbyError('matrix_oauth', 'Matrix OAuth needs a browser return URL');

		const metadata = await this.oauthMetadata();
		const callback = new URL(returnUrl);
		callback.hash = '';

		for(const name of ['code','state','error','error_description','error_uri']) callback.searchParams.delete(name);

		const local = callback.protocol === 'http:'
			&& ['localhost','127.0.0.1','[::1]'].includes(callback.hostname);

		if(callback.protocol !== 'https:' && !local)
		throw new MatrixLobbyError('matrix_oauth', 'Matrix OAuth requires HTTPS or an HTTP loopback address');

		const applicationType = local ? 'native' : 'web';
		const registeredRedirect = new URL(callback);

		if(local) registeredRedirect.port = '';

		const clientUri = this.clientUri ?? (callback.protocol === 'https:'
			? new URL('/', callback).href
			: 'https://seanmorris.github.io/phonics/'
		);

		const registration = await this.requestUrl('POST', metadata.registration_endpoint, {
			auth: false
			, body: {
				application_type: applicationType
				, client_name: this.clientName
				, client_uri: clientUri
				, redirect_uris: [registeredRedirect.href]
				, token_endpoint_auth_method: 'none'
				, response_types: ['code']
				, grant_types: ['authorization_code','refresh_token']
			}
		});

		if(typeof registration.client_id !== 'string' || !registration.client_id)
		throw new MatrixLobbyError('matrix_oauth_registration', 'Matrix OAuth registration returned no client ID');

		const state = randomValue(32);
		const verifier = randomValue(64);
		const deviceId = (this.deviceId ?? crypto.randomUUID()).replaceAll('-', '').toUpperCase();
		const pending = {
			homeserver: this.homeserver
			, room: this.room
			, state
			, verifier
			, deviceId
			, clientId: registration.client_id
			, redirectUri: callback.href
			, tokenEndpoint: metadata.token_endpoint
			, revocationEndpoint: metadata.revocation_endpoint
			, createdAt: Date.now()
		};

		try
		{
			this.oauthStorage?.setItem(OAUTH_PENDING_PREFIX + state, JSON.stringify(pending));
		}
		catch(error)
		{
			throw new MatrixLobbyError(
				'matrix_oauth_storage'
				, 'Cannot retain Matrix OAuth state for the redirect'
				, {cause: error}
			);
		}

		const authorization = new URL(metadata.authorization_endpoint);
		authorization.searchParams.set('client_id', registration.client_id);
		authorization.searchParams.set('response_type', 'code');
		authorization.searchParams.set('response_mode', 'fragment');
		authorization.searchParams.set('redirect_uri', callback.href);
		authorization.searchParams.set('scope', `urn:matrix:client:api:* urn:matrix:client:device:${deviceId}`);
		authorization.searchParams.set('state', state);
		authorization.searchParams.set('code_challenge', await pkceChallenge(verifier));
		authorization.searchParams.set('code_challenge_method', 'S256');

		return authorization.href;
	}

	/** Exchange an OAuth callback, optionally cleaning it from the current browser history entry. */
	async completeOAuth(value = this.location?.href, {replaceHistory = true} = {})
	{
		if(!value) return false;

		const {url, source} = callbackParameters(value);
		const state = source.get('state');

		if(!state || (!source.has('code') && !source.has('error'))) return false;

		const key = OAUTH_PENDING_PREFIX + state;
		const pending = readStorage(this.oauthStorage, key);

		try { this.oauthStorage?.removeItem(key); } catch {}

		url.hash = '';

		for(const name of ['code','state','error','error_description','error_uri']) url.searchParams.delete(name);

		if(replaceHistory)
		try { this.history?.replaceState?.(this.history.state ?? null, '', url.href); } catch {}

		if(!pending || pending.state !== state || pending.homeserver !== this.homeserver
			|| Date.now() - pending.createdAt > 10 * 60_000
		)
		throw new MatrixLobbyError('matrix_oauth_state', 'Matrix OAuth callback state is missing or expired');

		if(source.has('error'))
		throw new MatrixLobbyError(
			'matrix_oauth_denied'
			, source.get('error_description') || `Matrix OAuth was denied: ${source.get('error')}`
		);

		const result = await this.requestUrl('POST', pending.tokenEndpoint, {
			auth: false
			, form: {
				grant_type: 'authorization_code'
				, code: source.get('code')
				, redirect_uri: pending.redirectUri
				, client_id: pending.clientId
				, code_verifier: pending.verifier
			}
		});

		this.useOAuthSession(result, pending);

		return true;
	}

	async connect()
	{
		if(this.closed) throw new MatrixLobbyError('lobby_closed', 'Matrix lobby is closed');

		if(this.connected) return this.profile();

		if(!this.accessToken) await this.completeOAuth();

		if(!this.accessToken)
		throw new MatrixLobbyError('matrix_auth_required', 'Continue with Matrix to enter the lobby');

		const who = await this.request('GET', '/_matrix/client/v3/account/whoami');
		this.userId = peer(who.user_id);
		this.deviceId = who.device_id ?? this.deviceId;
		this.displayName ??= this.userId;
		this.save();

		const joined = await this.request(
			'POST'
			, `/_matrix/client/v3/join/${encodeURIComponent(this.room)}`
			, {body: {}}
		);

		this.roomId = joined.room_id;
		this.connected = true;
		await this.announcePresence();

		this.heartbeatTimer = setInterval(() => {
			this.announcePresence().catch(error => this.dispatchEvent(event('error', error)));
		}, this.heartbeat
		);

		this.syncTask = this.syncLoop();

		this.syncTask.catch(error => {
			if(!this.closed && !this.signal.aborted) this.dispatchEvent(event('error', error));
		});

		return this.profile();
	}

	async invite(peerId, payload = {})
	{
		this.requireConnected();
		peerId = peer(peerId);
		const now = Date.now();

		if(now - (this.lastInvite.get(peerId) ?? 0) < 15_000)
		throw new MatrixLobbyError('invite_rate', `Please wait before inviting ${peerId} again`);

		this.lastInvite.set(peerId, now);
		const invitation = {
			id: crypto.randomUUID()
			, from: this.userId
			, to: peerId
			, payload: structuredClone(payload)
			, expiresAt: now + this.invitationTtl
		};

		this.invitations.set(invitation.id, {...invitation, state: 'pending'});
		await this.send(INVITATION, invitation);

		return Object.freeze(invitation);
	}

	async respond(invitation, answer)
	{
		this.requireConnected();

		if(!['accepted','rejected'].includes(answer)) throw new TypeError('Invalid invitation response');

		if(invitation.to !== this.userId)
		throw new MatrixLobbyError('invite_recipient', 'Invitation is for another user');

		if(invitation.expiresAt <= Date.now()) throw new MatrixLobbyError('invitation_expired', 'Invitation expired');

		await this.send(INVITATION_STATE, {
			id: invitation.id
			, from: this.userId
			, to: invitation.from
			, state: answer
			, expiresAt: invitation.expiresAt
		});

		this.dispatchEvent(event('invitationstate', {id: invitation.id, state: answer}));
	}

	async cancel(invitationId, peerId)
	{
		this.requireConnected();
		const invitation = this.invitations.get(invitationId);

		if(!invitation || invitation.state !== 'pending') return;

		invitation.state = 'cancelled';

		await this.send(INVITATION_STATE, {
			id: invitationId
			, from: this.userId
			, to: peer(peerId)
			, state: 'cancelled'
			, expiresAt: invitation.expiresAt
		});
	}

	async signalPeer(peerId, payload)
	{
		this.requireConnected();

		await this.send(SIGNAL, {
			id: crypto.randomUUID()
			, from: this.userId
			, to: peer(peerId)
			, payload: structuredClone(payload)
			, sentAt: Date.now()
		});
	}

	async chat(text)
	{
		this.requireConnected();
		text = String(text).trim();

		if(!text || text.length > 1000) throw new MatrixLobbyError('chat_message', 'Chat message is empty or too long');

		const sentAt = Date.now();
		await this.send(CHAT, {text, sentAt});
		this.dispatchEvent(event('chat', {from: this.userId, text, sentAt}));
	}

	async announcePresence()
	{
		this.requireConnected();

		await this.send(PRESENCE, {
			peer: this.userId
			, displayName: this.displayName
			, expiresAt: Date.now() + this.presenceTtl
			, protocol: '1.0.0'
		});
	}

	async syncOnce()
	{
		this.requireConnected();
		const query = new URLSearchParams({timeout: String(this.syncTimeout)});

		if(this.since) query.set('since', this.since);

		const result = await this.request('GET', `/_matrix/client/v3/sync?${query}`);
		this.since = result.next_batch ?? this.since;
		const timeline = result.rooms?.join?.[this.roomId]?.timeline?.events ?? [];

		for(const item of timeline) this.consume(item);

		this.expirePresence();

		return result;
	}

	async syncLoop()
	{
		while(!this.closed && !this.signal.aborted) await this.syncOnce();
	}

	consume(item)
	{
		if(!item || typeof item.content !== 'object' || item.sender === this.userId) return;

		const content = item.content;

		if(item.type === PRESENCE)
		{
			if(content.peer !== item.sender || !Number.isFinite(content.expiresAt)) return;

			this.peers.set(item.sender, {
				id: item.sender
				, displayName: String(content.displayName ?? item.sender)
				, expiresAt: content.expiresAt
			});

			this.publishPresence();
		}
		else if(item.type === INVITATION)
		{
			if(content.from !== item.sender || content.to !== this.userId || content.expiresAt <= Date.now()) return;

			this.dispatchEvent(event('invitation', {invitation: Object.freeze(structuredClone(content))}));
		}
		else if(item.type === INVITATION_STATE)
		{
			if(content.from !== item.sender || content.to !== this.userId) return;

			const invitation = this.invitations.get(content.id);

			if(!invitation || invitation.state !== 'pending' || content.expiresAt !== invitation.expiresAt) return;

			invitation.state = content.state;
			this.dispatchEvent(event('invitationstate', {id: content.id, state: content.state}));
		}
		else if(item.type === SIGNAL)
		{
			if(content.from !== item.sender || content.to !== this.userId) return;

			this.dispatchEvent(event('signal', {from: item.sender, payload: structuredClone(content.payload)}));
		}
		else if(item.type === CHAT)
		{
			if(typeof content.text !== 'string' || content.text.length > 1000) return;

			this.dispatchEvent(event('chat', {from: item.sender, text: content.text, sentAt: content.sentAt}));
		}
	}

	expirePresence(now = Date.now())
	{
		let changed = false;

		for(const [id, value] of this.peers)
		if(value.expiresAt <= now)
		{
			this.peers.delete(id);
			changed = true;
		}

		if(changed) this.publishPresence();
	}

	publishPresence()
	{
		this.dispatchEvent(event('presence', {
			peers: [this.profile(), ...[...this.peers.values()].map(({expiresAt, ...value}) => value)]
		}));
	}

	async send(type, content)
	{
		const transaction = `${Date.now()}-${++this.transaction}`;

		return this.request(
			'PUT'
			, `/_matrix/client/v3/rooms/${encodeURIComponent(
				this.roomId
			)}/send/${encodeURIComponent(
				type
			)}/${transaction}`
			, {body: content}
		);
	}

	async request(method, path, options = {})
	{
		return this.requestUrl(method, new URL(path, this.homeserver), options);
	}

	async requestUrl(method, target, {body, form, auth = true, retry = true} = {})
	{
		this.signal.throwIfAborted();
		const headers = {'Accept': 'application/json'};

		if(body !== undefined) headers['Content-Type'] = 'application/json';
		else if(form !== undefined) headers['Content-Type'] = 'application/x-www-form-urlencoded';

		if(auth)
		{
			await this.ensureAccessToken();

			if(!this.accessToken) throw new MatrixLobbyError('matrix_auth_required', 'Matrix login is required');

			headers.Authorization = `Bearer ${this.accessToken}`;
		}

		let response;

		try
		{
			response = await this.fetch(new URL(target, this.homeserver), {
				method
				, headers
				, body: body !== undefined
					? JSON.stringify(body)
					: form === undefined ? undefined : new URLSearchParams(form)
				, signal: this.signal
				, redirect: 'error'
			});
		}
		catch(error)
		{
			throw new MatrixLobbyError(
				'matrix_transport'
				, `Matrix request failed: ${method} ${target}`
				, {cause: error}
			);
		}

		let value = {};

		try { value = await response.json(); } catch {}

		if(response.status === 401 && auth && retry && this.refreshToken)
		{
			await this.refreshAccessToken();

			return this.requestUrl(method, target, {body, form, auth, retry: false});
		}

		if(!response.ok)
		throw new MatrixLobbyError(
			value.errcode ?? value.error ?? 'matrix_http'
			, value.error_description ?? value.error ?? `Matrix HTTP ${response.status}`
			, {status: response.status, details: value}
		);

		return value;
	}

	async ensureAccessToken()
	{
		if(this.authApi === 'oauth' && this.refreshToken && this.expiresAt <= Date.now() + 30_000)
		await this.refreshAccessToken();
	}

	async refreshAccessToken()
	{
		if(this.refreshTask) return this.refreshTask;

		if(!this.refreshToken || !this.clientId)
		throw new MatrixLobbyError('matrix_auth_required', 'Matrix OAuth session must be renewed');

		this.refreshTask = (async () => {
			let endpoint = this.tokenEndpoint;

			if(!endpoint)
			{
				const metadata = await this.oauthMetadata();
				endpoint = metadata.token_endpoint;
				this.revocationEndpoint = metadata.revocation_endpoint;
			}

			const result = await this.requestUrl('POST', endpoint, {
				auth: false
				, form: {
					grant_type: 'refresh_token'
					, refresh_token: this.refreshToken
					, client_id: this.clientId
				}
			});

			if(!result.access_token)
			throw new MatrixLobbyError('matrix_oauth_refresh', 'Matrix OAuth refresh returned no access token');

			this.accessToken = result.access_token;
			this.refreshToken = result.refresh_token ?? this.refreshToken;
			this.expiresAt = Date.now() + Math.max(1, Number(result.expires_in ?? 300)) * 1000;
			this.tokenEndpoint = endpoint;
			this.save();
		})();

		try
		{
			await this.refreshTask;
		}
		finally
		{
			this.refreshTask = null;
		}
	}

	useSession(result)
	{
		if(!result.access_token || !result.user_id)
		throw new MatrixLobbyError('matrix_login', 'Matrix login returned no session');

		this.accessToken = result.access_token;
		this.userId = peer(result.user_id);
		this.deviceId = result.device_id ?? null;
		this.displayName ??= this.userId;
		this.save();

		return this.profile();
	}

	useOAuthSession(result, pending)
	{
		if(!result.access_token || !result.refresh_token)
		throw new MatrixLobbyError('matrix_oauth_login', 'Matrix OAuth returned no renewable session');

		this.accessToken = result.access_token;
		this.refreshToken = result.refresh_token;
		this.expiresAt = Date.now() + Math.max(1, Number(result.expires_in ?? 300)) * 1000;
		this.clientId = pending.clientId;
		this.deviceId = pending.deviceId;
		this.tokenEndpoint = pending.tokenEndpoint;
		this.revocationEndpoint = pending.revocationEndpoint;
		this.authApi = 'oauth';
		this.save();

		return this.profile();
	}

	save()
	{
		try
		{
			this.storage?.setItem(this.storageKey, JSON.stringify({
				accessToken: this.accessToken
				, refreshToken: this.refreshToken
				, expiresAt: this.expiresAt
				, clientId: this.clientId
				, tokenEndpoint: this.tokenEndpoint
				, revocationEndpoint: this.revocationEndpoint
				, authApi: this.authApi
				, userId: this.userId
				, deviceId: this.deviceId
				, displayName: this.displayName
			}));
		}
		catch
		{
		}
	}

	logoutLocal()
	{
		this.accessToken = this.refreshToken = this.userId = this.deviceId = null;
		this.expiresAt = 0;

		try { this.storage?.removeItem(this.storageKey); } catch {}
	}

	close()
	{
		if(this.closed) return;

		this.closed = true;
		this.connected = false;
		clearInterval(this.heartbeatTimer);
		this.controller.abort(new MatrixLobbyError('lobby_closed', 'Matrix lobby closed'));
		this.peers.clear();
		this.invitations.clear();
	}

	requireConnected()
	{
		if(!this.connected || this.closed) throw new MatrixLobbyError('lobby_state', 'Matrix lobby is not connected');
	}
}

/** Register the provider when loaded as a verified Phonics browser export. */
export function activate({services, exportId})
{
	if(!services?.register) throw new Error('Phonics Matrix requires the browser service registry');

	services.register('lobby-provider', exportId, options => new MatrixLobbyProvider(options));

	return () => services.unregister('lobby-provider', exportId);
}
