import {NetworkError} from './Errors.mjs';

const event = (type, detail) => new CustomEvent(type, {detail});

const transitions = Object.freeze({
	idle: ['connecting','closed']
	, connecting: ['available','auth-required','failed','closed']
	, 'auth-required': ['connecting','failed','closed']
	, available: ['inviting','invited','negotiating','closed','failed']
	, inviting: ['available','negotiating','closed','failed']
	, invited: ['available','negotiating','closed','failed']
	, negotiating: ['connected','available','closed','failed']
	, connected: ['loading','ready','available','closed','failed']
	, loading: ['ready','available','closed','failed']
	, ready: ['starting','available','closed','failed']
	, starting: ['playing','available','closed','failed']
	, playing: ['available','closed','failed']
	, failed: ['connecting','available','closed']
	, closed: []
});

/** Finite lobby/invitation coordinator independent of Matrix and WebRTC classes. */
export class LobbyController extends EventTarget
{

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
		this.install();
	}

	async connect()
	{
		this.move('connecting');

		try
		{
			const profile = await this.provider.connect();
			this.profile = profile;
			this.move('available', {profile});

			return profile;
		}
		catch(error)
		{
			if(error?.code === 'matrix_auth_required') this.move('auth-required', {error});
			else this.fail(error);

			throw error;
		}
	}

	async beginOAuth(returnUrl)
	{
		if(typeof this.provider.beginOAuth !== 'function')
		throw new NetworkError('lobby_auth', 'Selected lobby provider does not support Matrix OAuth');

		return this.provider.beginOAuth(returnUrl);
	}

	async completeOAuth(callbackUrl)
	{
		if(typeof this.provider.completeOAuth !== 'function')
		throw new NetworkError('lobby_auth', 'Selected lobby provider cannot complete Matrix OAuth');

		const completed = await this.provider.completeOAuth(callbackUrl, {replaceHistory: false});

		if(!completed) throw new NetworkError('lobby_auth', 'Matrix OAuth returned no authorization response');

		return this.connect();
	}

	async configure(configuration)
	{
		if(typeof this.provider.configure !== 'function')
		throw new NetworkError('lobby_config', 'Selected lobby provider cannot change configuration');

		if(!['idle','auth-required','failed'].includes(this.state))
		throw new NetworkError('lobby_state', `Cannot change lobby configuration while ${this.state}`);

		this.provider.configure(configuration);

		return this.connect();
	}

	async loginToken(token)
	{
		if(typeof this.provider.loginToken !== 'function')
		throw new NetworkError('lobby_auth', 'Selected lobby provider does not support token login');

		await this.provider.loginToken(token);

		return this.connect();
	}

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

	async reject(invitation)
	{
		await this.provider.respond(invitation, 'rejected');
		this.invitations.delete(invitation.id);
		this.move('available', {invitation});
	}

	async cancel()
	{
		if(!this.outgoing) return;

		await this.provider.cancel(this.outgoing.invitation.id, this.outgoing.peerId);
		this.outgoing = null;
		this.move('available');
	}

	markLoading(detail = {}) { this.move('loading', detail); }

	markReady(detail = {}) { this.move('ready', detail); }

	markStarting(detail = {}) { this.move('starting', detail); }

	markPlaying(detail = {}) { this.move('playing', detail); }

	closeMatch(reason = 'left match', {local = false} = {})
	{
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

	close()
	{
		if(this.closed) return;

		this.closed = true;
		this.closeMatch('lobby closed');
		this.controller.abort(new NetworkError('lobby_closed', 'Lobby controller closed'));
		this.provider.close?.();
		this.transport = null;
		this.peers = [];
		this.invitations.clear();
		this.state = 'closed';
		this.dispatchEvent(event('statechange', this.snapshot()));
	}

	snapshot(extra = {})
	{
		return Object.freeze({
			state: this.state
			, profile: this.profile ?? null
			, peers: [...this.peers]
			, invitations: [...this.invitations.values()]
			, outgoing: this.outgoing
			, match: this.match
			, ...extra
		});
	}

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
		this.signal.addEventListener('abort', () => this.close(), {once: true});
	}

	async invitationState(detail)
	{
		if(!this.outgoing || detail.id !== this.outgoing.invitation.id) return;

		if(detail.state === 'accepted')
		{
			const outgoing = this.outgoing;

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

		const transport = this.transportFactory({
			polite: !initiator
			, signal: this.signal
			, sendSignal: payload => this.provider.signalPeer(peerId, {sessionId, payload})
		});

		this.transport = transport;

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

	fail(error)
	{
		if(this.closed) return;

		if(this.state !== 'failed' && transitions[this.state]?.includes('failed')) this.move('failed', {error});
		else this.dispatchEvent(event('error', error));
	}

	requireState(...states)
	{
		if(!states.includes(this.state)) throw new NetworkError('lobby_state', `Lobby is ${this.state}`);
	}
}
