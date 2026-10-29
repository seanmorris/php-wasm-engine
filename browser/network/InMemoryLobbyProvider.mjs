import {NetworkError} from './Errors.mjs';
import {PeerId} from './Values.mjs';

const event = (type, detail) => new CustomEvent(type, {detail});

/** Shared deterministic room used by tests and offline examples. */
export class InMemoryLobbyHub
{

	/**
	 * Create an empty set of in-memory rooms.
	 * @param {object} [options] Hub settings.
	 * @param {function(): number} [options.now] Clock in milliseconds; defaults to Date.now().
	 */
	constructor({now = () => Date.now()} = {})
	{
		this.now = now;
		this.rooms = new Map;
	}

	/**
	 * Add a provider to its room and announce presence to every member.
	 * @param {InMemoryLobbyProvider} provider Provider whose room and peer ID are joined.
	 * @returns {void}
	 * @throws {NetworkError} lobby_identity when the peer ID is already in the room.
	 */
	join(provider)
	{
		const room = this.rooms.get(provider.room) ?? new Map;

		if(room.has(provider.peerId))
		throw new NetworkError('lobby_identity', `Peer already joined: ${provider.peerId}`);

		room.set(provider.peerId, provider);
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

		room?.delete(provider.peerId);

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
		const peers = [...room.values()].map(provider => provider.profile());

		for(const provider of room.values()) provider.dispatchEvent(event('presence', {peers}));
	}

	/**
	 * Dispatch one event to a peer in the sender's room.
	 * @param {InMemoryLobbyProvider} sender Sending provider; its peer ID is added as detail.from.
	 * @param {string} target Receiving peer ID.
	 * @param {string} type Event type to dispatch.
	 * @param {object} detail Event detail fields.
	 * @returns {void}
	 * @throws {NetworkError} lobby_peer when the target is not in the room.
	 */
	deliver(sender, target, type, detail)
	{
		const provider = this.rooms.get(sender.room)?.get(target);

		if(!provider) throw new NetworkError('lobby_peer', `Peer is unavailable: ${target}`);

		provider.dispatchEvent(event(type, {...detail, from: sender.peerId}));
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
}

/** LobbyProvider implementation with no network or account dependency. */
export class InMemoryLobbyProvider extends EventTarget
{

	/**
	 * Create a disconnected provider for one peer in a shared hub.
	 * @param {object} options Provider settings.
	 * @param {InMemoryLobbyHub} options.hub Shared hub; any other value throws TypeError.
	 * @param {string} options.peerId Validated peer ID.
	 * @param {string} [options.displayName] Display name; defaults to the peer ID.
	 * @param {string} [options.room] Room name; defaults to phonics.
	 * @param {number} [options.invitationTtl] Invitation lifetime in milliseconds; defaults to 30,000.
	 * @param {AbortSignal} [options.signal] Closes the provider when aborted after connecting.
	 */
	constructor({hub, peerId, displayName = peerId, room = 'phonics', invitationTtl = 30_000, signal} = {})
	{
		super();

		if(!(hub instanceof InMemoryLobbyHub)) throw new TypeError('InMemoryLobbyProvider requires a hub');

		this.hub = hub;
		this.peerId = new PeerId(peerId).value;
		this.displayName = String(displayName);
		this.room = String(room);
		this.invitationTtl = invitationTtl;
		this.signal = signal;
		this.invitations = new Map;
		this.connected = false;
		this.closed = false;
	}

	/**
	 * Describe this peer for presence lists.
	 * @returns {{id: string, displayName: string}} Frozen peer profile.
	 */
	profile() { return Object.freeze({id: this.peerId, displayName: this.displayName}); }

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

		this.hub.broadcast(this, 'chat', {text, sentAt: this.hub.now()});
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
