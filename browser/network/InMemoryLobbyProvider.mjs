import {NetworkError} from './Errors.mjs';
import {PeerId} from './Values.mjs';

const event = (type, detail) => new CustomEvent(type, {detail});

/** Shared deterministic room used by tests and offline examples. */
export class InMemoryLobbyHub
{

	constructor({now = () => Date.now()} = {})
	{
		this.now = now;
		this.rooms = new Map;
	}

	join(provider)
	{
		const room = this.rooms.get(provider.room) ?? new Map;

		if(room.has(provider.peerId))
		throw new NetworkError('lobby_identity', `Peer already joined: ${provider.peerId}`);

		room.set(provider.peerId, provider);
		this.rooms.set(provider.room, room);
		this.presence(provider.room);
	}

	leave(provider)
	{
		const room = this.rooms.get(provider.room);

		room?.delete(provider.peerId);

		if(!room?.size) this.rooms.delete(provider.room);
		else this.presence(provider.room);
	}

	presence(roomId)
	{
		const room = this.rooms.get(roomId) ?? new Map;
		const peers = [...room.values()].map(provider => provider.profile());

		for(const provider of room.values()) provider.dispatchEvent(event('presence', {peers}));
	}

	deliver(sender, target, type, detail)
	{
		const provider = this.rooms.get(sender.room)?.get(target);

		if(!provider) throw new NetworkError('lobby_peer', `Peer is unavailable: ${target}`);

		provider.dispatchEvent(event(type, {...detail, from: sender.peerId}));
	}

	broadcast(sender, type, detail)
	{
		for(const provider of this.rooms.get(sender.room)?.values() ?? [])
		provider.dispatchEvent(event(type, {...detail, from: sender.peerId}));
	}
}

/** LobbyProvider implementation with no network or account dependency. */
export class InMemoryLobbyProvider extends EventTarget
{

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

	profile() { return Object.freeze({id: this.peerId, displayName: this.displayName}); }

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

	async respond(invitation, answer)
	{
		this.requireConnected();

		if(!['accepted','rejected'].includes(answer)) throw new TypeError('Invalid invitation response');

		if(invitation.expiresAt <= this.hub.now()) throw new NetworkError('invitation_expired', 'Invitation expired');

		this.hub.deliver(this, invitation.from, 'invitationstate', {id: invitation.id, state: answer});
		this.dispatchEvent(event('invitationstate', {id: invitation.id, state: answer}));
	}

	async cancel(invitationId, peerId)
	{
		this.requireConnected();
		const invitation = this.invitations.get(invitationId);

		if(!invitation || invitation.state !== 'pending') return;

		invitation.state = 'cancelled';
		this.hub.deliver(this, peerId, 'invitationstate', {id: invitationId, state: 'cancelled'});
		this.dispatchEvent(event('invitationstate', {id: invitationId, state: 'cancelled'}));
	}

	async signalPeer(peerId, payload)
	{
		this.requireConnected();
		this.hub.deliver(this, new PeerId(peerId).value, 'signal', {payload: structuredClone(payload)});
	}

	async chat(text)
	{
		this.requireConnected();
		text = String(text).trim();

		if(!text || text.length > 1000) throw new NetworkError('chat_message', 'Chat message is empty or too long');

		this.hub.broadcast(this, 'chat', {text, sentAt: this.hub.now()});
	}

	close()
	{
		if(this.closed) return;

		this.closed = true;

		if(this.connected) this.hub.leave(this);

		this.connected = false;
		this.invitations.clear();
	}

	requireConnected()
	{
		if(this.closed || !this.connected) throw new NetworkError('lobby_state', 'Lobby provider is not connected');
	}
}
