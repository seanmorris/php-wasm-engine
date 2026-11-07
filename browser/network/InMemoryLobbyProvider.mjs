import {NetworkError} from './Errors.mjs';
import {PeerId} from './Values.mjs';

const event = (type, detail) => new CustomEvent(type, {detail});

/**
 * Shared deterministic room used by tests and offline examples.
 *
 * Multiple provider instances share this hub to exercise discovery, invitations, chat, and signaling
 * deterministically without accounts or external services.
 */
export class InMemoryLobbyHub
{
	/** Clock function used for deterministic timestamps and expiry. */
	now;

	/** In-memory lobby rooms indexed by room identifier. */
	rooms;


	/**
	 * Create an empty set of in-memory rooms.
	 * @param {object} [options] Hub settings.
	 * @param {function(): number} [options.now] Clock in milliseconds; defaults to Date.now().
	 */
	constructor({now = () => Date.now()} = {})
	{
		this.now = now;
		this.rooms = new Map;
		this.events = new Map;
		this.sequence = 0;
	}

	/**
	 * Add a provider to its room and announce presence to every member.
	 * @param {InMemoryLobbyProvider} provider Provider whose room and peer ID are joined.
	 * @returns {void}
	 * @throws {NetworkError} lobby_identity when the peer ID, or the same tab of it, is already in the room.
	 */
	join(provider)
	{
		const room = this.rooms.get(provider.room) ?? new Map;

		if(room.has(provider.member))
		throw new NetworkError('lobby_identity', `Peer already joined: ${provider.peerId}`);

		room.set(provider.member, provider);
		this.rooms.set(provider.room, room);
		this.presence(provider.room);
	}

	/**
	 * Remove a provider from its room, announcing presence to any remaining members.
	 * @param {InMemoryLobbyProvider} provider Provider leaving its room.
	 * @returns {void}
	 */
	leave(provider)
	{
		const room = this.rooms.get(provider.room);

		room?.delete(provider.member);

		if(!room?.size) this.rooms.delete(provider.room);
		else this.presence(provider.room);
	}

	/**
	 * Dispatch a presence event listing every member's profile to each member of a room.
	 * @param {string} roomId Room to announce.
	 * @returns {void}
	 */
	presence(roomId)
	{
		const room = this.rooms.get(roomId) ?? new Map;
		const peers = [...new Map([...room.values()].map(provider => [provider.peerId, provider.profile()])).values()];

		for(const provider of room.values()) provider.dispatchEvent(event('presence', {peers}));
	}

	/**
	 * Dispatch one event to a peer in the sender's room, in every tab where that peer is connected.
	 * @param {InMemoryLobbyProvider} sender Sending provider; its peer ID is added as detail.from.
	 * @param {string} target Receiving peer ID.
	 * @param {string} type Event type to dispatch.
	 * @param {object} detail Event detail fields.
	 * @returns {void}
	 * @throws {NetworkError} lobby_peer when the target is not in the room.
	 */
	deliver(sender, target, type, detail)
	{
		const providers = [...this.rooms.get(sender.room)?.values() ?? []].filter(value => value.peerId === target);

		if(!providers.length) throw new NetworkError('lobby_peer', `Peer is unavailable: ${target}`);

		for(const provider of providers) provider.dispatchEvent(event(type, {...detail, from: sender.peerId}));
	}

	/**
	 * Dispatch one event to every member of the sender's room, including the sender.
	 * @param {InMemoryLobbyProvider} sender Sending provider; its peer ID is added as detail.from.
	 * @param {string} type Event type to dispatch.
	 * @param {object} detail Event detail fields.
	 * @returns {void}
	 */
	broadcast(sender, type, detail)
	{
		for(const provider of this.rooms.get(sender.room)?.values() ?? [])
		provider.dispatchEvent(event(type, {...detail, from: sender.peerId}));
	}

	/** Store one durable room event and dispatch it to every connected member. */
	game(sender, content, transaction, type = 'org.seanmorris.phonics.game.v1')
	{
		const room = this.events.get(sender.room) ?? [];
		const prior = room.find(item => item.transaction === transaction && item.sender === sender.peerId);

		if(prior) return prior;

		const item = Object.freeze({
			event_id: `$memory-${++this.sequence}`
			, type
			, sender: sender.peerId
			, origin_server_ts: this.now()
			, content: structuredClone(content)
			, transaction
		});

		room.push(item);
		this.events.set(sender.room, room);
		this.broadcast(sender, 'gameevent', {event: item});

		return item;
	}

	/** Return durable events from a room in insertion order. */
	history(roomId) { return structuredClone(this.events.get(roomId) ?? []); }
}

/**
 * LobbyProvider implementation with no network or account dependency.
 *
 * The provider implements the lobby contract against an in-process hub, giving tests and offline examples
 * the same event shape as a remote service.
 */
export class InMemoryLobbyProvider extends EventTarget
{
	/** In-memory lobby hub shared by connected test providers. */
	hub;

	/** Validated identifier of the connected lobby peer. */
	peerId;

	/** Browser tab name, or null for a peer joined once. */
	tab;

	/** Room membership key: the peer ID, plus the tab when one is named. */
	member;

	/** Display name advertised to other lobby peers. */
	displayName;

	/** Lobby room identifier used by this provider. */
	room;

	/** Lifetime of newly created invitations in milliseconds. */
	invitationTtl;

	/** Abort signal that cancels asynchronous work owned here. */
	signal;

	/** Known invitations indexed by invitation identifier. */
	invitations;

	/** Whether this lobby provider is connected to its room. */
	connected;

	/** Whether the instance is closed and rejects further work. */
	closed;


	/**
	 * Create a disconnected provider for one peer in a shared hub.
	 * @param {object} options Provider settings.
	 * @param {InMemoryLobbyHub} options.hub Shared hub; any other value throws TypeError.
	 * @param {string} options.peerId Validated peer ID.
	 * @param {string} [options.displayName] Display name; defaults to the peer ID.
	 * @param {string} [options.room] Room name; defaults to phonics.
	 * @param {number} [options.invitationTtl] Invitation lifetime in milliseconds; defaults to 90,000.
	 * @param {AbortSignal} [options.signal] Closes the provider when aborted after connecting.
	 * @param {string} [options.tab] Names one browser tab, so several tabs of one peer ID can join the same room.
	 */
	constructor(
		{hub, peerId, displayName = peerId, room = 'phonics', invitationTtl = 90_000, signal, tab = null} = {}
	){
		super();

		if(!(hub instanceof InMemoryLobbyHub)) throw new TypeError('InMemoryLobbyProvider requires a hub');

		this.hub = hub;
		this.peerId = new PeerId(peerId).value;
		this.tab = tab === null ? null : String(tab);
		this.member = this.tab === null ? this.peerId : `${this.peerId}\u0000${this.tab}`;
		this.displayName = String(displayName);
		this.room = String(room);
		this.invitationTtl = invitationTtl;
		this.signal = signal;
		this.invitations = new Map;
		this.connected = false;
		this.closed = false;
		this.activity = null;
	}

	/**
	 * Describe this peer for presence lists.
	 * @returns {{id: string, displayName: string}} Frozen peer profile.
	 */
	profile() { return Object.freeze({id: this.peerId, displayName: this.displayName, activity: this.activity}); }

	/**
	 * Join the hub room once.
	 * @returns {Promise<{id: string, displayName: string}>} This peer's profile.
	 * @throws {NetworkError} lobby_closed after close().
	 */
	async connect()
	{
		if(this.closed) throw new NetworkError('lobby_closed', 'Lobby provider is closed');

		if(this.connected) return this.profile();

		this.signal?.throwIfAborted();
		this.connected = true;
		this.hub.join(this);
		this.signal?.addEventListener('abort', () => this.close(), {once: true});

		return this.profile();
	}

	/**
	 * Send a pending invitation that expires after the configured lifetime.
	 * @param {string} peerId Invited peer ID.
	 * @param {object} [payload] Cloned invitation payload.
	 * @returns {Promise<object>} Frozen invitation with id, from, to, payload, and expiresAt.
	 */
	async invite(peerId, payload = {})
	{
		this.requireConnected();
		const id = crypto.randomUUID();

		const invitation = Object.freeze({
			id
			, from: this.peerId
			, to: new PeerId(peerId).value
			, payload: structuredClone(payload)
			, expiresAt: this.hub.now() + this.invitationTtl
		});

		this.invitations.set(id, {...invitation, state: 'pending'});
		this.hub.deliver(this, invitation.to, 'invitation', {invitation});

		return invitation;
	}

	/**
	 * Accept or reject a received invitation, notifying both peers.
	 * @param {object} invitation Received invitation.
	 * @param {string} answer accepted or rejected.
	 * @returns {Promise<void>}
	 * @throws {NetworkError} invitation_expired when the invitation has expired.
	 */
	async respond(invitation, answer)
	{
		this.requireConnected();

		if(!['accepted','rejected'].includes(answer)) throw new TypeError('Invalid invitation response');

		if(invitation.expiresAt <= this.hub.now()) throw new NetworkError('invitation_expired', 'Invitation expired');

		this.hub.deliver(this, invitation.from, 'invitationstate', {id: invitation.id, state: answer});
		this.dispatchEvent(event('invitationstate', {id: invitation.id, state: answer}));
	}

	/**
	 * Cancel a pending invitation sent by this provider; other invitations are ignored.
	 * @param {string} invitationId Invitation to cancel.
	 * @param {string} peerId Invited peer to notify.
	 * @returns {Promise<void>}
	 */
	async cancel(invitationId, peerId)
	{
		this.requireConnected();
		const invitation = this.invitations.get(invitationId);

		if(!invitation || invitation.state !== 'pending') return;

		invitation.state = 'cancelled';
		this.hub.deliver(this, peerId, 'invitationstate', {id: invitationId, state: 'cancelled'});
		this.dispatchEvent(event('invitationstate', {id: invitationId, state: 'cancelled'}));
	}

	/**
	 * Deliver a cloned signalling payload to one peer.
	 * @param {string} peerId Receiving peer ID.
	 * @param {*} payload Signalling payload.
	 * @returns {Promise<void>}
	 */
	async signalPeer(peerId, payload)
	{
		this.requireConnected();
		this.hub.deliver(this, new PeerId(peerId).value, 'signal', {payload: structuredClone(payload)});
	}

	/**
	 * Broadcast a trimmed chat message to the room.
	 * @param {*} text Message text; 1-1000 characters after trimming.
	 * @returns {Promise<void>}
	 * @throws {NetworkError} chat_message when the message is empty or too long.
	 */
	async chat(text)
	{
		this.requireConnected();
		text = String(text).trim();

		if(!text || text.length > 1000) throw new NetworkError('chat_message', 'Chat message is empty or too long');

		const sentAt = this.hub.now();

		this.hub.game(this, {
			msgtype: 'm.text'
			, body: text
			, 'org.seanmorris.phonics.sent_at': sentAt
		}, `chat:${crypto.randomUUID()}`, 'm.room.message'
		);

		this.hub.broadcast(this, 'chat', {text, sentAt});
	}

	/** Send one durable deterministic-game room event with an idempotent transaction identifier. */
	async gameEvent(content, transaction = crypto.randomUUID())
	{
		this.requireConnected();
		const item = this.hub.game(this, content, String(transaction));

		return {event_id: item.event_id};
	}

	/** Publish a game offer as a standard Matrix-style room message with interoperable fallback text. */
	async gameOffer(content, transaction = crypto.randomUUID())
	{
		this.requireConnected();
		const item = this.hub.game(this, content, String(transaction), 'm.room.message');

		return {event_id: item.event_id};
	}

	/** Read one room event by identifier. */
	async roomEvent(eventId)
	{
		this.requireConnected();
		const item = this.hub.history(this.room).find(value => value.event_id === eventId);

		if(!item) throw new NetworkError('room_event', `Unknown room event: ${eventId}`);

		return item;
	}

	/** Read related game events for one root event in deterministic room order. */
	async relations(rootId)
	{
		this.requireConnected();

		return this.hub.history(this.room).filter(item => item.content?.['m.relates_to']?.event_id === rootId);
	}

	/** Read recent durable room events, optionally restricted to one event type. */
	async roomHistory({type, types, limit = 200} = {})
	{
		this.requireConnected();
		const selected = Array.isArray(types) ? new Set(types) : type ? new Set([type]) : null;
		const values = this.hub.history(this.room).filter(item => !selected || selected.has(item.type));

		return values.slice(-Math.max(1, Math.min(1000, Number(limit) || 200)));
	}

	/** Advertise this browser's current waiting, active, or spectating session. */
	async setActivity(activity)
	{
		this.requireConnected();
		this.activity = activity === null ? null : structuredClone(activity);
		this.hub.presence(this.room);
	}

	/**
	 * Leave the room once and discard sent invitations.
	 * @returns {void}
	 */
	close()
	{
		if(this.closed) return;

		this.closed = true;

		if(this.connected) this.hub.leave(this);

		this.connected = false;
		this.invitations.clear();
	}

	/**
	 * Require an open, connected provider.
	 * @returns {void}
	 * @throws {NetworkError} lobby_state when closed or not connected.
	 */
	requireConnected()
	{
		if(this.closed || !this.connected) throw new NetworkError('lobby_state', 'Lobby provider is not connected');
	}
}
