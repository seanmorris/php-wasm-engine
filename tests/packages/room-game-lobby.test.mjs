import test from 'node:test';
import assert from 'node:assert/strict';
import {InMemoryLobbyHub, InMemoryLobbyProvider} from '../../browser/network/InMemoryLobbyProvider.mjs';
import {RoomGameProtocol} from '../../browser/network/RoomGameLobby.mjs';

const game = Object.freeze({
	id: 'org.example.pool'
	, protocol: '1.0.0'
	, rules: 'casual-eight-ball-v1'
	, application: 'sha256-app'
	, runtime: 'runtime-fixture'
});

const storage = () => {
	const values = new Map;

	return {
		getItem: key => values.get(key) ?? null
		, setItem: (key, value) => values.set(key, value)
		, removeItem: key => values.delete(key)
	};
};

const until = async condition => {
	for(let count = 0; count < 200; count++)
	{
		if(condition()) return;

		await new Promise(resolve => setTimeout(resolve, 2));
	}

	throw new Error('Timed out waiting for room game protocol');
};

test('room games commit and reveal a shared seed before acknowledged deterministic actions', async () => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest', displayName: 'Guest'});

	for(const provider of [hostProvider,guestProvider])
	{
		const setActivity = provider.setActivity.bind(provider);

		provider.setActivity = activity => activity?.state === 'active' ? new Promise(() => {}) : setActivity(activity);
	}

	const host = new RoomGameProtocol({provider: hostProvider, game, session: {}, storage: storage()});
	const guest = new RoomGameProtocol({provider: guestProvider, game, session: {}, storage: storage()});
	const starts = new Map, actions = [], confirmations = [];
	let hostPort, guestPort;

	const adapter = (id, port) => {
		return {
			start: detail => {
				starts.set(id, detail);
				void port().reportReady('initial-hash');
			}
			, action: detail => actions.push({id, detail})
			, confirmed: detail => confirmations.push({id, detail})
		};
	};

	hostPort = host.attachGame(adapter('host', () => hostPort));
	guestPort = guest.attachGame(adapter('guest', () => guestPort));
	await host.connect();
	await guest.connect();
	const root = await host.create();
	const offerEvent = await hostProvider.roomEvent(root);
	assert.equal(offerEvent.type, 'm.room.message');
	assert.equal(offerEvent.content.msgtype, 'm.text');
	assert.match(offerEvent.content.body, /https:\/\/localhost\/#phonics-game=/);
	assert.equal(offerEvent.content['org.seanmorris.phonics.game.v1'].kind, 'offer');
	await guest.join(root);

	await until(
		() => starts.size === 2 && host.snapshot().tables[0]?.status === 'active'
			&& guest.snapshot().tables[0]?.status === 'active'
	);

	assert.equal(starts.get('host').seed, starts.get('guest').seed);
	assert.deepEqual(starts.get('host').handshake, starts.get('guest').handshake);
	assert.equal(starts.get('host').handshake.seed, starts.get('host').seed);
	assert.equal(Object.keys(starts.get('host').handshake.hashPromises).length, 2);
	assert.equal(Object.keys(starts.get('host').handshake.contributions).length, 2);
	assert.match(starts.get('host').handshake.commutativeProduct, /^[A-Za-z0-9_-]{43}$/);
	assert.match(starts.get('host').seed, /^[a-f0-9]{64}$/);
	assert.equal(confirmations.filter(value => value.detail.sequence === 0).length, 2);
	const actor = host.snapshot().tables[0].turn;
	const port = actor === 'host' ? hostPort : guestPort;
	const action = {type: 'shot', aim_udeg: 0, power_bp: 7500, top_bp: 0, side_bp: 0};
	await port.submitAction(action, 'initial-hash');
	await until(() => actions.length === 2);
	await port.submitAction(action, 'initial-hash');
	assert.equal(actions.length, 2, 'an exact pending retry must not publish or execute twice');
	const settlement = {
		sequence: 1
		, hash: 'settled-hash'
		, nextActor: actor === 'host' ? 'guest' : 'host'
	};

	await hostPort.reportSettled({...settlement, checkpoint: actor === 'host' ? 'checkpoint-v1' : null});
	await guestPort.reportSettled({...settlement, checkpoint: actor === 'guest' ? 'checkpoint-v1' : null});
	await until(() => host.snapshot().tables[0].sequence === 1 && guest.snapshot().tables[0].sequence === 1);
	assert.equal(actions.length, 2);
	assert.equal(confirmations.filter(value => value.detail.sequence === 1).length, 2);

	assert.deepEqual(
		confirmations.filter(value => value.detail.sequence === 1).map(value => value.detail.checkpoint)
		, ['checkpoint-v1','checkpoint-v1']
		, 'both applications must receive the acting player checkpoint with confirmation'
	);

	assert.equal(host.snapshot().tables[0].turn, actor === 'host' ? 'guest' : 'host');
	assert.equal(host.snapshot().tables[0].status, 'active');
	host.close();
	guest.close();
	hostProvider.close();
	guestProvider.close();
});

test('linked challenges use readable Matrix messages and join the intended account', async () => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest', displayName: 'Guest'});

	const host = new RoomGameProtocol({
		provider: hostProvider
		, game: {...game, title: 'PHP Billiards Online'}
		, session: {}
		, storage: storage()
		, location: new URL('https://games.example/billiards/')
	});

	await host.connect();
	await guestProvider.connect();
	const root = await host.create({target: 'guest'});
	const message = await guestProvider.roomEvent(root);
	const offer = message.content['org.seanmorris.phonics.game.v1'];
	const url = new URL(message.content.body.match(/https:\/\/\S+/)[0]);
	assert.equal(message.type, 'm.room.message');
	assert.match(message.content.formatted_body, /<a href=/);
	assert.equal(new URLSearchParams(url.hash.slice(1)).get('phonics-game'), offer.session_id);

	const replaced = [];

	const guest = new RoomGameProtocol({
		provider: guestProvider
		, game: {...game, title: 'PHP Billiards Online'}
		, session: {}
		, storage: storage()
		, location: url
		, history: {state: null, replaceState: (...values) => replaced.push(values)}
	});

	await guest.connect();
	assert.equal(guest.selected, root);
	assert.equal(guest.snapshot().tables[0].players.includes('guest'), true);
	assert.equal(replaced.length, 1);
	host.close();
	guest.close();
	hostProvider.close();
	guestProvider.close();
});

test('in-memory room history preserves stable transaction IDs and relations', async () => {
	const hub = new InMemoryLobbyHub;
	const provider = new InMemoryLobbyProvider({hub, peerId: 'host'});
	await provider.connect();
	const root = await provider.gameEvent({v: 1,kind: 'offer'}, 'root');

	const first = await provider.gameEvent({
		v: 1
		, kind: 'join'
		, 'm.relates_to': {rel_type: 'org.seanmorris.phonics.game.v1',event_id: root.event_id}
	}, 'join'
	);

	const retry = await provider.gameEvent({
		v: 1
		, kind: 'join'
		, 'm.relates_to': {rel_type: 'org.seanmorris.phonics.game.v1',event_id: root.event_id}
	}, 'join'
	);

	assert.equal(first.event_id, retry.event_id);
	assert.equal((await provider.relations(root.event_id)).length, 1);
	assert.equal((await provider.roomHistory({type: 'org.seanmorris.phonics.game.v1'})).length, 2);
	provider.close();
});

test('lobby startup performs one filtered replay without old-event fan-out', async () => {
	const hub = new InMemoryLobbyHub;
	const writer = new InMemoryLobbyProvider({hub, peerId: 'writer'});
	await writer.connect();
	const created = Date.now() - 60 * 60_000;

	for(let index = 0; index < 250; index++)
	hub.game(writer, {
		msgtype: 'm.text'
		, body: `Expired table ${index}`
		, 'org.seanmorris.phonics.game.v1': {
			v: 1
			, kind: 'offer'
			, session_id: `expired-${index}`
			, host: 'writer'
			, target: null
			, created_at: created
			, expires_at: created + 15 * 60_000
			, game
		}
	}, `expired-${index}`, 'm.room.message'
	);

	hub.game(writer, {
		v: 1
		, kind: 'join'
		, session_id: 'missing'
		, player: 'writer'
		, 'm.relates_to': {
			rel_type: 'org.seanmorris.phonics.game.v1'
			, event_id: '$root-outside-history'
		}
	}, 'orphan-child'
	);

	const provider = new InMemoryLobbyProvider({hub, peerId: 'reader'});
	const history = provider.roomHistory.bind(provider);
	const historyCalls = [];
	let eventLoads = 0, relationLoads = 0;

	provider.roomHistory = options => {
		historyCalls.push(options);

		return history(options);
	};

	provider.roomEvent = async () => { eventLoads++; throw new Error('unexpected event fetch'); };

	provider.relations = async () => { relationLoads++; return []; };

	const protocol = new RoomGameProtocol({provider, game, session: {}, storage: storage()});
	let changes = 0;
	protocol.addEventListener('change', () => changes++);
	await protocol.connect();

	assert.equal(historyCalls.length, 1);

	assert.deepEqual(historyCalls[0].types, [
		'm.room.message'
		, 'org.seanmorris.phonics.game.v1'
		, 'org.seanmorris.phonics.chat.v1'
	]);

	assert.equal(eventLoads, 0);
	assert.equal(relationLoads, 0);
	assert.equal(protocol.snapshot().tables.length, 0);
	assert.ok(changes <= 2, `expected at most presence plus final render, received ${changes}`);

	protocol.close();
	provider.close();
	writer.close();
});

test('unknown related events cannot recursively fetch cyclic roots', async () => {
	const provider = new EventTarget;
	let eventLoads = 0, relationLoads = 0;

	provider.roomEvent = async root => {
		eventLoads++;
		await new Promise(resolve => setTimeout(resolve, 2));

		return {
			type: 'org.seanmorris.phonics.game.v1'
			, event_id: root
			, sender: 'attacker'
			, content: {
				v: 1
				, kind: 'join'
				, session_id: 'missing-session'
				, player: 'attacker'
				, 'm.relates_to': {
					rel_type: 'org.seanmorris.phonics.game.v1'
					, event_id: root
				}
			}
		};
	};

	provider.relations = async () => { relationLoads++; return []; };

	provider.profile = () => ({id: 'reader', displayName: 'Reader'});
	const protocol = new RoomGameProtocol({provider, game, session: {}, storage: storage()});

	const child = eventId => ({
		type: 'org.seanmorris.phonics.game.v1'
		, event_id: eventId
		, sender: 'attacker'
		, content: {
			v: 1
			, kind: 'join'
			, session_id: 'missing-session'
			, player: 'attacker'
			, 'm.relates_to': {
				rel_type: 'org.seanmorris.phonics.game.v1'
				, event_id: '$cyclic-root'
			}
		}
	});

	provider.dispatchEvent(new CustomEvent('gameevent', {detail: {event: child('$live-orphan')}}));
	await new Promise(resolve => setTimeout(resolve, 2));
	assert.equal(eventLoads, 0, 'unsolicited live children must not fetch unknown roots');

	await Promise.all([
		protocol.consume(child('$orphan-a'))
		, protocol.consume(child('$orphan-b'))
	]);

	assert.equal(eventLoads, 1, 'concurrent children must share one root lookup');
	assert.equal(relationLoads, 0, 'an invalid root must not load relations');
	assert.equal(protocol.snapshot().tables.length, 0);
	await protocol.consume(child('$orphan-c'));
	assert.equal(eventLoads, 1, 'an immutable invalid root must be negative-cached');
	protocol.close();
});
