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

const seedValue = number => {
	const bytes = Buffer.alloc(32);
	bytes.writeUInt32BE(number, 28);

	return bytes.toString('base64url');
};

const opening = (number = 1, nonce = 4, phrase = 0) => ({
	contribution: seedValue(number)
	, nonce: seedValue(nonce)
	, phrase
});

const seedFixture = async (t, version = 2) => {
	const provider = new EventTarget;

	provider.profile = () => ({id: 'observer'});

	provider.setActivity = async () => {};

	const protocol = new RoomGameProtocol({provider, game, session: {}, storage: storage()});
	t.after(() => protocol.close());
	const root = '$seed-fixture', sessionId = 'session-fixture';
	let sequence = 0;

	const consume = (kind, player, body = {}, sentAt = Date.now()) => protocol.consume({
		event_id: `$fixture-${++sequence}`
		, type: 'org.seanmorris.phonics.game.v1'
		, sender: player
		, origin_server_ts: sentAt
		, content: {
			v: version
			, kind
			, session_id: sessionId
			, player
			, ...body
			, 'm.relates_to': {rel_type: 'org.seanmorris.phonics.game.v1', event_id: root}
		}
	}, {replay: true});

	await protocol.consume({
		event_id: root
		, type: 'org.seanmorris.phonics.game.v1'
		, sender: 'host'
		, content: {
			v: version
			, kind: 'offer'
			, session_id: sessionId
			, host: 'host'
			, game
			, created_at: Date.now()
			, expires_at: Date.now() + 60_000
		}
	});

	const session = protocol.sessions.get(root);

	if(session) await consume('seat', 'host', {players: ['host', 'guest'], commit_deadline: Date.now() + 60_000});

	return {protocol, session, consume};
};

const stalledExchange = async (t, missing = 'reveal') => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest'});
	const retained = storage();
	const host = new RoomGameProtocol({provider: hostProvider, game, session: {}, storage: retained});
	const guest = new RoomGameProtocol({provider: guestProvider, game, session: {}, storage: storage()});
	const protocols = [host, guest];

	t.after(() => {
		for(const protocol of protocols) protocol.close();
		hostProvider.close();
		guestProvider.close();
	});

	// The withholding peer does not cooperate with client-side deadlines either.

	guest[missing] = async () => {};

	guest.armRevealDeadline = () => {};

	await host.connect();
	await guest.connect();
	const root = await host.create();
	await guest.join(root);
	const session = host.sessions.get(root);
	await until(() => missing === 'reveal' ? session.reveals.size === 1 : session.commits.size === 1);

	return {hub, hostProvider, guestProvider, host, guest, root, session, retained, protocols};
};

test('a withholding peer cannot win through the honest player\'s seed timeout or later departure', async t => {
	const {hub, hostProvider, host, guest, root, session, retained} = await stalledExchange(t);
	const stops = [];
	host.attachGame({stop: detail => stops.push(detail)});
	clearTimeout(session.deadlineTimer);
	t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: session.commitDeadline - 60_000});
	host.armRevealDeadline(session);
	t.mock.timers.tick(119_999);
	assert.equal(session.status, 'committing');
	t.mock.timers.tick(1);
	assert.equal(session.status, 'expired');
	assert.equal(session.outcome, undefined);
	assert.equal(session.seed, undefined);
	assert.equal(retained.getItem(`phonics.game.seed:v2:${root}:host`), null);
	assert.equal(session.localSeedMaterial, undefined);
	assert.equal(stops.length, 1);
	assert.equal(stops[0].winner, undefined);
	assert.match(stops[0].reason, /timed out/i);
	assert.equal(host.snapshot().tables[0].resumable, false);
	assert.equal(host.snapshot().tables[0].spectatable, false);

	await guest.child(
		guest.sessions.get(root)
		, 'reveal'
		, {player: 'guest', ...guest.sessions.get(root).localSeedMaterial}
		, 'late-reveal'
	);

	for(const peer of [host, guest]) await peer.reportReady('late-ready');
	assert.equal(session.status, 'expired');
	assert.equal(session.seed, undefined);
	await host.leave();
	assert.equal(host.selected, null);
	assert.equal(hub.history(hostProvider.room).filter(item => item.content.kind === 'resign').length, 0);
	assert.notEqual(await host.create(), root);
});

test('reload keeps the original seed deadline and expired history publishes no new opening', async t => {
	const {hub, hostProvider, host, root, session, retained, protocols} = await stalledExchange(t);
	host.close();
	const transcriptLength = hub.history(hostProvider.room).length;
	t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: session.commitDeadline + 40_000});
	const resumed = new RoomGameProtocol({provider: hostProvider, game, session: {}, storage: retained});
	protocols.push(resumed);
	await resumed.connect();
	assert.equal(resumed.sessions.get(root).status, 'committing');
	t.mock.timers.tick(19_999);
	assert.equal(resumed.sessions.get(root).status, 'committing');
	t.mock.timers.tick(1);
	assert.equal(resumed.sessions.get(root).status, 'expired');
	resumed.close();

	t.mock.method(
		crypto
		, 'getRandomValues'
		, () => { throw new Error('Expired history must not create another opening'); }
	);

	for(const peerId of ['host', 'observer'])
	{
		const provider = peerId === 'host' ? hostProvider : new InMemoryLobbyProvider({hub, peerId});
		const replay = new RoomGameProtocol({provider, game, session: {}, storage: retained});
		protocols.push(replay);

		if(provider !== hostProvider) t.after(() => provider.close());

		await replay.connect();
		assert.equal(replay.sessions.get(root).status, 'expired');
		assert.equal(replay.sessions.get(root).outcome, undefined);
	}

	assert.equal(hub.history(hostProvider.room).length, transcriptLength);
});

test('a missing commitment also expires without a resignation', async t => {
	const {hub, hostProvider, host, session} = await stalledExchange(t, 'commit');
	clearTimeout(session.deadlineTimer);
	t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: session.commitDeadline - 60_000});
	host.armRevealDeadline(session);
	t.mock.timers.tick(120_000);
	assert.equal(session.status, 'expired');
	assert.equal(session.outcome, undefined);
	assert.equal(hub.history(hostProvider.room).filter(item => item.content.kind === 'resign').length, 0);
});

test('late seed events and legacy timeout resignations cannot resurrect an expired exchange on replay', async t => {
	const {protocol, session, consume} = await seedFixture(t);
	const host = opening(), guest = opening(2, 5);
	await consume('commit', 'host', {commit: await protocol.commitDigest(session, 'host', host)});
	await consume('commit', 'guest', {commit: await protocol.commitDigest(session, 'guest', guest)});
	await consume('reveal', 'host', host);
	t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: session.commitDeadline + 60_000});
	await consume('reveal', 'guest', guest);
	await consume('ready', 'host', {hash: 'late-ready'});
	await consume('ready', 'guest', {hash: 'late-ready'});
	await consume('resign', 'host', {reason: 'seed reveal timeout'});
	await protocol.resumeSeedExchange(session);
	assert.equal(session.status, 'expired');
	assert.equal(session.reveals.size, 1);
	assert.equal(session.seed, undefined);
	assert.equal(session.outcome, undefined);
});

test('completed timely history still loads after expiry and readiness cannot skip seed verification', async t => {
	const {protocol, session, consume} = await seedFixture(t);
	const sentAt = session.commitDeadline + 59_999;
	t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: session.commitDeadline + 180_000});
	await consume('ready', 'host', {hash: 'initial'});
	await consume('ready', 'guest', {hash: 'initial'});
	assert.equal(session.status, 'committing');
	assert.equal(session.seed, undefined);

	for(const [player, material] of [['host', opening()], ['guest', opening(2, 5)]])
	await consume('commit', player, {commit: await protocol.commitDigest(session, player, material)}, sentAt);
	await consume('reveal', 'host', opening(), sentAt);
	await consume('reveal', 'guest', opening(2, 5), sentAt);
	assert.equal(session.status, 'active');
	assert.match(session.seed, /^[a-f0-9]{64}$/);
	assert.equal(session.hash, 'initial');
	await protocol.resumeSeedExchange(session);
	protocol.armRevealDeadline(session);
	t.mock.timers.tick(120_000);
	assert.equal(session.status, 'active');
});

test('missing, invalid, and expired server timestamps cannot supply a commitment or reveal', async t => {
	for(const kind of ['commit', 'reveal'])
	for(const timestamp of [null, NaN, Infinity, 'future', 'deadline', 'later'])
	{
		const {protocol, session, consume} = await seedFixture(t);
		const material = opening();
		const commit = await protocol.commitDigest(session, 'host', material);

		if(kind === 'reveal') await consume('commit', 'host', {commit});

		const sentAt = timestamp === 'deadline'
			? session.commitDeadline + 60_000
			: timestamp === 'later' ? session.commitDeadline + 60_001 : timestamp;

		await consume(kind, 'host', kind === 'commit' ? {commit} : material, sentAt);
		assert.equal(session[kind === 'commit' ? 'commits' : 'reveals'].size, 0);
		assert.equal(session.seed, undefined);
	}
});

test('an old seed timer cannot stop or resign a different selected table', async t => {
	const {protocol, session} = await seedFixture(t);
	t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: session.commitDeadline - 60_000});
	const selected = {...session, root: '$new-table', status: 'active', seed: 'new-seed'};
	protocol.sessions.set(selected.root, selected);
	protocol.selected = selected.root;
	const stops = [];
	protocol.adapter = {stop: value => stops.push(value)};
	protocol.armRevealDeadline(session);
	t.mock.timers.tick(120_000);
	assert.equal(session.status, 'expired');
	assert.equal(selected.status, 'active');
	assert.equal(selected.outcome, undefined);
	assert.equal(protocol.selected, selected.root);
	assert.equal(stops.length, 0);
});

test('frozen, resigned, and closed exchanges stay stopped when a stale deadline callback runs', async t => {
	for(const finish of ['freeze', 'resign', 'close'])
	await t.test(finish, async t => {
		const {protocol, session, consume} = await seedFixture(t);
		t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: session.commitDeadline - 60_000});
		const schedule = t.mock.method(globalThis, 'setTimeout');
		protocol.armRevealDeadline(session);
		const callback = schedule.mock.calls[0].arguments[0];

		if(finish === 'freeze') protocol.freeze(session, 'Invalid seed reveal');
		else if(finish === 'resign') await consume('resign', 'guest', {reason: 'left'});
		else protocol.close();

		const status = session.status;
		t.mock.timers.tick(120_000);
		callback();
		assert.equal(session.status, status);

		if(finish === 'resign') assert.deepEqual(session.outcome, {winner: 'host', reason: 'left'});
		else assert.equal(session.outcome, undefined);

		await consume('ready', 'host', {hash: 'late'});
		await consume('ready', 'guest', {hash: 'late'});
		assert.equal(session.status, status);
	});
});

test('a reveal still hashing when its deadline passes cannot restart the exchange', async t => {
	const {protocol, session, consume} = await seedFixture(t);
	const host = opening(), guest = opening(2, 5);
	const guestCommit = await protocol.commitDigest(session, 'guest', guest);
	await consume('commit', 'host', {commit: await protocol.commitDigest(session, 'host', host)});
	await consume('commit', 'guest', {commit: guestCommit});
	await consume('reveal', 'host', host);
	t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: session.commitDeadline + 59_999});
	protocol.armRevealDeadline(session);
	let finish;
	t.mock.method(protocol, 'commitDigest', () => new Promise(resolve => { finish = resolve; }));

	const pending = consume('reveal', 'guest', guest);
	t.mock.timers.tick(1);
	assert.equal(session.status, 'expired');
	finish(guestCommit);
	await pending;
	assert.equal(session.status, 'expired');
	assert.equal(session.reveals.size, 1);
	assert.equal(session.seed, undefined);
});

test('room games commit and reveal a shared seed before acknowledged deterministic actions', async () => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest', displayName: 'Guest'});

	for(const provider of [hostProvider,guestProvider])
	{
		const setActivity = provider.setActivity.bind(provider);

		provider.setActivity = activity => activity?.state === 'active' ? new Promise(() => {}) : setActivity(activity);
	}

	const hostStorage = storage(), guestStorage = storage();
	const host = new RoomGameProtocol({provider: hostProvider, game, session: {}, storage: hostStorage});
	const guest = new RoomGameProtocol({provider: guestProvider, game, session: {}, storage: guestStorage});
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
	assert.equal(offerEvent.content['org.seanmorris.phonics.game.v1'].v, 2);
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
	assert.equal(starts.get('host').handshake.commitmentVersion, 2);
	const transcript = hub.history(hostProvider.room).map(item => item.content);
	const firstReveal = transcript.findIndex(item => item.kind === 'reveal');
	assert.equal(transcript.slice(0, firstReveal).filter(item => item.kind === 'commit').length, 2);

	for(const item of transcript.filter(item => item.kind === 'commit'))
	{
		assert.equal(item.contribution, undefined);
		assert.equal(item.nonce, undefined);
		assert.equal(item.phrase, undefined);
	}

	for(const item of transcript.filter(item => item.kind === 'reveal'))
	{
		assert.match(item.nonce, /^[A-Za-z0-9_-]{43}$/);
		assert.ok(Number.isInteger(item.phrase) && item.phrase >= 0 && item.phrase < 8);

		assert.equal(
			await host.commitDigest(host.sessions.get(root), item.player, item)
			, starts.get('host').handshake.hashPromises[item.player]
		);

		assert.deepEqual(
			starts.get('host').handshake.commitmentOpenings[item.player]
			, {nonce: item.nonce, phrase: item.phrase}
		);
	}

	for(const [peer, protocol, retained] of [['host', host, hostStorage], ['guest', guest, guestStorage]])
	{
		assert.equal(retained.getItem(`phonics.game.seed:v2:${root}:${peer}`), null);
		assert.equal(protocol.sessions.get(root).localSeedMaterial, undefined);
	}

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

test('seed commitments bind the nonce, each phrase, both operands and the table context', async t => {
	const {protocol, session} = await seedFixture(t);
	const material = opening();
	const original = await protocol.commitDigest(session, 'host', material);
	assert.equal(original, '883fb4907b2a9ffedc67a4499fba851d23b335adfbb47a646aa495055fcf8bbb');

	const phrases = await Promise.all(
		Array.from({length: 8}, (_, phrase) => protocol.commitDigest(session, 'host', {...material, phrase}))
	);

	assert.equal(new Set(phrases).size, 8);

	for(const variant of [opening(1, 5), opening(2, 4), opening(2, 3)])
	assert.notEqual(
		await protocol.commitDigest(session, 'host', variant)
		, original
		, 'Equal arithmetic sums must not substitute for the committed operands'
	);
	assert.notEqual(await protocol.commitDigest(session, 'guest', material), original);
	assert.notEqual(await protocol.commitDigest({...session, root: '$another-table'}, 'host', material), original);

	assert.notEqual(
		await protocol.commitDigest({...session, offer: {session_id: 'another-session'}}, 'host', material)
		, original
	);
});

test('nonces and phrase choices change commitments but never the combined seed or breaker', async t => {

	const run = async (host, guest, reverse = false) => {
		const fixture = await seedFixture(t);
		const {protocol, session, consume} = fixture;
		const players = [['host', host], ['guest', guest]];

		if(reverse) players.reverse();

		for(const [player, material] of players)
		await consume('commit', player, {commit: await protocol.commitDigest(session, player, material)});

		for(const [player, material] of players) await consume('reveal', player, material);
		assert.equal(session.status, 'ready');

		return session;
	};

	const first = await run(opening(1, 4, 0), opening(2, 5, 1));
	const second = await run(opening(1, 6, 7), opening(2, 7, 6), true);
	assert.notDeepEqual(first.seedHandshake.hashPromises, second.seedHandshake.hashPromises);
	assert.equal(first.seed, second.seed);
	assert.equal(first.turn, second.turn);
	assert.equal(first.seedHandshake.commutativeProduct, second.seedHandshake.commutativeProduct);
	assert.notEqual((await run(opening(3, 4, 0), opening(2, 5, 1))).seed, first.seed);
});

test('tampered or malformed commitment openings cannot produce a seed', async t => {
	const valid = opening();
	const variants = [
		opening(1, 5), opening(2, 4), opening(1, 4, 1), {...valid, nonce: undefined}
		, {...valid, nonce: null}
		, {...valid, nonce: 'a'.repeat(42)}
		, {...valid, nonce: valid.nonce + '='}
		, {...valid, nonce: 'A'.repeat(42) + 'B'}, {...valid, contribution: 'invalid'}
		, {...valid, phrase: undefined}, {...valid, phrase: -1}, {...valid, phrase: 8}
		, {...valid, phrase: 0.5}, {...valid, phrase: '0'}
	];

	for(const material of variants)
	{
		const {protocol, session, consume} = await seedFixture(t);
		await consume('commit', 'host', {commit: await protocol.commitDigest(session, 'host', valid)});
		await consume('commit', 'guest', {commit: await protocol.commitDigest(session, 'guest', opening(2, 5))});
		await consume('reveal', 'host', material);
		assert.equal(session.status, 'frozen', JSON.stringify(material));
		assert.equal(session.reveals.size, 0);
		await consume('reveal', 'host', valid);
		await consume('reveal', 'guest', opening(2, 5));
		assert.equal(session.seed, undefined);
	}
});

test('room seed version rejects legacy and unknown commitment exchanges', async t => {
	for(const version of [1, 3]) assert.equal((await seedFixture(t, version)).session, undefined);
	assert.ok((await seedFixture(t, 2)).session);
});

test('CSPRNG bytes independently choose the contribution, nonce and all eight phrases without bias', async t => {
	const {protocol, session} = await seedFixture(t);
	const published = [];

	protocol.child = async (_, kind, content) => published.push({kind, ...content});

	protocol.armRevealDeadline = () => {};

	const counts = Array(8).fill(0);
	let byte = 0;

	t.mock.method(crypto, 'getRandomValues', bytes => {
		assert.equal(bytes.length, 65);
		bytes.set(Buffer.from(seedValue(1), 'base64url'));
		bytes.set(Buffer.from(seedValue(4), 'base64url'), 32);
		bytes[64] = byte++;

		return bytes;
	});

	for(let index = 0; index < 256; index++)
	{
		const next = {...session, root: `$random-${index}`};
		await protocol.commit(next);
		const material = next.localSeedMaterial;
		assert.equal(material.contribution, seedValue(1));
		assert.equal(material.nonce, seedValue(4));
		counts[material.phrase]++;
	}

	assert.deepEqual(counts, Array(8).fill(32));
	assert.ok(published.every(item => item.kind === 'commit' && item.nonce === undefined && item.phrase === undefined));
});

test('reload restores the same private opening, and completed history needs no new secrets', async t => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest'});
	const retained = storage();
	const host = new RoomGameProtocol({provider: hostProvider, game, session: {}, storage: storage()});
	const guest = new RoomGameProtocol({provider: guestProvider, game, session: {}, storage: retained});
	const protocols = [host, guest];

	t.after(() => {
		for(const protocol of protocols) protocol.close();
		hostProvider.close();
		guestProvider.close();
	});

	guest.reveal = async () => {};

	await host.connect();
	await guest.connect();
	const root = await host.create();
	await guest.join(root);
	await until(() => host.sessions.get(root).reveals.size === 1);
	const original = JSON.parse(retained.getItem(`phonics.game.seed:v2:${root}:guest`));
	guest.close();
	const resumed = new RoomGameProtocol({provider: guestProvider, game, session: {}, storage: retained});
	protocols.push(resumed);
	await resumed.connect();
	await until(() => resumed.sessions.get(root)?.seed && host.sessions.get(root)?.seed);
	assert.equal(resumed.sessions.get(root).seed, host.sessions.get(root).seed);

	assert.deepEqual(
		resumed.sessions.get(root).openings.get('guest')
		, {nonce: original.nonce, phrase: original.phrase}
	);

	const transcript = hub.history(guestProvider.room);
	assert.equal(transcript.filter(item => item.sender === 'guest' && item.content.kind === 'commit').length, 1);
	assert.equal(transcript.filter(item => item.sender === 'guest' && item.content.kind === 'reveal').length, 1);
	assert.equal(retained.getItem(`phonics.game.seed:v2:${root}:guest`), null);
	resumed.close();
	t.mock.method(crypto, 'getRandomValues', () => { throw new Error('History must not generate a new opening'); });

	const replay = new RoomGameProtocol({provider: guestProvider, game, session: {}, storage: retained});
	protocols.push(replay);
	await replay.connect();
	assert.deepEqual(replay.sessions.get(root).seedHandshake, host.sessions.get(root).seedHandshake);
	assert.equal(hub.history(guestProvider.room).length, transcript.length);
});

test('a live seed exchange works when session storage is unavailable', async t => {
	const hub = new InMemoryLobbyHub;

	const blocked = () => { throw new Error('Storage denied'); };

	const peers = ['host', 'guest'].map(peerId => {
		const provider = new InMemoryLobbyProvider({hub, peerId});

		const protocol = new RoomGameProtocol({
			provider
			, game
			, session: {}
			, storage: {getItem: blocked, setItem: blocked, removeItem: blocked}
		});

		t.after(() => { protocol.close(); provider.close(); });

		return protocol;
	});

	for(const peer of peers) await peer.connect();
	const root = await peers[0].create();
	await peers[1].join(root);
	await until(() => peers.every(peer => peer.sessions.get(root)?.seed));
	assert.equal(peers[0].sessions.get(root).seed, peers[1].sessions.get(root).seed);
});

test('a lost or corrupt private opening freezes instead of replacing a published commitment', async t => {
	t.mock.method(crypto, 'getRandomValues', () => { throw new Error('Do not regenerate a committed opening'); });

	for(const stored of [null, '{invalid', JSON.stringify({...opening(), nonce: null})])
	{
		const {protocol, session} = await seedFixture(t);

		protocol.provider.profile = () => ({id: 'host'});
		const published = [];

		protocol.child = async (...args) => published.push(args);
		session.commits.set('host', await protocol.commitDigest(session, 'host', opening()));
		session.commits.set('guest', await protocol.commitDigest(session, 'guest', opening(2, 5)));

		if(stored !== null) protocol.storage.setItem('phonics.game.seed:v2:$seed-fixture:host', stored);

		await protocol.reveal(session);
		assert.equal(session.status, 'frozen');
		assert.equal(session.seed, undefined);
		assert.equal(published.length, 0);
	}
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
			v: 2
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

test('leaving an unseated table cancels it and a challenged player can decline', async () => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest', displayName: 'Guest'});

	const protocol = provider => new RoomGameProtocol({
		provider
		, game
		, session: {}
		, storage: storage()
		, location: new URL('https://games.example/billiards/')
	});

	const host = protocol(hostProvider);
	const guest = protocol(guestProvider);
	const notices = [];
	host.addEventListener('notice', ({detail}) => notices.push(detail));
	await host.connect();
	await guest.connect();

	const open = await host.create();
	await until(() => guest.snapshot().tables.some(table => table.root === open && table.joinable));
	assert.match(host.snapshot().tables.find(table => table.root === open).link, /#phonics-game=/);
	await host.leave();
	assert.equal(host.selected, null);
	await until(() => !guest.snapshot().tables.some(table => table.root === open));
	await assert.rejects(guest.join(open), /no longer open/);

	const challenge = await host.create({target: 'guest'});
	await until(() => guest.snapshot().tables.some(table => table.root === challenge && table.declinable));
	const offered = guest.snapshot().tables.find(table => table.root === challenge);
	assert.equal(offered.forMe, true);
	assert.equal(offered.joinable, true);
	await guest.decline(challenge);
	await until(() => notices.some(notice => notice.kind === 'declined' && notice.root === challenge));
	assert.equal(host.selected, null);
	assert.equal(host.snapshot().tables.some(table => table.root === challenge), false);
	assert.equal(guest.snapshot().tables.some(table => table.root === challenge), false);

	host.close();
	guest.close();
	hostProvider.close();
	guestProvider.close();
});
