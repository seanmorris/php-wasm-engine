import {NetworkError} from './Errors.mjs';

const event = (type, detail) => new CustomEvent(type, {detail});

const transitions = Object.freeze({
	idle: ['connecting','closed']
	, connecting: ['available','auth-required','failed','closed']
	, 'auth-required': ['connecting','failed','closed']
	, available: ['inviting','invited','negotiating','signing-out','closed','failed']
	, inviting: ['available','negotiating','closed','failed']
	, invited: ['available','negotiating','closed','failed']
	, negotiating: ['connected','available','closed','failed']
	, connected: ['loading','ready','available','closed','failed']
	, loading: ['ready','available','closed','failed']
	, ready: ['starting','available','closed','failed']
	, starting: ['playing','available','closed','failed']
	, playing: ['available','closed','failed']
	, 'signing-out': ['auth-required','failed','closed']
	, failed: ['connecting','available','closed']
	, closed: []
});

/**
 * Finite lobby/invitation coordinator independent of Matrix and WebRTC classes.
 *
 * It serializes authentication, discovery, invitation, private-link, resource, signaling, match,
 * cancellation, and sign-out transitions behind observable snapshots.
 */
export class LobbyController extends EventTarget
{
	/** Lobby and signaling provider used by this controller. */
	provider;

	/** Factory that creates a transport for an accepted match. */
	transportFactory;

	/** Callback that prepares local match data for an invitation. */
	prepareInvitation;

	/** Negotiated protocol compatibility record. */
	protocol;

	/** Abort controller governing the lifetime of lobby operations. */
	controller;

	/** Abort signal that cancels asynchronous work owned here. */
	signal;

	/** Current lobby controller state. */
	state;

	/** Discovered lobby peers in provider order. */
	peers;

	/** Known invitations indexed by invitation identifier. */
	invitations;

	/** Invitation currently offered to another peer. */
	outgoing;

	/** Match metadata accepted for the active peer connection. */
	match;

	/** Active network transport used by this session or bridge. */
	transport;

	/** Signaling messages waiting for transport creation. */
	signalQueue;

	/** Whether the instance is closed and rejects further work. */
	closed;

	/** Detected private-link support state for the active provider. */
	inviteLinkSupport;

	/** Pending capability probe for private invitation links. */
	inviteLinkSupportTask;

	/** Authenticated lobby profile for the local peer. */
	profile;

	/** Pending provider logout operation, if one is running. */
	logoutTask;

	/** Result of the most recently completed provider logout. */
	logoutResult;

	/** Current private-link join attempt, including cancellation state. */
	joinAttempt;

	/** Invitation currently being accepted, if any. */
	activeInvitation;

	/** Locally prepared resource payload for the invitation being accepted. */
	acceptPayload;

	/** Pending cleanup of provider state for the finished match. */
	matchCleanup;

	/** Shared promise for the in-progress controller shutdown. */
	closeTask;


	/**
	 * Create an idle controller and subscribe to the provider's lobby events.
	 * @param {object} options Controller settings.
	 * @param {EventTarget} options.provider Lobby provider; any non-EventTarget throws TypeError.
	 * @param {function(object): object} options.transportFactory Receives polite, signal and sendSignal; returns a
	 * peer transport.
	 * @param {function(object): Promise<{publicPayload?: object, privatePayload?: *}>} [options.prepareInvitation]
	 * Splits an invitation payload; by default the whole payload is public.
	 * @param {AbortSignal} [options.signal] Closes the controller when aborted.
	 * @param {string} [options.protocol] Protocol version required of invitations; defaults to 1.0.0.
	 */
	constructor(
		{
			provider
			, transportFactory
			, prepareInvitation = async value => ({publicPayload: value})
			, signal
			, protocol = '1.0.0'
		} = {}
	){
		super();

		if(!(provider instanceof EventTarget)) throw new TypeError('LobbyController requires a provider');

		if(typeof transportFactory !== 'function') throw new TypeError('LobbyController requires a transport factory');

		this.provider = provider;
		this.transportFactory = transportFactory;
		this.prepareInvitation = prepareInvitation;
		this.protocol = protocol;
		this.controller = new AbortController;
		this.signal = signal ? AbortSignal.any([signal, this.controller.signal]) : this.controller.signal;
		this.state = 'idle';
		this.peers = [];
		this.invitations = new Map;
		this.outgoing = null;
		this.match = null;
		this.transport = null;
		this.signalQueue = [];
		this.closed = false;
		this.inviteLinkSupport = typeof provider.createInviteLink === 'function' ? 'checking' : 'unsupported';
		this.inviteLinkSupportTask = null;
		this.logoutResult = null;
		this.install();
	}

	/**
	 * Connect the provider and enter the available state.
	 * @returns {Promise<object>} Local profile from the provider.
	 * @throws {*} The provider failure; matrix_auth_required moves to auth-required, others to failed.
	 */
	async connect()
	{
		this.move('connecting');

		try
		{
			const profile = await this.provider.connect();
			this.profile = profile;
			this.move('available', {profile});
			void this.checkInviteLinkSupport();

			return profile;
		}
		catch(error)
		{
			if(error?.code === 'matrix_auth_required') this.move('auth-required', {error});
			else this.fail(error);

			throw error;
		}
	}

	/**
	 * Start provider OAuth for the given return URL.
	 * @param {string} returnUrl Redirect URL passed to the provider.
	 * @returns {Promise<*>} Provider beginOAuth() result.
	 * @throws {NetworkError} lobby_auth when the provider has no OAuth support.
	 */
	async beginOAuth(returnUrl)
	{
		if(typeof this.provider.beginOAuth !== 'function')
		throw new NetworkError('lobby_auth', 'Selected lobby provider does not support Matrix OAuth');

		return this.provider.beginOAuth(returnUrl);
	}

	/**
	 * Complete provider OAuth from a callback URL, then connect.
	 * @param {string} callbackUrl Relayed authorization callback URL.
	 * @returns {Promise<object>} Local profile from connect().
	 * @throws {NetworkError} lobby_auth when unsupported or when no authorization response was found.
	 */
	async completeOAuth(callbackUrl)
	{
		if(typeof this.provider.completeOAuth !== 'function')
		throw new NetworkError('lobby_auth', 'Selected lobby provider cannot complete Matrix OAuth');

		const completed = await this.provider.completeOAuth(callbackUrl, {replaceHistory: false});

		if(!completed) throw new NetworkError('lobby_auth', 'Matrix OAuth returned no authorization response');

		return this.connect();
	}

	/**
	 * Replace the provider configuration while idle, awaiting authorization, or failed, then connect.
	 * @param {object} configuration Provider-specific configuration.
	 * @returns {Promise<object>} Local profile from connect().
	 * @throws {NetworkError} lobby_config when unsupported; lobby_state in any other state.
	 */
	async configure(configuration)
	{
		if(typeof this.provider.configure !== 'function')
		throw new NetworkError('lobby_config', 'Selected lobby provider cannot change configuration');

		if(!['idle','auth-required','failed'].includes(this.state))
		throw new NetworkError('lobby_state', `Cannot change lobby configuration while ${this.state}`);

		this.provider.configure(configuration);
		this.inviteLinkSupport = typeof this.provider.createInviteLink === 'function' ? 'checking' : 'unsupported';
		this.inviteLinkSupportTask = null;

		return this.connect();
	}

	/** Detect optional private-link support without failing the rest of the lobby. */
	async checkInviteLinkSupport({refresh = false} = {})
	{
		if(typeof this.provider.createInviteLink !== 'function')
		{
			this.inviteLinkSupport = 'unsupported';

			return false;
		}

		if(typeof this.provider.inviteLinkCapability !== 'function')
		{
			this.inviteLinkSupport = 'supported';
			this.dispatchEvent(event('update', this.snapshot()));

			return true;
		}

		if(!refresh && this.inviteLinkSupportTask) return this.inviteLinkSupportTask;

		this.inviteLinkSupport = 'checking';
		const homeserver = this.provider.homeserver;

		this.inviteLinkSupportTask = (async () => {
			let supported = false;

			try
			{
				supported = Boolean((await this.provider.inviteLinkCapability({refresh})).supported);
			}
			catch
			{
			}

			if(!this.closed && this.provider.homeserver === homeserver)
			{
				this.inviteLinkSupport = supported ? 'supported' : 'unsupported';
				this.dispatchEvent(event('update', this.snapshot()));
			}

			return supported;
		})();

		try
		{
			return await this.inviteLinkSupportTask;
		}
		finally
		{
			this.inviteLinkSupportTask = null;
		}
	}

	/**
	 * Log the provider in with a token, then connect.
	 * @param {string} token Provider login token.
	 * @returns {Promise<object>} Local profile from connect().
	 * @throws {NetworkError} lobby_auth when the provider has no token login.
	 */
	async loginToken(token)
	{
		if(typeof this.provider.loginToken !== 'function')
		throw new NetworkError('lobby_auth', 'Selected lobby provider does not support token login');

		await this.provider.loginToken(token);

		return this.connect();
	}

	/**
	 * End the authenticated provider session while no invitation or match is active.
	 * @returns {Promise<*>} Provider logout result.
	 * @throws {NetworkError} lobby_auth when unsupported; lobby_state unless available.
	 */
	async logout()
	{
		if(this.logoutTask) return this.logoutTask;

		if(typeof this.provider.logout !== 'function')
		throw new NetworkError('lobby_auth', 'Selected lobby provider does not support sign-out');

		this.requireState('available');
		this.move('signing-out');

		this.logoutTask = (async () => {
			try
			{
				const result = await this.provider.logout();
				this.logoutResult = result;
				this.profile = null;
				this.peers = [];
				this.invitations.clear();
				this.outgoing = null;
				this.inviteLinkSupport = typeof this.provider.createInviteLink === 'function'
					? 'checking'
					: 'unsupported';

				this.inviteLinkSupportTask = null;
				this.move('auth-required', {logout: result});

				return result;
			}
			catch(error)
			{
				this.fail(error);

				throw error;
			}
		})();

		try
		{
			return await this.logoutTask;
		}
		finally
		{
			this.logoutTask = null;
		}
	}

	/**
	 * Invite one peer while available; the public payload gains a session ID and protocol version.
	 * @param {string} peerId Invited peer ID.
	 * @param {object} [payload] Payload passed through prepareInvitation().
	 * @returns {Promise<object>} Provider invitation.
	 */
	async invite(peerId, payload = {})
	{
		this.requireState('available');
		const sessionId = crypto.randomUUID();
		const prepared = await this.prepareInvitation(structuredClone(payload));

		const invitation = await this.provider.invite(peerId, {
			...structuredClone(prepared.publicPayload ?? {})
			, sessionId
			, protocol: this.protocol
		});

		this.outgoing = {
			invitation
			, peerId
			, sessionId
			, publicPayload: structuredClone(prepared.publicPayload ?? {})
			, privatePayload: prepared.privatePayload
		};

		this.move('inviting', {invitation});

		return invitation;
	}

	/** Create a single-use private link using an optional provider capability. */
	async createInviteLink(selection = {})
	{
		this.requireState('available', 'inviting');

		if(typeof this.provider.createInviteLink !== 'function')
		throw new NetworkError('invite_unsupported', 'This provider cannot create private links');

		if(!await this.checkInviteLinkSupport())
		throw new NetworkError('invite_unsupported', 'This homeserver does not support anonymous invitation links');

		if(this.outgoing) await this.cancel();

		const prepared = await this.prepareInvitation(selection);
		const sessionId = crypto.randomUUID();
		const payload = {...prepared.publicPayload, sessionId, protocol: this.protocol, match: prepared.privatePayload};
		const invitation = await this.provider.createInviteLink(payload);
		this.outgoing = {invitation, peerId: null, sessionId, privatePayload: prepared.privatePayload};
		this.move('inviting', {invitation});

		return invitation;
	}

	/** Register/admit only after explicit activation, then resolve resources before SDP. */
	async joinInviteLink(invitation, prepare = async () => ({}))
	{
		this.requireState('idle', 'failed');

		if(typeof this.provider.joinInviteLink !== 'function')
		throw new NetworkError('invite_unsupported', 'This provider cannot join private links');

		this.move('connecting');
		const attempt = new AbortController;
		this.joinAttempt = attempt;

		try
		{
			const admitted = await this.provider.joinInviteLink(invitation);
			attempt.signal.throwIfAborted();
			this.profile = this.provider.profile();
			this.move('available');
			const local = await prepare(admitted, attempt.signal);
			attempt.signal.throwIfAborted();

			return await this.accept(admitted, local);
		}
		catch(error)
		{
			await this.provider.cancelInviteLink?.();

			if(!this.closed) this.fail(error);

			throw error;
		}
		finally
		{
			if(this.joinAttempt === attempt) this.joinAttempt = null;
		}
	}

	/** Cancel the active private invitation and return to ordinary lobby discovery. */
	async cancelInviteLink()
	{
		this.joinAttempt?.abort(new NetworkError('invite_cancelled', 'Invitation cancelled'));
		const cleanup = this.provider.cancelInviteLink?.();
		this.outgoing = null;

		if(!this.closed && transitions[this.state]?.includes('available')) this.move('available');

		return cleanup;
	}

	/**
	 * Accept an invitation and negotiate a non-initiating peer transport.
	 * @param {object} invitation Received invitation.
	 * @param {object} [localPayload] Local match data retained as match.localPayload.
	 * @returns {Promise<object|null>} Started transport, or null when superseded or closed meanwhile.
	 * @throws {NetworkError} lobby_state unless available or invited; protocol_version on a mismatch.
	 */
	async accept(invitation, localPayload = {})
	{
		if(!['available','invited'].includes(this.state))
		throw new NetworkError('lobby_state', `Cannot accept invitation while ${this.state}`);

		if(invitation.payload?.protocol !== this.protocol)
		throw new NetworkError('protocol_version', `Invitation requires protocol ${invitation.payload?.protocol}`);

		this.activeInvitation = invitation;
		this.acceptPayload = localPayload;
		await this.provider.respond(invitation, 'accepted');

		return this.connectPeer({
			peerId: invitation.from
			, sessionId: invitation.payload.sessionId
			, initiator: false
			, invitation
		});
	}

	/**
	 * Reject an invitation and return to the available state.
	 * @param {object} invitation Received invitation.
	 * @returns {Promise<void>}
	 */
	async reject(invitation)
	{
		await this.provider.respond(invitation, 'rejected');
		this.invitations.delete(invitation.id);
		this.move('available', {invitation});
	}

	/**
	 * Cancel the outgoing invitation, if any, and return to the available state.
	 * @returns {Promise<void>}
	 */
	async cancel()
	{
		if(!this.outgoing) return;

		if(this.outgoing.invitation.privateLink) return this.cancelInviteLink();

		await this.provider.cancel(this.outgoing.invitation.id, this.outgoing.peerId);
		this.outgoing = null;
		this.move('available');
	}

	/**
	 * Enter the loading state.
	 * @param {object} [detail] Extra fields for the statechange snapshot.
	 * @returns {void}
	 */
	markLoading(detail = {}) { this.move('loading', detail); }

	/**
	 * Enter the ready state.
	 * @param {object} [detail] Extra fields for the statechange snapshot.
	 * @returns {void}
	 */
	markReady(detail = {}) { this.move('ready', detail); }

	/**
	 * Enter the starting state.
	 * @param {object} [detail] Extra fields for the statechange snapshot.
	 * @returns {void}
	 */
	markStarting(detail = {}) { this.move('starting', detail); }

	/**
	 * Enter the playing state.
	 * @param {object} [detail] Extra fields for the statechange snapshot.
	 * @returns {void}
	 */
	markPlaying(detail = {}) { this.move('playing', detail); }

	/**
	 * Close the current transport and match data, returning to available when that transition is allowed.
	 * @param {string} [reason] Close reason; defaults to left match.
	 * @param {object} [options] Close options.
	 * @param {boolean} [options.local] Reported in the matchend event detail.
	 * @returns {void} Dispatches matchend when a transport or match existed.
	 */
	closeMatch(reason = 'left match', {local = false} = {})
	{
		this.joinAttempt?.abort(new NetworkError('invite_cancelled', reason));
		this.matchCleanup = this.provider.cancelInviteLink?.(reason);
		const transport = this.transport, match = this.match;
		this.transport = null;
		this.match = null;
		this.activeInvitation = null;
		this.acceptPayload = null;
		this.outgoing = null;
		this.signalQueue = [];
		transport?.close(reason);

		if(!this.closed && transitions[this.state]?.includes('available')) this.move('available', {reason});

		if(transport || match) this.dispatchEvent(event('matchend', {match, reason, local}));
	}

	/**
	 * Close the match, abort the controller signal, close the provider, and enter the closed state once.
	 * @returns {void}
	 */
	close()
	{
		if(this.closed) return this.closeTask;

		this.closed = true;
		this.closeMatch('lobby closed');
		this.controller.abort(new NetworkError('lobby_closed', 'Lobby controller closed'));
		this.closeTask = Promise.allSettled([this.matchCleanup, this.provider.close?.()]);
		this.transport = null;
		this.peers = [];
		this.invitations.clear();
		this.state = 'closed';
		this.dispatchEvent(event('statechange', this.snapshot()));

		return this.closeTask;
	}

	/**
	 * Describe the current lobby state.
	 * @param {object} [extra] Fields merged into the snapshot.
	 * @returns {object} Frozen state, profile, peers, invitations, outgoing invitation, and match.
	 */
	snapshot(extra = {})
	{
		return Object.freeze({
			state: this.state
			, profile: this.profile ?? null
			, peers: [...this.peers]
			, invitations: [...this.invitations.values()]
			, outgoing: this.outgoing
			, match: this.match
			, inviteLinkSupport: this.inviteLinkSupport
			, logoutResult: this.logoutResult
			, ...extra
		});
	}

	/**
	 * Subscribe to provider presence, invitation, signal, chat, and error events and to the abort signal.
	 * @returns {void}
	 */
	install()
	{
		this.provider.addEventListener('presence', ({detail}) => {
			this.peers = (detail.peers ?? []).filter(peer => peer.id !== this.profile?.id);
			this.dispatchEvent(event('update', this.snapshot()));
		});

		this.provider.addEventListener('invitation', ({detail}) => {
			const invitation = detail.invitation;
			this.invitations.set(invitation.id, invitation);

			if(this.state === 'available') this.move('invited', {invitation});
			else this.dispatchEvent(event('update', this.snapshot({invitation})));
		});

		this.provider.addEventListener('invitationstate', ({detail}) => {
			this.invitationState(detail).catch(error => this.fail(error));
		});

		this.provider.addEventListener('signal', ({detail}) => {
			this.receiveSignal(detail).catch(error => this.fail(error));
		});

		this.provider.addEventListener('chat', ({detail}) => this.dispatchEvent(event('chat', detail)));
		this.provider.addEventListener('error', ({detail}) => this.fail(detail));

		for(const type of ['invitestatus', 'cleanupstatus', 'signalingstatus'])
		this.provider.addEventListener(type, ({detail}) => this.dispatchEvent(event(type, detail)));

		this.provider.addEventListener('invitestatus', ({detail}) => {
			if(detail.state !== 'closed') return;

			this.joinAttempt?.abort(new NetworkError('invite_cancelled', detail.reason));

			if(this.match) this.closeMatch(detail.reason);
			else if(this.outgoing?.invitation.privateLink)
			{
				this.outgoing = null;

				if(!this.closed && transitions[this.state]?.includes('available'))
				this.move('available', {reason: detail.reason});
			}
		});

		this.provider.addEventListener('inviteclosed', ({detail}) => this.closeMatch(detail.reason));
		this.signal.addEventListener('abort', () => this.close(), {once: true});
	}

	/**
	 * Apply a state change for the outgoing invitation; acceptance starts the initiating transport.
	 * @param {{id: string, state: string}} detail Invitation ID and new state.
	 * @returns {Promise<void>}
	 */
	async invitationState(detail)
	{
		if(!this.outgoing || detail.id !== this.outgoing.invitation.id) return;

		if(detail.state === 'accepted')
		{
			const outgoing = this.outgoing;

			if(outgoing.invitation.privateLink) outgoing.peerId = detail.peerId;

			await this.connectPeer({
				peerId: outgoing.peerId
				, sessionId: outgoing.sessionId
				, initiator: true
				, invitation: outgoing.invitation
			});
		}
		else
		{
			this.outgoing = null;
			this.move('available', {invitationState: detail.state});
		}
	}

	/**
	 * Create and start the peer transport for one match, replaying queued signals for that session.
	 * @param {object} match Match identity.
	 * @param {string} match.peerId Remote peer ID.
	 * @param {string} match.sessionId Match session ID.
	 * @param {boolean} match.initiator Whether this peer sent the invitation.
	 * @param {object} match.invitation Invitation that created the match.
	 * @returns {Promise<object|null>} Started transport, or null when superseded or closed meanwhile.
	 * @throws {NetworkError} lobby_match when a transport is already active.
	 */
	async connectPeer({peerId, sessionId, initiator, invitation})
	{
		if(this.transport) throw new NetworkError('lobby_match', 'A peer transport is already active');

		this.match = Object.freeze({
			peerId
			, sessionId
			, initiator
			, invitation
			, privatePayload: initiator ? this.outgoing?.privatePayload : null
			, localPayload: initiator ? null : this.acceptPayload
		});

		this.move('negotiating', {match: this.match});

		const transport = await this.transportFactory({
			polite: !initiator
			, signal: this.signal
			, sendSignal: payload => this.provider.signalPeer(peerId, {sessionId, payload})
		});

		if(this.closed || this.match?.sessionId !== sessionId)
		{
			transport.close();

			return null;
		}

		this.transport = transport;

		transport.addEventListener('recovery', ({detail}) => {
			if(this.transport === transport && !this.closed) this.dispatchEvent(event('recovery', detail));
		});

		transport.addEventListener('message', ({detail}) => {
			if(this.transport === transport && !this.closed) this.dispatchEvent(event('message', detail));
		});

		transport.addEventListener('error', ({detail}) => {
			if(this.transport === transport && !this.closed) this.fail(detail);
		});

		transport.addEventListener('close', ({detail}) => {
			if(this.transport === transport && !this.closed) this.closeMatch(detail.reason);
		});

		try
		{
			for(const value of this.signalQueue.splice(0))
			if(value.from === peerId && value.payload.sessionId === sessionId)
			{
				await transport.receiveSignal(value.payload.payload);

				if(this.closed || this.transport !== transport) return null;
			}

			await transport.start({initiator});

			if(this.closed || this.transport !== transport) return null;

			this.move('connected', {match: this.match});
			this.dispatchEvent(event('transport', {transport, match: this.match}));

			return transport;
		}
		catch(error)
		{
			if(this.closed || this.transport !== transport) return null;

			this.fail(error);
			throw error;
		}
	}

	/**
	 * Forward a signal to the matching transport, or queue up to 128 signals until it exists.
	 * @param {{from: string, payload: object}} detail Sender and session-tagged signalling payload.
	 * @returns {Promise<void>}
	 */
	async receiveSignal(detail)
	{
		if(this.closed) return;

		const value = {from: detail.from, payload: detail.payload};

		if(!this.transport || !this.match
			|| detail.from !== this.match.peerId
			|| detail.payload?.sessionId !== this.match.sessionId
		){
			this.signalQueue.push(value);

			if(this.signalQueue.length > 128) this.signalQueue.shift();

			return;
		}

		const transport = this.transport;

		try
		{
			await transport.receiveSignal(detail.payload.payload);
		}
		catch(error)
		{
			if(this.closed || this.transport !== transport) return;

			throw error;
		}
	}

	/**
	 * Enter a state allowed by the transition table, or dispatch update when already in it.
	 * @param {string} next Target state.
	 * @param {object} [detail] Extra fields for the event snapshot.
	 * @returns {void}
	 * @throws {NetworkError} lobby_transition for a disallowed transition.
	 */
	move(next, detail = {})
	{
		if(this.state === next)
		{
			this.dispatchEvent(event('update', this.snapshot(detail)));

			return;
		}

		if(!transitions[this.state]?.includes(next))
		throw new NetworkError('lobby_transition', `Invalid lobby transition ${this.state} -> ${next}`);

		this.state = next;
		this.dispatchEvent(event('statechange', this.snapshot(detail)));
	}

	/**
	 * Enter the failed state when allowed; otherwise dispatch an error event.
	 * @param {*} error Failure reported to listeners.
	 * @returns {void}
	 */
	fail(error)
	{
		if(this.closed) return;

		if(this.match || this.provider.linkState) this.closeMatch(error.message ?? 'Connection failed');

		if(this.state !== 'failed' && transitions[this.state]?.includes('failed')) this.move('failed', {error});
		else this.dispatchEvent(event('error', error));
	}

	/**
	 * Require one of the listed states.
	 * @param {...string} states Allowed states.
	 * @returns {void}
	 * @throws {NetworkError} lobby_state otherwise.
	 */
	requireState(...states)
	{
		if(!states.includes(this.state)) throw new NetworkError('lobby_state', `Lobby is ${this.state}`);
	}
}
