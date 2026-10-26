import test from 'node:test';
import assert from 'node:assert/strict';
import {LobbyController} from '../../browser/network/LobbyController.mjs';
import {
	InMemoryLobbyHub
	, InMemoryLobbyProvider
} from '../../browser/network/InMemoryLobbyProvider.mjs';
import {NetworkSession} from '../../browser/network/NetworkSession.mjs';
import {NetworkError} from '../../browser/network/Errors.mjs';
import {
	decodeJsonPayload
	, decodePacket
	, encodeJsonPacket
	, encodePacket
	, MessageFamily
} from '../../browser/network/Protocol.mjs';
import {ResourceNegotiator} from '../../browser/network/ResourceNegotiator.mjs';
import {ResourceStore} from '../../browser/ResourceStore.mjs';
import {normalizeWorld} from '../../browser/WorldDefinition.mjs';
import {sha256} from '../../browser/network/Values.mjs';

class LoopbackTransport extends EventTarget
{

	constructor(hub, options)
	{
		super();
		this.hub = hub;
		this.options = options;
		this.closed = false;
		hub.add(this);
	}

	start({initiator}) { this.initiator = initiator; return this.hub.ready(this); }

	receiveSignal() {}

	send(channel, data)
	{
		if(!this.peer || this.closed) throw new Error('loopback is not open');

		const copy = new Uint8Array(data);
		queueMicrotask(() => this.peer.dispatchEvent(new CustomEvent('message', {detail: {channel, data: copy}})));

		return true;
	}

	close(reason)
	{
		if(this.closed) return;

		this.closed = true;
		this.dispatchEvent(new CustomEvent('close', {detail: {reason}}));
	}
}

class LoopbackHub
{

	constructor() { this.transports = []; this.waiters = new Map; }

	add(value)
	{
		this.transports.push(value);

		if(this.transports.length % 2 === 0)
		{
			const [a,b] = this.transports.slice(-2);
			a.peer = b;
			b.peer = a;

			for(const item of [a,b]) this.waiters.get(item)?.(item);
		}
	}

	ready(value)
	{
		if(value.peer) return Promise.resolve(value);

		return new Promise(resolve => this.waiters.set(value, resolve));
	}
}

const world = normalizeWorld({
	type: 'phonics-world'
	, schemaVersion: 1
	, imports: {}
	, game: 'https://game.example/doom/phonics.json#game'
	, resourceSlots: {'base-wad': {authority: 'host', accept: ['.wad']}}
	, sources: {
		episode: {
			adapter: 'https://game.example/doom/phonics.json#wad'
			, resources: [
				{slot: 'base-wad', role: 'iwad'}
			]
		}
	}
	, start: {source: 'episode', scope: 'E1M1'}
	, network: {
		lobby: {provider: '@matrix/lobby'}
		, transport: '@phonics/webrtc'
		, players: {min: 2, max: 2}
	}
}, 'https://game.example/world.json'
);

const store = () => new ResourceStore({
	fetch: async () => new Response(new TextEncoder().encode('fixture wad'))
});

const wait = (target, name) => new Promise(resolve => target.addEventListener(name, resolve, {once: true}));

test('network session orders offered slots by source stack precedence', () => {
	const stacked = normalizeWorld({
		type: 'phonics-world'
		, schemaVersion: 1
		, imports: {}
		, game: 'https://game.example/doom/phonics.json#game'
		, resourceSlots: {
			'match-wad': {authority: 'host', accept: ['.wad'], required: false}
			, 'base-wad': {authority: 'host', accept: ['.wad']}
		}
		, sources: {
			episode: {
				adapter: 'https://game.example/doom/phonics.json#wad'
				, resources: [
					{slot: 'base-wad', role: 'iwad'}
					, {slot: 'match-wad', role: 'pwad'}
				]
			}
		}
		, start: {source: 'episode', scope: 'E1M1'}
	}, 'https://game.example/stacked-world.json'
	);

	const session = new NetworkSession({controller: new EventTarget, negotiator: {}, world: stacked});

	assert.deepEqual(session.orderedSlots().map(([id]) => id), ['base-wad','match-wad']);
});

test('network session includes immutable world resources in ordered match content', async () => {
	const payload = new TextEncoder().encode('fixed support WAD');
	const integrity = await sha256(payload);

	const stacked = normalizeWorld({
		type: 'phonics-world'
		, schemaVersion: 1
		, imports: {}
		, game: 'https://game.example/doom/phonics.json#game'
		, resourceSlots: {
			'base-wad': {authority: 'host', accept: ['.wad']}
			, 'support-wad': {
				authority: 'world'
				, defaultUrl: 'https://assets.example/support.wad'
				, integrity
				, accept: ['.wad']
			}
		}
		, sources: {
			episode: {
				adapter: 'https://game.example/doom/phonics.json#wad'
				, resources: [
					{slot: 'base-wad', role: 'iwad'}
					, {slot: 'support-wad', role: 'pwad'}
				]
			}
		}
		, start: {source: 'episode', scope: 'E1M1'}
	}, 'https://game.example/stacked-world.json'
	);

	const negotiator = new ResourceNegotiator({
		world: stacked
		, store: new ResourceStore({
			fetch: async url => new Response(url.includes('support') ? payload : new TextEncoder().encode('base WAD'))
		})
		, role: 'host'
		, policy: {dataOrigins: '*'}
	});

	const session = new NetworkSession({controller: new EventTarget, negotiator, world: stacked});

	const invitation = await session.prepareInvitation({
		resources: {'base-wad': 'https://assets.example/base.wad'}
	});

	assert.deepEqual(
		invitation.privatePayload.offers.map(({slot, role}) => [slot, role])
		, [['base-wad','iwad'],['support-wad','pwad']]
	);

	assert.equal(JSON.stringify(invitation.publicPayload).includes('assets.example'), false);

	await assert.rejects(
		() => session.prepareInvitation({
			resources: {
				'base-wad': 'https://assets.example/base.wad'
				, 'support-wad': 'https://other.example/support.wad'
			}
		})
		, /fixed by its declaring world/
	);

	negotiator.close();
});

test('network session keeps resource URLs out of invitations and starts after matching readiness', async () => {
	const lobbyHub = new InMemoryLobbyHub;
	const loopback = new LoopbackHub;

	const factory = options => new LoopbackTransport(loopback, options);

	const hostController = new LobbyController({
		provider: new InMemoryLobbyProvider({hub: lobbyHub, peerId: 'host'})
		, transportFactory: factory
	});

	const guestController = new LobbyController({
		provider: new InMemoryLobbyProvider({hub: lobbyHub, peerId: 'guest'})
		, transportFactory: factory
	});

	const host = new NetworkSession({
		controller: hostController
		, negotiator: new ResourceNegotiator({
			world
			, store: store()
			, role: 'host'
			, policy: {dataOrigins: ['https://cdn.example']}
		})
		, world
		, startDelay: 5
	});

	const guest = new NetworkSession({
		controller: guestController
		, negotiator: new ResourceNegotiator({
			world
			, store: store()
			, role: 'guest'
			, policy: {dataOrigins: ['https://cdn.example']}
		})
		, world
		, startDelay: 5
	});

	await hostController.connect();
	await guestController.connect();
	const invited = wait(guestController, 'statechange');

	const invitation = await hostController.invite('guest', {
		resources: {'base-wad': 'https://cdn.example/DOOM1.wad?private=token'}
		, settings: {mode: 'deathmatch', fragLimit: 10}
	});

	assert.equal(JSON.stringify(invitation.payload).includes('private=token'), false);
	await invited;
	const hostResources = wait(host, 'resourcesready');
	const guestResources = wait(guest, 'resourcesready');
	const hostStart = wait(host, 'start');
	const guestStart = wait(guest, 'start');
	await guestController.accept(invitation);
	await Promise.all([hostResources, guestResources]);
	host.gameReady();
	guest.gameReady();
	await Promise.all([hostStart, guestStart]);

	assert.equal(hostController.state, 'playing');
	assert.equal(guestController.state, 'playing');
	assert.equal(guest.boundWorld.sources.episode.resources[0].integrity.startsWith('sha256-'), true);
	assert.equal(host.descriptor.settings.mode, 'deathmatch');

	assert.deepEqual(
		host.matchState()
		, {
			role: 'host'
			, localSlot: 1
			, remoteSlot: 2
			, localPeer: 'host'
			, remotePeer: 'guest'
			, descriptorDigest: host.descriptorDigest
			, descriptor: host.descriptor
		}
	);

	host.sendGameplay(MessageFamily.input, {commands: [{sequence: 1}]}, {tick: 35, schema: 2});
	await new Promise(resolve => setImmediate(resolve));
	const [record] = JSON.parse(guest.bridge.receive());
	const packet = decodePacket(Uint8Array.from(atob(record.data), value => value.charCodeAt(0)));

	assert.equal(packet.family, MessageFamily.input);
	assert.equal(packet.tick, 35);
	assert.equal(packet.schema, 2);
	assert.deepEqual(decodeJsonPayload(packet), {commands: [{sequence: 1}]});
	assert.throws(() => host.sendGameplay(MessageFamily.hello, {}), /cannot originate handshake/);
	host.close();
	guest.close();
});

test('network session resolves name-only content independently before the shared start barrier', async () => {
	const localWorld = normalizeWorld({
		type: 'phonics-world'
		, schemaVersion: 1
		, imports: {}
		, game: 'https://game.example/doom/phonics.json#game'
		, resourceSlots: {
			'base-wad': {
				distribution: 'local'
				, authority: 'host'
				, accept: ['.wad']
				, requirement: {
					id: 'doom-base'
					, names: ['DOOM2.WAD']
					, inspector: 'https://game.example/doom/phonics.json#wad-inspector'
					, equivalence: 'profile'
					, candidates: [
						{id: 'doom2', names: ['DOOM2.WAD'], criteria: {}}
						, {id: 'freedoom.phase2', names: ['freedoom2.wad'], criteria: {}}
					]
				}
			}
		}
		, sources: {
			episode: {
				adapter: 'https://game.example/doom/phonics.json#wad'
				, resources: [
					{slot: 'base-wad', role: 'iwad'}
				]
			}
		}
		, start: {source: 'episode', scope: 'MAP01'}
		, network: {
			lobby: {provider: '@matrix/lobby'}
			, transport: '@phonics/webrtc'
			, players: {min: 2, max: 2}
		}
	}, 'https://game.example/local-world.json'
	);

	const resourceStore = () => new ResourceStore({
		fetch: async url => new Response(new TextEncoder().encode(url.includes('free') ? 'free' : 'owned'))
	});

	const inspector = () => ({
		inspect: bytes => {
			const free = new TextDecoder().decode(bytes) === 'free';

			return {
				candidate: free ? 'freedoom.phase2' : 'doom2'
				, profile: {format: 'doom-wad', mapNamespace: 'commercial'}
			};
		}
	});

	const lobbyHub = new InMemoryLobbyHub;
	const loopback = new LoopbackHub;

	const factory = options => new LoopbackTransport(loopback, options);

	const hostController = new LobbyController({
		provider: new InMemoryLobbyProvider({hub: lobbyHub, peerId: 'host'})
		, transportFactory: factory
	});

	const guestController = new LobbyController({
		provider: new InMemoryLobbyProvider({hub: lobbyHub, peerId: 'guest'})
		, transportFactory: factory
	});

	const policy = {dataOrigins: ['https://owned.example','https://free.example']};

	const host = new NetworkSession({
		controller: hostController
		, negotiator: new ResourceNegotiator({
			world: localWorld
			, store: resourceStore()
			, role: 'host'
			, policy
			, inspectors: inspector
		})
		, world: localWorld
		, startDelay: 5
	});

	const guest = new NetworkSession({
		controller: guestController
		, negotiator: new ResourceNegotiator({
			world: localWorld
			, store: resourceStore()
			, role: 'guest'
			, policy
			, inspectors: inspector
		})
		, world: localWorld
		, startDelay: 5
	});

	await hostController.connect();
	await guestController.connect();

	const invitation = await hostController.invite('guest', {
		resources: {'base-wad': 'https://owned.example/DOOM2.WAD?private=token'}
	});

	assert.equal(JSON.stringify(invitation.payload).includes('owned.example'), false);
	assert.equal(JSON.stringify(invitation.payload).includes('private=token'), false);
	assert.equal(invitation.payload.content[0].requirement.names[0], 'DOOM2.WAD');

	const hostResources = wait(host, 'resourcesready');
	const guestResources = wait(guest, 'resourcesready');
	const hostStart = wait(host, 'start');
	const guestStart = wait(guest, 'start');

	await guestController.accept(invitation, {
		resources: {'base-wad': 'https://free.example/freedoom2.wad'}
	});

	await Promise.all([hostResources, guestResources]);
	host.gameReady();
	guest.gameReady();
	await Promise.all([hostStart, guestStart]);

	assert.equal(hostController.state, 'playing');
	assert.equal(guestController.state, 'playing');
	assert.equal(guest.boundWorld.sources.episode.resources[0].candidate, 'freedoom.phase2');
	host.close();
	guest.close();
});

test('network session tracks control and realtime packet sequences independently', async () => {
	const session = new NetworkSession({controller: new EventTarget, negotiator: {}, world});
	session.match = {initiator: false};

	await session.receive({
		channel: 'realtime'
		, data: encodePacket({family: MessageFamily.input, sequence: 100, payload: '{}'})
	});

	await session.receive({
		channel: 'control'
		, data: encodeJsonPacket({family: MessageFamily.diagnostics, sequence: 1}, {clockReady: true, rtt: 3})
	});

	assert.equal(session.peerClockReady, true);
	assert.equal(session.peerRtt, 3);
});

async function matchPeers(t, {startDelay = 5, guestStore = store()} = {})
{
	const hub = new InMemoryLobbyHub;
	const loopback = new LoopbackHub;

	const peers = ['host', 'guest'].map(peerId => new NetworkSession({
		controller: new LobbyController({
			provider: new InMemoryLobbyProvider({hub, peerId})
			, transportFactory: options => new LoopbackTransport(loopback, options)
		})
		, negotiator: new ResourceNegotiator({
			world
			, store: peerId === 'guest' ? guestStore : store()
			, role: 'host'
			, policy: {dataOrigins: ['https://cdn.example']}
		})
		, world
		, startDelay
	}));

	t.after(() => peers.forEach(peer => peer.close()));
	await Promise.all(peers.map(peer => peer.controller.connect()));

	const connect = async (host = peers[0], guest = peers[1]) => {
		const ready = Promise.all(peers.map(peer => wait(peer, 'resourcesready')));

		const invitation = await host.controller.invite(guest.controller.profile.id, {
			resources: {'base-wad': 'https://cdn.example/DOOM1.wad'}
			, settings: {mode: 'deathmatch'}
		});

		await guest.controller.accept(invitation);

		return ready;
	};

	const play = async () => {
		const started = Promise.all(peers.map(peer => wait(peer, 'start')));
		peers.forEach(peer => peer.gameReady());
		await started;
	};

	return {peers, connect, play};
}

test('a ready host waits for the guest to finish loading before sending game readiness', async t => {
	let release, loading;
	const loaded = new Promise(resolve => { loading = resolve; });

	const guestStore = new ResourceStore({
		fetch: () => new Promise(resolve => {

			release = () => resolve(new Response(new TextEncoder().encode('fixture wad')));
			loading();
		})
	});

	const {peers: [host, guest], connect} = await matchPeers(t, {guestStore});
	const errors = [];

	for(const peer of [host, guest]) peer.addEventListener('error', ({detail}) => errors.push(detail.message));
	const connected = connect();
	await loaded;
	host.gameReady();
	await new Promise(resolve => setImmediate(resolve));
	assert.deepEqual(errors, []);
	assert.equal(guest.remoteGameReady, false);
	const started = Promise.all([wait(host, 'start'), wait(guest, 'start')]);
	guest.addEventListener('resourcesready', () => guest.gameReady(), {once: true});
	release();
	await connected;
	await started;
	assert.equal(host.controller.state, 'playing');
	assert.equal(guest.controller.state, 'playing');
	assert.deepEqual(errors, []);
});

test('leaving a match clears replication state and both peers can play again with reversed roles', async t => {
	const {peers: [host, guest], connect, play} = await matchPeers(t);
	const ended = [];

	for(const peer of [host, guest]) peer.addEventListener('disconnect', ({detail}) => ended.push(detail));
	await connect();
	await play();
	const oldTransport = host.transport;
	const oldGuestTransport = guest.transport;
	host.sendGameplay(MessageFamily.input, {old: true});
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(guest.bridge.diagnostics().queuedPackets, 1);

	const disconnected = wait(guest, 'disconnect');
	host.leaveMatch();
	await disconnected;

	for(const peer of [host, guest])
	{
		assert.equal(peer.controller.state, 'available');
		assert.equal(peer.transport, null);
		assert.equal(peer.matchState(), null);
		assert.equal(peer.boundWorld, null);
		assert.equal(peer.bridge.transport, null);
		assert.equal(peer.bridge.receive(), '[]');
		assert.equal(peer.localGameReady, false);
		assert.equal(peer.localGameReadySent, false);
		assert.equal(peer.remoteGameReady, false);
		assert.equal(peer.peerClockReady, false);
		assert.equal(peer.started, false);
		assert.equal(peer.sendGameplay(MessageFamily.input, {}), false);
		assert.equal(peer.sendGameplay(MessageFamily.event, {}, {channel: 'control'}), false);
	}

	assert.deepEqual(ended.map(value => value.local), [true, false]);
	assert.equal(oldTransport.closed, true);
	assert.equal(oldGuestTransport.closed, true);

	await connect(guest, host);
	await play();
	assert.equal(host.matchState().localSlot, 2);
	assert.equal(guest.matchState().localSlot, 1);

	// Late callbacks from the previous RTC connection must not close or pollute its replacement.
	oldTransport.dispatchEvent(new CustomEvent('error', {detail: new Error('old transport failed')}));
	oldTransport.dispatchEvent(new CustomEvent('close', {detail: {reason: 'late close'}}));

	oldTransport.dispatchEvent(new CustomEvent('message', {
		detail: {
			channel: 'realtime'
			, data: encodePacket({family: MessageFamily.input, sequence: 900, payload: '{}'})
		}
	}));

	assert.equal(host.controller.state, 'playing');
	assert.equal(host.bridge.receive(), '[]');
	assert.equal(ended.length, 2);
	guest.sendGameplay(MessageFamily.input, {new: true});
	await new Promise(resolve => setImmediate(resolve));
	const [record] = JSON.parse(host.bridge.receive());
	assert.deepEqual(decodeJsonPayload(decodePacket(Buffer.from(record.data, 'base64'))), {new: true});
});

test('closing during the start countdown cancels the start and discards late game readiness', async t => {
	const {peers: [host, guest], connect} = await matchPeers(t, {startDelay: 30});
	await connect();
	let starts = 0, ends = 0;
	host.addEventListener('start', () => starts++);
	host.addEventListener('disconnect', () => ends++);

	const starting = new Promise(resolve => host.controller.addEventListener('statechange', ({detail}) => {
		if(detail.state === 'starting') resolve();
	}));

	host.gameReady();
	guest.gameReady();
	await starting;
	host.transport.close('peer disconnected');
	host.controller.closeMatch('duplicate close');
	host.gameReady();
	await new Promise(resolve => setTimeout(resolve, 60));
	assert.equal(starts, 0);
	assert.equal(ends, 1);
	assert.equal(host.controller.state, 'available');
});

test('a data-channel send/close race returns false to PHP without hiding programming errors', async t => {
	for(const failure of [
		new NetworkError('transport_closed', 'WebRTC transport is closed')
		, new NetworkError('channel_closed', 'realtime is not open')
		, new DOMException('RTCDataChannel is not open', 'InvalidStateError')
	]){
		const {peers: [host], connect} = await matchPeers(t);
		await connect();
		let ends = 0;
		host.addEventListener('disconnect', () => ends++);

		host.transport.send = () => { throw failure; };

		assert.equal(host.sendGameplay(MessageFamily.input, {}), false);
		assert.equal(host.sendGameplay(MessageFamily.input, {}), false);
		assert.equal(ends, 1);
		assert.equal(host.controller.state, 'available');
		assert.throws(() => host.sendGameplay(MessageFamily.hello, {}), /cannot originate handshake/);
		assert.throws(() => host.sendGameplay(MessageFamily.input, {}, {channel: 'typo'}), /Unknown gameplay channel/);
	}

	const {peers: [host], connect} = await matchPeers(t);
	await connect();

	host.transport.send = () => { throw new TypeError('broken sender'); };

	assert.throws(() => host.sendGameplay(MessageFamily.input, {}), /broken sender/);
	assert.notEqual(host.matchState(), null);
});

test('disconnect during resource acquisition cannot finish loading an abandoned match', async t => {
	let release, loading;
	const loaded = new Promise(resolve => { loading = resolve; });

	const guestStore = new ResourceStore({
		fetch: () => new Promise(resolve => {

			release = () => resolve(new Response(new TextEncoder().encode('fixture wad')));
			loading();
		})
	});

	const {peers: [host, guest], connect} = await matchPeers(t, {guestStore});
	let resourcesReady = 0, errors = 0;
	guest.addEventListener('resourcesready', () => resourcesReady++);
	guest.addEventListener('error', () => errors++);
	// This match intentionally never reaches resourcesready on the guest.
	void connect();
	await loaded;
	const disconnected = wait(guest, 'disconnect');
	host.leaveMatch();
	await disconnected;
	release();
	await new Promise(resolve => setTimeout(resolve, 20));
	assert.equal(resourcesReady, 0);
	assert.equal(errors, 0);
	assert.equal(guest.boundWorld, null);
	assert.equal(guest.negotiator.resolutions().length, 0);
	assert.equal(guest.controller.state, 'available');
});
