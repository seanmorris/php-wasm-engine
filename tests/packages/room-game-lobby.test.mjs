import test from 'node:test';
import assert from 'node:assert/strict';
import {InMemoryLobbyHub, InMemoryLobbyProvider} from '../../browser/network/InMemoryLobbyProvider.mjs';
import {
	RoomGameProtocol
	, gameAspectRatio
	, gameCanvasSize
} from '../../browser/network/RoomGameLobby.mjs';

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

test('room canvas sizing supports responsive games without changing fixed-ratio games', () => {
	assert.equal(gameAspectRatio('auto'), null);
	assert.equal(gameAspectRatio(' AUTO '), null);
	assert.equal(gameAspectRatio('16/9'), 16 / 9);
	assert.equal(gameAspectRatio(4 / 3), 4 / 3);
	assert.equal(gameAspectRatio('invalid'), 16 / 9);
	assert.deepEqual(gameCanvasSize(390, 500, null), {width: 390, height: 500});
	assert.deepEqual(gameCanvasSize(1000, 500, null), {width: 1000, height: 500});
	assert.deepEqual(gameCanvasSize(1000, 700, 16 / 9), {width: 1000, height: 562.5});
	assert.deepEqual(gameCanvasSize(700, 500, 16 / 9), {width: 700, height: 393.75});
});

const seedFixture = async (t, version = 2, seat = {}) => {
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

	if(session)
	await consume('seat', 'host', {players: ['host', 'guest'], commit_deadline: Date.now() + 60_000, ...seat});

	return {protocol, session, consume};
};

const stalledExchange = async (t, missing = 'reveal', options = {}) => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest'});
	const retained = storage();
	const configuredGame = {...game, ...options};
	const host = new RoomGameProtocol({provider: hostProvider, game: configuredGame, session: {}, storage: retained});

	const guest = new RoomGameProtocol(
		{provider: guestProvider, game: configuredGame, session: {}, storage: storage()}
	);

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

test('seedTimeoutMs validates its units and defaults to a two-minute exchange', t => {
	const provider = new EventTarget;

	const make = value => new RoomGameProtocol({provider, game: {...game, seedTimeoutMs: value}, session: {}});

	for(const value of [undefined, 1, 30_000, 120_000, 300_000, 7_200_000, 2_147_483_647])
	{
		const protocol = make(value);
		t.after(() => protocol.close());
		assert.equal(protocol.game.seedTimeoutMs, value ?? 120_000);
	}

	for(const value of [null, 0, -1, 1.5, NaN, Infinity, '120000', false, {}, 2_147_483_648, Number.MAX_SAFE_INTEGER])
	assert.throws(() => make(value), {name: 'RangeError', message: /game\.seedTimeoutMs.*milliseconds/});
});

test('games may declare bounded opaque actions while legacy billiards actions stay strict', async t => {
	const provider = new EventTarget;
	provider.profile = () => ({id: 'host'});
	const configured = {
		...game
		, actionTypes: ['shuffle-proof-commitments','draw','fold']
		, maximumActionBytes: 4096
	};
	const protocol = new RoomGameProtocol({provider, game: configured, session: {}});
	t.after(() => protocol.close());
	protocol.connected = true;
	protocol.selected = '$table';
	protocol.sessions.set('$table', {
		root: '$table', localRole: 'player', seats: ['host','guest'], status: 'active', turn: 'host'
		, hash: 'state-0', pending: null, sequence: 0
	});
	const published = [];
	protocol.child = async (session, kind, body) => { published.push({kind, body}); session.pending = body; };
	const action = {type: 'shuffle-proof-commitments', offset: 0, items: [['opaque-card']]};
	await protocol.submitAction(action, 'state-0');
	assert.deepEqual(published, [{kind: 'action',body:{sequence:1,actor:'host',pre_hash:'state-0',action}}]);
	protocol.sessions.get('$table').pending = null;
	for(const invalid of [
		{type:'shot',aim_udeg:0,power_bp:7500,top_bp:0,side_bp:0}
		, {type:'draw',amount:1.5}
		, {type:'draw',constructor:'pollution'}
		, {type:'draw',payload:'x'.repeat(5000)}
	]) await assert.rejects(protocol.submitAction(invalid,'state-0'),/invalid quantized action/);
	for(const bad of [
		{actionTypes:[]},{actionTypes:['Bad']},{actionTypes:['draw','draw']}
		, {actionTypes:['draw'],maximumActionBytes:255},{actionTypes:['draw'],maximumActionBytes:49153}
		, {maximumActionBytes:4096}
		, {checkpointMode:'sometimes'}
		, {actionBatches:'yes'}
	]) assert.throws(()=>new RoomGameProtocol({provider,game:{...game,...bad},session:{}}),/game\./);

	const billiards = new RoomGameProtocol({provider,game,session:{}});
	t.after(()=>billiards.close());
	billiards.connected=true;billiards.selected='$pool';
	billiards.sessions.set('$pool',{root:'$pool',localRole:'player',seats:['host','guest'],status:'active',turn:'host',hash:'pool-0',pending:null,sequence:0});
	billiards.child=async()=>{};
	await billiards.submitAction({type:'shot',aim_udeg:0,power_bp:7500,top_bp:0,side_bp:0},'pool-0');
	await assert.rejects(billiards.submitAction({type:'draw'},'pool-0'),/invalid quantized action/);
});

test('action batches cross actors and publish settlement only at their explicit boundary', async t => {
	const provider = new EventTarget;
	let player = 'host', eventNumber = 0;
	provider.profile = () => ({id: player});
	const configured = {
		...game
		, actionTypes: ['fragment','finish']
		, maximumActionBytes: 4096
		, checkpointMode: 'history'
		, actionBatches: true
	};
	const protocol = new RoomGameProtocol({provider, game: configured, session: {}});
	t.after(() => protocol.close());
	const root = '$batch', published = [], applied = [];
	const table = {
		root
		, offer: {session_id: 'batch-session'}
		, localRole: 'player'
		, seats: ['host','guest']
		, status: 'active'
		, turn: 'host'
		, sequence: 0
		, hash: 'zero'
		, pending: null
		, actions: new Map
		, settles: new Map
		, earlySettles: new Map
		, events: new Set
	};
	protocol.connected = true;
	protocol.selected = root;
	protocol.sessions.set(root, table);
	protocol.adapter = {action: detail => applied.push(detail)};
	protocol.child = async (session, kind, body) => {
		published.push({kind, body: structuredClone(body)});
		await protocol.consume({
			event_id: `$batch-${++eventNumber}`
			, type: 'org.seanmorris.phonics.game.v1'
			, sender: player
			, content: {
				v: 2, kind, session_id: session.offer.session_id, ...structuredClone(body)
				, 'm.relates_to': {rel_type: 'org.seanmorris.phonics.game.v1', event_id: root}
			}
		});
	};

	await protocol.submitAction({type:'fragment',part:1}, 'zero', {settle:false});
	assert.equal(await protocol.reportSettled({sequence:1,hash:'one',nextActor:'guest'}), false);
	assert.deepEqual(published.map(value => value.kind), ['action']);
	assert.equal(table.sequence, 0);
	assert.equal(table.batch.actions.length, 1);

	player = 'guest';
	await protocol.submitAction({type:'finish',part:2}, 'one', {settle:true});
	assert.equal(await protocol.reportSettled({sequence:2,hash:'two',nextActor:'host'}), true);
	assert.deepEqual(published.map(value => value.kind), ['action','action','settled']);
	assert.equal(table.sequence, 0);

	player = 'host';
	assert.equal(await protocol.reportSettled({sequence:2,hash:'two',nextActor:'host'}), true);
	assert.deepEqual(published.map(value => value.kind), ['action','action','settled','settled']);
	assert.equal(table.sequence, 2);
	assert.equal(table.hash, 'two');
	assert.equal(table.turn, 'host');
	assert.equal(table.pending, null);
	assert.equal(table.batch, null);
	assert.deepEqual(applied.map(value => value.sequence), [1,2]);
});

test('reload starts from the confirmed boundary and reapplies every incomplete batch action', async t => {
	const provider = new EventTarget;
	provider.profile = () => ({id:'host'});
	const protocol = new RoomGameProtocol({
		provider
		, game: {
			...game, actionTypes:['fragment'], maximumActionBytes:4096, checkpointMode:'history', actionBatches:true
		}
		, session: {}
	});
	t.after(() => protocol.close());
	const action1={sequence:1,actor:'host',pre_hash:'zero',batch_end:false,action:{type:'fragment',part:1}};
	const action2={sequence:2,actor:'guest',pre_hash:'one',batch_end:false,action:{type:'fragment',part:2}};
	const table={
		root:'$resume-batch',offer:{session_id:'resume-session'},localRole:'player',seats:['host','guest']
		,status:'active',seed:'joint-seed',started:false,turn:'host',sequence:0,hash:'zero',pending:null
		,actions:new Map([[1,action1],[2,action2]]),settles:new Map,seedHandshake:null,batch:{actions:[action1,action2]}
	};
	protocol.connected=true;protocol.selected=table.root;protocol.sessions.set(table.root,table);
	let started;const applied=[];
	protocol.adapter={start:detail=>{started=detail;},action:detail=>applied.push(detail)};
	await protocol.startIfPossible(table);
	assert.equal(started.sequence,0);
	assert.equal(started.hash,'zero');
	assert.deepEqual(started.history,[]);
	assert.deepEqual(applied,[action1,action2]);
});

test('history checkpoint mode settles without snapshots and restores confirmed actions in order', async t => {
	const provider = new EventTarget;
	provider.profile = () => ({id: 'host'});
	const protocol = new RoomGameProtocol({
		provider
		, game: {...game,actionTypes:['deck-shuffle'],maximumActionBytes:4096,checkpointMode:'history'}
		, session: {}
	});
	t.after(()=>protocol.close());
	const action1={sequence:1,actor:'host',pre_hash:'initial',action:{type:'deck-shuffle',deck:['a']}};
	const action2={sequence:2,actor:'guest',pre_hash:'one',action:{type:'deck-shuffle',deck:['b']}};
	const table={
		root:'$cards',offer:{session_id:'cards-session'},localRole:'player',seats:['host','guest']
		, status:'active',seed:'joint-seed',started:false,turn:'host',sequence:2,hash:'two',pending:null
		, actions:new Map([[2,action2],[1,action1]]),settles:new Map,seedHandshake:null
	};
	protocol.connected=true;protocol.selected=table.root;protocol.sessions.set(table.root,table);
	let started;
	protocol.adapter={start:detail=>{started=detail;}};
	await protocol.startIfPossible(table);
	assert.deepEqual(started.history,[action1,action2]);
	assert.equal(started.checkpoint,null);

	table.pending=action2;
	let published;
	protocol.child=async(session,kind,body)=>{published={kind,body};};
	await protocol.reportSettled({sequence:2,hash:'two',nextActor:'host'});
	assert.deepEqual(published,{kind:'settled',body:{player:'host',sequence:2,hash:'two',next_actor:'host',outcome:null}});
});

test('custom seed timeouts share one absolute deadline and expire without a winner', async t => {
	for(const seedTimeoutMs of [15_000, 45_000, 120_000, 300_000, 7_200_000, 2_147_483_647])
	await t.test(`${seedTimeoutMs} ms`, async t => {
		const {hub, hostProvider, host, guest, root, session} = await stalledExchange(t, 'reveal', {seedTimeoutMs});
		const offered = hub.history(hostProvider.room).find(item => item.event_id === root);
		assert.equal(offered.content['org.seanmorris.phonics.game.v1'].game.seedTimeoutMs, seedTimeoutMs);
		const seat = hub.history(hostProvider.room).find(item => item.content.kind === 'seat').content;
		assert.equal(seat.seed_timeout_ms, seedTimeoutMs);
		assert.equal(seat.seed_deadline, session.seedDeadline);
		assert.equal(guest.sessions.get(root).seedDeadline, session.seedDeadline);
		assert.equal(session.seedDeadline, session.commitDeadline + 60_000, 'preserve the durable deadline encoding');
		clearTimeout(session.deadlineTimer);
		t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: session.seedDeadline - seedTimeoutMs});
		host.armRevealDeadline(session);
		t.mock.timers.tick(seedTimeoutMs - 1);
		assert.equal(session.status, 'committing');
		t.mock.timers.tick(1);
		assert.equal(session.status, 'expired');
		assert.equal(session.outcome, undefined);
		assert.equal(hub.history(hostProvider.room).filter(item => item.content.kind === 'resign').length, 0);
	});
});

test('reload restores the recorded custom deadline without restarting its budget', async t => {
	const seedTimeoutMs = 45_000;

	const {hostProvider, host, root, session, retained, protocols} = await stalledExchange(
		t
		, 'reveal'
		, {seedTimeoutMs}
	);

	host.close();
	t.mock.timers.enable({apis: ['Date', 'setTimeout'], now: session.seedDeadline - 5_000});

	const replay = new RoomGameProtocol(
		{provider: hostProvider, game: {...game, seedTimeoutMs}, session: {}, storage: retained}
	);

	protocols.push(replay);
	await replay.connect();
	assert.equal(replay.sessions.get(root).seedDeadline, session.seedDeadline);
	t.mock.timers.tick(4_999);
	assert.equal(replay.sessions.get(root).status, 'committing');
	t.mock.timers.tick(1);
	assert.equal(replay.sessions.get(root).status, 'expired');
	assert.equal(replay.sessions.get(root).outcome, undefined);
});

test('table compatibility includes the timeout and treats omitted values as the historical default', async t => {
	for(const [local, remote, accepted] of [
		[undefined, undefined, true]
		, [undefined, 120_000, true]
		, [120_000, undefined, true]
		, [45_000, 45_000, true]
		, [45_000, undefined, false]
		, [45_000, 120_000, false]
		, [undefined, 45_000, false]
		, [undefined, null, false]
		, [undefined, '120000', false]
		, [undefined, 0, false]
	]){
		const protocol = new RoomGameProtocol(
			{provider: new EventTarget, game: {...game, seedTimeoutMs: local}, session: {}}
		);

		t.after(() => protocol.close());

		await protocol.consume({
			event_id: '$timeout-offer'
			, type: 'org.seanmorris.phonics.game.v1'
			, sender: 'host'
			, content: {
				v: 2
				, kind: 'offer'
				, session_id: 'timeout-offer'
				, host: 'host'
				, game: {...game, seedTimeoutMs: remote}
				, created_at: Date.now()
				, expires_at: Date.now() + 60_000
			}
		});

		assert.equal(protocol.sessions.has('$timeout-offer'), accepted, `${local} vs ${remote}`);
	}
});

test('the starting gun rejects inconsistent timing fields and cannot be replaced to extend its deadline', async t => {
	const now = Date.now();
	t.mock.method(Date, 'now', () => now);
	const fields = {seed_timeout_ms: 120_000, seed_deadline: now + 120_000};
	const {session, consume} = await seedFixture(t, 2, fields);
	assert.equal(session.seedDeadline, fields.seed_deadline);
	await consume('seat', 'host', {...fields, players: ['host', 'guest'], commit_deadline: now + 300_000});
	assert.equal(session.seedDeadline, fields.seed_deadline);

	for(const invalid of [
		{seed_timeout_ms: 300_000}
		, {seed_timeout_ms: null}
		, {seed_timeout_ms: '120000'}
		, {seed_deadline: fields.seed_deadline + 1}, {seed_deadline: null}
		, {seed_deadline: undefined}
		, {seed_timeout_ms: undefined}
		, {commit_deadline: Number.MAX_SAFE_INTEGER}
	]){
		const {session} = await seedFixture(t, 2, {...fields, ...invalid});
		assert.equal(session.seats, undefined, JSON.stringify(invalid));
		assert.equal(session.status, 'open');
	}

	const legacy = await seedFixture(t);
	assert.equal(legacy.session.seedDeadline, now + 120_000);
});

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

test('an application dispute freezes locally without awarding the peer a resignation win', async () => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest', displayName: 'Guest'});
	const protocols = [
		new RoomGameProtocol({provider: hostProvider, game, session: {}, storage: storage()})
		, new RoomGameProtocol({provider: guestProvider, game, session: {}, storage: storage()})
	];
	const stops = [];
	const ports = protocols.map((protocol, index) => protocol.attachGame({
		start: detail => void ports[index].reportReady('initial-hash')
		, stop: detail => stops.push(detail)
	}));
	await Promise.all(protocols.map(protocol => protocol.connect()));
	const root = await protocols[0].create();
	await protocols[1].join(root);
	await until(() => protocols.every(protocol => protocol.snapshot().tables[0]?.status === 'active'));

	ports[0].dispute('Shuffle proof did not verify');
	assert.equal(protocols[0].snapshot().tables[0].status, 'frozen');
	assert.equal(protocols[0].sessions.get(root).outcome, undefined);
	assert.deepEqual(stops, [{reason: 'Shuffle proof did not verify', frozen: true}]);
	assert.equal(hub.history(hostProvider.room).filter(item => item.content.kind === 'resign').length, 0);

	for(const protocol of protocols) protocol.close();
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

const settingsAdapter = (starts, id, overrides = {}) => ({
	start: detail => starts.set(id, detail)
	, action: () => {}
	, confirmed: () => {}
	, validSettings: settings => Number.isSafeInteger(settings?.ante) && settings.ante >= 1 && settings.ante <= 5
		? true
		: 'Ante must be 1 to 5'
	, describeSettings: settings => `Ante ${settings.ante}`
	, ...overrides
});

test('table settings travel in the offer, label the readable message, and reach both starts', async () => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest', displayName: 'Guest'});

	const host = new RoomGameProtocol(
		{provider: hostProvider, game: {...game, title: 'Poker'}, session: {}, storage: storage()}
	);

	const guest = new RoomGameProtocol(
		{provider: guestProvider, game: {...game, title: 'Poker'}, session: {}, storage: storage()}
	);

	const starts = new Map;
	host.attachGame(settingsAdapter(starts, 'host'));
	guest.attachGame(settingsAdapter(starts, 'guest'));
	await host.connect();
	await guest.connect();

	const root = await host.create({settings: {ante: 2}});
	const message = await hostProvider.roomEvent(root);
	assert.match(message.content.body, /^Host opened a Poker table \(Ante 2\)\. Join: https:/);
	assert.match(message.content.formatted_body, /Poker \(Ante 2\)\. <a href=/);
	assert.deepEqual(message.content['org.seanmorris.phonics.game.v1'].settings, {ante: 2});

	await until(() => guest.snapshot().tables.length === 1);
	const listed = guest.snapshot().tables[0];
	assert.deepEqual(listed.settings, {ante: 2});
	assert.equal(listed.settingsLabel, 'Ante 2');
	assert.equal(listed.settingsProblem, null);
	assert.equal(listed.supported, true);
	assert.equal(listed.joinable, true);

	await guest.join(root);
	await until(() => starts.size === 2);
	assert.deepEqual(starts.get('host').settings, {ante: 2});
	assert.deepEqual(starts.get('guest').settings, {ante: 2});
	host.close();
	guest.close();
	hostProvider.close();
	guestProvider.close();
});

test('tables whose settings this build cannot play are listed but cannot be joined', async () => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest', displayName: 'Guest'});
	const plainProvider = new InMemoryLobbyProvider({hub, peerId: 'plain', displayName: 'Plain'});
	const host = new RoomGameProtocol({provider: hostProvider, game, session: {}, storage: storage()});
	const guest = new RoomGameProtocol({provider: guestProvider, game, session: {}, storage: storage()});
	const plain = new RoomGameProtocol({provider: plainProvider, game, session: {}, storage: storage()});
	const starts = new Map;
	// A newer host build allows a larger ante than this guest's build can play.
	host.attachGame(settingsAdapter(starts, 'host', {validSettings: () => true}));
	guest.attachGame(settingsAdapter(starts, 'guest'));
	plain.attachGame({start: () => {}, action: () => {}, confirmed: () => {}});
	await host.connect();
	await guest.connect();
	await plain.connect();

	const root = await host.create({settings: {ante: 9}});
	await until(() => guest.snapshot().tables.length === 1 && plain.snapshot().tables.length === 1);
	const listed = guest.snapshot().tables[0];
	assert.equal(listed.supported, false);
	assert.equal(listed.joinable, false);
	assert.equal(listed.settingsProblem, 'Ante must be 1 to 5');
	await assert.rejects(guest.join(root), /Ante must be 1 to 5/);
	assert.equal(guest.selected, null);

	// A game without settings hooks lists a configured table but refuses it, and still joins plain tables.
	assert.equal(plain.snapshot().tables[0].joinable, false);
	assert.equal(plain.snapshot().tables[0].settingsProblem, 'This game does not use table settings');
	await assert.rejects(plain.join(root), /does not use table settings/);
	assert.equal(hub.history(hostProvider.room).filter(item => item.content?.kind === 'join').length, 0);

	await host.leave();
	const second = await host.create();
	await until(() => plain.snapshot().tables.some(table => table.root === second));
	const open = plain.snapshot().tables.find(table => table.root === second);
	assert.equal(open.settings, null);
	assert.equal(open.settingsLabel, '');
	assert.equal(open.joinable, true);

	for(const item of [host, guest, plain, hostProvider, guestProvider, plainProvider]) item.close();
});

test('malformed or oversized table settings are refused when opening and ignored in offers', async t => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'});
	const host = new RoomGameProtocol({provider: hostProvider, game, session: {}, storage: storage()});
	t.after(() => { host.close(); hostProvider.close(); });

	host.attachGame(settingsAdapter(new Map, 'host', {validSettings: () => true}));
	await host.connect();

	for(const settings of [[1], {ante: 1.5}, 'ante', {big: 'x'.repeat(5000)}, Object.create({ante: 1})])
		await assert.rejects(host.create({settings}), /plain JSON object of at most 4 KB/);

	const strictProvider = new InMemoryLobbyProvider({hub, peerId: 'strict', displayName: 'Strict'});
	const strict = new RoomGameProtocol({provider: strictProvider, game, session: {}, storage: storage()});
	t.after(() => { strict.close(); strictProvider.close(); });

	strict.attachGame(settingsAdapter(new Map, 'strict'));
	await strict.connect();

	await assert.rejects(
		strict.create({settings: {ante: 0}})
		, /Ante must be 1 to 5/
		, 'a host cannot open a table its own build cannot play'
	);

	assert.equal(hub.history(hostProvider.room).length, 0);

	const observer = new EventTarget;

	observer.profile = () => ({id: 'observer'});

	observer.setActivity = async () => {};

	const protocol = new RoomGameProtocol({provider: observer, game, session: {}, storage: storage()});
	t.after(() => protocol.close());

	const offer = (root, settings) => protocol.consume({
		event_id: root
		, type: 'org.seanmorris.phonics.game.v1'
		, sender: 'host'
		, content: {
			v: 2
			, kind: 'offer'
			, session_id: `session-${root}`
			, host: 'host'
			, game
			, created_at: Date.now()
			, expires_at: Date.now() + 60_000
			, settings
		}
	});

	await offer('$oversized', {big: 'x'.repeat(5000)});
	await offer('$array', [1, 2]);
	await offer('$fraction', {ante: 0.5});
	await offer('$plain', {ante: 3});
	assert.deepEqual([...protocol.sessions.keys()], ['$plain']);
});

// Step deadlines run on mocked time: protocol timers and hub server timestamps share the mocked clock, and the
// helpers wait on setImmediate, which stays real.

const flush = async (rounds = 30) => {
	for(let index = 0; index < rounds; index++) await new Promise(resolve => setImmediate(resolve));
};

const waitFor = async (condition, label) => {
	for(let index = 0; index < 2000; index++)
	{
		if(condition()) return;

		await new Promise(resolve => setImmediate(resolve));
	}

	throw new Error(`Timed out waiting for ${label}`);
};

const shot = {type: 'shot', aim_udeg: 0, power_bp: 7500, top_bp: 0, side_bp: 0};

const clockTable = async (t, {timeoutMs = 60_000, configure = {}, ready = {host: true, guest: true}} = {}) => {
	t.mock.timers.enable({apis: ['setTimeout', 'Date'], now: 1_800_000_000_000});
	const hub = new InMemoryLobbyHub;
	const configured = {...game, ...(timeoutMs === null ? {} : {actionTimeoutMs: timeoutMs}), ...configure};
	const providers = {}, protocols = {}, ports = {}, stops = {host: [], guest: []}, actions = {host: [], guest: []};

	for(const id of ['host','guest'])
	{
		providers[id] = new InMemoryLobbyProvider({hub, peerId: id, displayName: id});

		protocols[id] = new RoomGameProtocol(
			{provider: providers[id], game: configured, session: {}, storage: storage()}
		);

		ports[id] = protocols[id].attachGame({
			start: () => { if(ready[id]) void ports[id].reportReady('h0'); }
			, action: detail => actions[id].push(detail)
			, confirmed: () => {}
			, stop: detail => stops[id].push(detail)
		});
	}

	t.after(() => {
		for(const id of ['host','guest'])
		{
			protocols[id].close();
			providers[id].close();
		}
	});

	await protocols.host.connect();
	await protocols.guest.connect();
	const root = await protocols.host.create();
	await protocols.guest.join(root);

	const table = id => protocols[id].sessions.get(root);
	await waitFor(() => table('host')?.seed && table('guest')?.seed, 'the shared seed');

	const claims = () => hub.history(providers.host.room).filter(item => item.content?.kind === 'timeout');

	return {hub, configured, providers, protocols, ports, stops, actions, root, table, claims};
};

const activeClockTable = async (t, options) => {
	const fixture = await clockTable(t, options);
	const {table} = fixture;
	await waitFor(() => table('host').status === 'active' && table('guest').status === 'active', 'an active table');
	const actor = table('host').turn;

	return {...fixture, actor, waiting: actor === 'host' ? 'guest' : 'host'};
};

test('a player who stalls past the step deadline forfeits, and a late move cannot rescue the match', async t => {
	const {protocols, ports, stops, actions, root, table, claims, actor, waiting} = await activeClockTable(t);
	const since = table('host').clockSince;
	const clock = protocols.host.snapshot().tables.find(item => item.root === root).clock;
	assert.deepEqual(clock, {deadline: since + 60_000, stage: 'action', owed: [actor]});

	t.mock.timers.tick(59_000);
	await flush();
	assert.equal(claims().length, 0, 'nobody claims before the deadline');

	// Half a second after the deadline the waiting player has not claimed yet, and the move is already too late.
	t.mock.timers.tick(1_500);
	await ports[actor].submitAction(shot, 'h0');
	await flush();

	for(const id of ['host','guest'])
	{
		assert.equal(table(id).pending ?? null, null, `${id} ignores the late move`);
		assert.equal(actions[id].length, 0);
	}

	// The claim goes out five seconds after the deadline, 65 s after the clock started.
	t.mock.timers.tick(4_499);
	await flush();
	assert.equal(claims().length, 0);
	t.mock.timers.tick(1);
	await waitFor(() => table('host').status === 'complete' && table('guest').status === 'complete', 'the claim');
	const outcome = {winner: waiting, loser: actor, reason: 'timeout', timeout: true};

	for(const id of ['host','guest'])
	{
		assert.deepEqual(table(id).outcome, outcome);
		assert.deepEqual(stops[id].at(-1), outcome);
	}

	assert.equal(claims().length, 1);
	assert.equal(claims()[0].sender, waiting);

	assert.deepEqual(
		{...claims()[0].content, 'm.relates_to': undefined, session_id: undefined, v: undefined}
		, {
			kind: 'timeout'
			, player: waiting
			, against: actor
			, stage: 'action'
			, sequence: 0
			, 'm.relates_to': undefined
			, session_id: undefined
			, v: undefined
		}
	);

	assert.ok(claims()[0].origin_server_ts >= since + 60_000);
	assert.equal(protocols[waiting].snapshot().tables.find(item => item.root === root).clock, null);
});

test('every accepted step restarts the clock, and a missing settlement report forfeits', async t => {
	const {ports, table, claims, actor, waiting} = await activeClockTable(t);
	t.mock.timers.tick(30_000);
	await ports[actor].submitAction(shot, 'h0');
	await waitFor(() => table('host').pending && table('guest').pending, 'the action');
	t.mock.timers.tick(1_000);
	await ports[actor].reportSettled({sequence: 1, hash: 'h1', nextActor: waiting, checkpoint: 'checkpoint-v1'});
	await flush();
	const settledAt = table('host').clockSince;
	assert.deepEqual(table('host').clockSince, table('guest').clockSince);
	assert.equal(settledAt, 1_800_000_000_000 + 31_000, 'the actor report restarted the clock');

	// The original deadline passes without a claim, because the match moved on.
	t.mock.timers.tick(40_000);
	await flush();
	assert.equal(claims().length, 0);
	assert.deepEqual(table('host').status, 'active');

	t.mock.timers.tick(25_000);
	await waitFor(() => table('host').status === 'complete' && table('guest').status === 'complete', 'the claim');
	assert.equal(claims()[0].sender, actor);
	assert.deepEqual(claims()[0].content.stage, 'settle');
	assert.deepEqual(table('guest').outcome, {winner: actor, loser: waiting, reason: 'timeout', timeout: true});
});

test('a missing ready report forfeits, and a table where both players stall expires without a winner', async t => {
	await t.test('one stalled ready report', async t => {
		const {table, claims, stops} = await clockTable(t, {ready: {host: true, guest: false}});
		await waitFor(() => table('host').ready.size === 1 && table('guest').ready.size === 1, 'the host report');
		t.mock.timers.tick(65_000);
		await waitFor(() => table('host').status === 'complete' && table('guest').status === 'complete', 'the claim');
		assert.equal(claims()[0].sender, 'host');
		assert.equal(claims()[0].content.stage, 'ready');
		assert.deepEqual(stops.guest.at(-1), {winner: 'host', loser: 'guest', reason: 'timeout', timeout: true});
	});

	await t.test('both ready reports missing', async t => {
		const {table, claims, stops} = await clockTable(t, {ready: {host: false, guest: false}});
		await waitFor(() => table('host').status === 'ready' && table('guest').status === 'ready', 'the seed');
		t.mock.timers.tick(65_000);
		await waitFor(() => table('host').status === 'expired' && table('guest').status === 'expired', 'expiry');
		assert.equal(claims().length, 0, 'nobody claims a win while also owing a step');
		assert.equal(table('host').outcome, undefined);
		assert.match(table('guest').error, /Both players missed the time limit/);
		assert.equal(stops.host.at(-1).expired, true);
	});
});

test('inside an action batch the local game names who owes the next step', async t => {
	const configure = {
		actionTypes: ['fragment','finish']
		, maximumActionBytes: 4096
		, checkpointMode: 'history'
		, actionBatches: true
	};

	const {protocols, ports, table, claims, root, actor, waiting} = await activeClockTable(t, {configure});
	await ports[actor].submitAction({type: 'fragment', part: 1}, 'h0', {settle: false});
	await waitFor(() => table('host').batch && table('guest').batch, 'the fragment');

	const clock = () => protocols[waiting].snapshot().tables.find(item => item.root === root).clock;
	assert.equal(clock().owed, null, 'the protocol cannot know the batch order by itself');

	// Only the actor's game has reported the fragment so far.
	assert.equal(await ports[actor].reportSettled({sequence: 1, hash: 'f1', nextActor: waiting}), false);
	t.mock.timers.tick(65_000);
	await waitFor(() => claims().length === 1, 'the claim');
	await flush();
	assert.equal(table(actor).status, 'complete');
	assert.equal(table(waiting).status, 'active', 'the stalled browser waits for its own game before judging');
	assert.equal(table(waiting).claims.length, 1);

	assert.equal(await ports[waiting].reportSettled({sequence: 1, hash: 'f1', nextActor: waiting}), false);
	await flush();
	assert.equal(table(waiting).status, 'complete');
	assert.deepEqual(table(waiting).outcome, {winner: actor, loser: waiting, reason: 'timeout', timeout: true});
	assert.deepEqual(clock(), null);
});

test('claims from the player who owes the step, early claims and claims about an old step change nothing', async t => {
	const {protocols, table, claims, actor, waiting} = await activeClockTable(t);

	const claim = async (player, against, body = {}) => protocols[player].child(
		table(player)
		, 'timeout'
		, {player, against, stage: 'action', sequence: 0, ...body}
		, `forged:${Math.random()}`
	);

	await claim(waiting, actor);
	await flush();
	assert.equal(table('host').status, 'active', 'a claim before the deadline is rejected');

	t.mock.timers.tick(61_000);
	await claim(actor, waiting);
	await claim(waiting, actor, {sequence: 4});
	await claim(waiting, actor, {stage: 'settle'});
	await claim(waiting, waiting);
	await flush();

	for(const id of ['host','guest']) assert.equal(table(id).status, 'active');

	assert.equal(claims().length, 5);
	t.mock.timers.tick(4_000);
	await waitFor(() => table('host').status === 'complete' && table('guest').status === 'complete', 'the real claim');
	assert.deepEqual(table('host').outcome.winner, waiting);
});

test('a reloaded reader replays the same timeout result and ignores the late move', async t => {
	const {hub, configured, ports, table, root, actor, waiting} = await activeClockTable(t);
	t.mock.timers.tick(61_000);
	await ports[actor].submitAction(shot, 'h0');
	t.mock.timers.tick(4_000);
	await waitFor(() => table('host').status === 'complete', 'the claim');

	const provider = new InMemoryLobbyProvider({hub, peerId: 'reader', displayName: 'Reader'});
	const reader = new RoomGameProtocol({provider, game: configured, session: {}, storage: storage()});
	t.after(() => { reader.close(); provider.close(); });

	await reader.connect();
	const replayed = reader.sessions.get(root);
	assert.equal(replayed.status, 'complete');
	assert.equal(replayed.sequence, 0);
	assert.equal(replayed.actions.size, 0, 'the late move stays out of the replayed transcript');
	assert.deepEqual(replayed.outcome, {winner: waiting, loser: actor, reason: 'timeout', timeout: true});
});

test('step deadlines are validated, part of table compatibility, and absent unless a game sets one', async t => {
	for(const actionTimeoutMs of [0, 1.5, '60000', 2_147_483_648])
		assert.throws(
			() => new RoomGameProtocol({provider: new EventTarget, game: {...game, actionTimeoutMs}, session: {}})
			, /actionTimeoutMs must be an integer/
		);

	const provider = new EventTarget;

	provider.profile = () => ({id: 'observer'});

	const timed = new RoomGameProtocol(
		{provider, game: {...game, actionTimeoutMs: 60_000}, session: {}, storage: storage()}
	);

	const plain = new RoomGameProtocol({provider, game, session: {}, storage: storage()});
	t.after(() => { timed.close(); plain.close(); });

	const offer = (protocol, root, actionTimeoutMs) => protocol.consume({
		event_id: root
		, type: 'org.seanmorris.phonics.game.v1'
		, sender: 'host'
		, content: {
			v: 2
			, kind: 'offer'
			, session_id: `session-${root}`
			, host: 'host'
			, game: {...game, ...(actionTimeoutMs === undefined ? {} : {actionTimeoutMs})}
			, created_at: Date.now()
			, expires_at: Date.now() + 60_000
		}
	});

	for(const protocol of [timed, plain])
	{
		await offer(protocol, '$none', undefined);
		await offer(protocol, '$minute', 60_000);
		await offer(protocol, '$two-minutes', 120_000);
	}

	assert.deepEqual([...timed.sessions.keys()], ['$minute']);
	assert.deepEqual([...plain.sessions.keys()], ['$none']);
});

test('games without a step deadline publish no clock and ignore timeout claims', async t => {
	const {protocols, table, root, actor, waiting} = await activeClockTable(t, {timeoutMs: null});
	assert.equal(protocols.host.snapshot().tables.find(item => item.root === root).clock, null);
	t.mock.timers.tick(3_600_000);

	await protocols[waiting].child(
		table(waiting)
		, 'timeout'
		, {player: waiting, against: actor, stage: 'action', sequence: 0}
		, 'forged'
	);

	await flush();
	assert.equal(table('host').status, 'active');
});
