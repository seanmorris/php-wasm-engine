import test from 'node:test';
import assert from 'node:assert/strict';
import {MatrixLobbyProvider, MatrixInviteCipher
	, parseMatrixInvite
} from '../../packages/phonics-matrix/browser/matrix.mjs';
import {readInviteLink} from '../../browser/network/InviteLink.mjs';

const prefix = 'org.seanmorris.phonics.';

const reply = (body, status = 200) => new Response(JSON.stringify(body), {status});

const memory = () => {
	const data = new Map;

	return {
		getItem: key => data.get(key) ?? null
		, setItem: (key, value) => data.set(key, value)
		, removeItem: key => data.delete(key)
		, key: index => [...data.keys()][index]
		, get length() { return data.size; }
		, data
	};
};

const capability = () => ({
	v: 1
	, provider: 'matrix'
	, server: 'https://matrix.example'
	, host: '@host:matrix.example'
	, device: 'HOST'
	, id: crypto.randomUUID()
	, secret: Buffer.from(crypto.getRandomValues(new Uint8Array(32))).toString('base64url')
	, expiresAt: Date.now() + 900_000
});

const link = value => `https://game.example/phonics/games/doom-phonics/#phonics-invite=${Buffer.from(
	JSON.stringify(value)
).toString(
	'base64url'
)}`;

const sorted = value => JSON.parse(JSON.stringify(value, Object.keys(value).sort()));

function hostFixture(fetcher = async () => reply({}), storage = memory(), locks = null)
{
	const calls = [];

	const host = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, userId: '@host:matrix.example'
		, deviceId: 'HOST'
		, accessToken: 'host-token'
		, storage
		, locks
		, location: {href: 'https://game.example/phonics/games/doom-phonics/'}
		, fetch: async (url, options) => {
			const target = new URL(url);
			const call = {
				path: decodeURIComponent(target.pathname)
				, search: target.search
				, ...options
				, body: options.body && JSON.parse(options.body)
			};

			calls.push(call);

			if(call.path.endsWith('/register') && call.search === '?kind=guest')
			return reply({
				access_token: 'probe-guest-token'
				, user_id: '@probe:matrix.example'
				, device_id: 'PROBE'
			});

			if(call.path.endsWith('/logout')) return reply({});

			if(call.path.endsWith('/createRoom')) return reply({room_id: '!private:matrix.example'});

			if(call.path.includes('/send/')) return reply({event_id: '$stored'});

			return fetcher(call);
		}
	});

	host.connected = true;

	return {host, calls, storage};
}

test('host probes guest-link support once and revokes the disposable session', async () => {
	const {host, calls} = hostFixture();

	try
	{
		assert.deepEqual(await host.inviteLinkCapability(), {supported: true, reason: null});
		assert.deepEqual(await host.inviteLinkCapability(), {supported: true, reason: null});
		const registrations = calls.filter(call => call.path.endsWith('/register'));
		const logouts = calls.filter(call => call.path.endsWith('/logout'));
		assert.equal(registrations.length, 1);
		assert.equal(registrations[0].headers.Authorization, undefined);
		assert.equal(logouts.length, 1);
		assert.equal(logouts[0].headers.Authorization, 'Bearer probe-guest-token');
	}
	finally
	{
		await host.close();
	}
});

test('guest-disabled homeserver capability prevents private room creation', async () => {
	const calls = [];

	const host = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, userId: '@host:matrix.example'
		, deviceId: 'HOST'
		, accessToken: 'host-token'
		, fetch: async (url, options) => {
			calls.push({url: String(url), options});

			return reply({errcode: 'M_FORBIDDEN', error: 'Guest access is disabled'}, 403);
		}
	});

	host.connected = true;
	const capability = await host.inviteLinkCapability();

	assert.deepEqual(capability, {supported: false, reason: 'guest-registration-disabled'});
	await assert.rejects(host.createInviteLink({}), {code: 'matrix_guest_unavailable'});

	assert.equal(calls.some(call => new URL(call.url).pathname.endsWith('/createRoom')), false);
	await host.close();
});

test('link parsing is read-only, prefix-preserving and rejects executable fields and malformed capabilities', () => {
	const value = capability();
	assert.deepEqual(readInviteLink(link(value)), value);
	assert.deepEqual(parseMatrixInvite(link(value)), value);
	assert.equal(readInviteLink('https://game.example/phonics/games/doom-phonics/'), null);

	for(const invalid of [{...value, application: 'https://evil/app.phar'}
		, {...value, secret: 'short'}
		, {
			...value
			, v: 2
		}
	]){
		assert.throws(() => readInviteLink(link(invalid)));
		assert.throws(() => parseMatrixInvite(link(invalid)));
	}
});

test('cipher authenticates canonicalized Matrix JSON, direction, purpose, time, secret, size and replay', async () => {
	const invitation = capability(), sender = '@guest:matrix.example';
	const cipher = new MatrixInviteCipher(invitation), receiver = new MatrixInviteCipher(invitation);
	const context = {purpose: 'claim', sender, device: 'GUEST', to: invitation.host};
	const envelope = await cipher.seal('claim', sender, 'GUEST', invitation.host, {claimId: 'claim'});
	envelope.header = sorted(envelope.header);
	await assert.rejects(receiver.open(envelope, {...context, sender: '@other:matrix.example'}));
	await assert.rejects(receiver.open(envelope, {...context, purpose: 'admission'}));
	await assert.rejects(receiver.open(envelope, {...context, to: sender}));
	await assert.rejects(new MatrixInviteCipher(capability()).open(envelope, context));
	await assert.rejects(receiver.open({...envelope, ciphertext: 'AAAA'}, context));
	assert.deepEqual(await receiver.open(envelope, context), {claimId: 'claim'});
	assert.equal(await receiver.open(envelope, context), null);
	await assert.rejects(cipher.seal('claim', sender, 'GUEST', invitation.host, {data: 'a'.repeat(128 * 1024)}));
	const expired = structuredClone(envelope);
	expired.header.at = Date.now() - 130_000;
	await assert.rejects(new MatrixInviteCipher(invitation).open(expired, context));
});

test(
	'private room has restricted powers, no alias/federation, encrypted invitation and a retention backstop'
	, async () => {
		const {host, calls, storage} = hostFixture();

		try
		{
			const invite = await host.createInviteLink({map: 'E1M1', resources: ['secret.wad']});
			assert.equal(new URL(invite.url).pathname, '/phonics/games/doom-phonics/');
			const creation = calls.find(call => call.path.endsWith('/createRoom')).body;
			assert.equal(creation.visibility, 'private');
			assert.equal(creation.room_version, '11');
			assert.equal(creation.creation_content['m.federate'], false);
			assert.equal(creation.room_alias_name, undefined);
			assert.equal(creation.power_level_content_override.redact, 100);
			assert.equal(creation.power_level_content_override.events[`${prefix}private.v1`], 0);

			assert.equal(
				creation.initial_state.find(item => item.type === 'm.room.retention').content.max_lifetime
				, 1_800_000
			);

			assert.equal(
				creation.initial_state.find(
					item => item.type === 'm.room.history_visibility'
				).content.history_visibility
				, 'shared'
			);

			const stored = calls.find(call => call.path.includes('/send/')).body;
			assert.equal(JSON.stringify(stored).includes('secret.wad'), false);
			const journal = JSON.stringify(host.readJournal());
			assert.match(journal, /\$stored/);
			assert.equal(journal.includes(parseMatrixInvite(invite.url).secret), false);
			assert.equal(journal.includes('host-token'), false);
		}
		finally
		{
			await host.close();
		}
	}
);

test(
	'only an authenticated first claimant reserves admission; retries are idempotent and racing guests lose'
	, async () => {
		const {host, calls} = hostFixture();

		try
		{
			const invite = await host.createInviteLink({map: 'E1M1'});
			const value = parseMatrixInvite(invite.url), cipher = new MatrixInviteCipher(value);

			const claim = async (sender, claimId, source = cipher) => ({
				type: `${prefix}claim.v1`
				, sender
				, content: await source.seal('claim', sender, 'GUEST', value.host, {claimId})
			});

			const bad = new MatrixInviteCipher({...value, secret: capability().secret});
			await host.consumeDevice(await claim('@bad:matrix.example', 'bad', bad));
			const spoof = await claim('@real:matrix.example', 'spoof');
			await host.consumeDevice({...spoof, sender: '@spoof:matrix.example'});
			assert.equal(host.linkState.claim, undefined);

			await Promise.all([
				claim('@first:matrix.example', 'first').then(item => host.consumeDevice(item))
				, claim('@second:matrix.example', 'second').then(item => host.consumeDevice(item))
			]);

			const winner = host.linkState.peerId, id = host.linkState.claim;
			await host.consumeDevice(await claim(winner, id));
			assert.equal(calls.filter(call => call.path.endsWith('/invite')).length, 1);
			assert.equal(calls.filter(call => call.path.includes('/sendToDevice/')).length, 2);
			assert.equal(host.linkState.peerId, winner);
		}
		finally
		{
			await host.close();
		}
	}
);

test(
	'host redacts both senders across history pages before leave/forget, independently of disposal abort'
	, async () => {
		let pages = 0;

		const {host, calls} = hostFixture(async call => {
			assert.equal(call.signal.aborted, false);

			if(call.path.endsWith('/messages'))
		return reply(++pages === 1
			? {
				chunk: [{
					event_id: '$guest'
					, type: `${prefix}private.v1`
					, sender: '@guest:matrix.example'
					, content: {ciphertext: 'encrypted'}
				}]
				, end: 'page2'
			}
			: {
				chunk: [{
					event_id: '$host'
					, type: `${prefix}private.v1`
					, sender: '@host:matrix.example'
					, content: {ciphertext: 'encrypted'}
				}]
			}
		);

			return reply({});
		});

		await host.createInviteLink({});
		await host.close();
		assert.equal(pages, 2);
		const paths = calls.map(call => call.path);
		const revoked = paths.findIndex(path => path.endsWith('/state/m.room.power_levels'));
		const redacted = paths.findIndex(path => path.includes('/redact/$guest/'));
		const left = paths.findIndex(path => path.endsWith('/leave'));
		assert.ok(revoked < redacted && redacted < left);
		assert.ok(paths.some(path => path.includes('/redact/$host/')));
		assert.ok(paths.at(-1).endsWith('/forget'));
		assert.deepEqual(host.cleanupJournal, {});
	}
);

test('failed cleanup persists without leaving, retry merges other tabs, and active locks prevent cleanup', async () => {
	let fail = true;

	const {host, calls, storage} = hostFixture(
		async call => fail && call.path.includes('/redact/') ? reply({errcode: 'M_LIMIT_EXCEEDED'}, 429) : reply({})
	);

	await host.createInviteLink({});
	await host.cancelInviteLink();
	assert.equal(calls.some(call => call.path.endsWith('/leave')), false);
	assert.equal(host.cleanupJournal['!private:matrix.example'].status, 'pending');
	storage.setItem(host.journalKey() + ':room:!other:matrix.example', JSON.stringify({status: 'active'}));
	fail = false;
	await host.retryCleanup();
	assert.deepEqual(host.cleanupJournal, {'!other:matrix.example': {status: 'active'}});
	host.locks = {
		request: async (name, options, action) => { assert.equal(options.ifAvailable, true); await action(null); }
	};

	const count = calls.length;
	await host.retryCleanup();
	assert.equal(calls.length, count);
	await host.close();
});

test('guest registration is explicit, isolated from host storage and fails without onboarding fallback', async () => {
	const storage = memory(), calls = [];
	storage.setItem('phonics.matrix.session:https://matrix.example', JSON.stringify({accessToken: 'saved-host'}));

	const guest = new MatrixLobbyProvider({
		homeserver: 'https://matrix.example'
		, guest: true
		, storage
		, fetch: async (url, options) => { calls.push({url, options}); return reply({errcode: 'M_FORBIDDEN'}, 403); }
	});

	assert.equal(calls.length, 0);
	assert.equal(guest.accessToken, null);
	await assert.rejects(guest.joinInviteLink(capability()), {code: 'matrix_guest_unavailable'});
	assert.equal(calls.length, 1);
	assert.match(String(calls[0].url), /register\?kind=guest$/);
	assert.equal(calls[0].options.headers.Authorization, undefined);
	assert.match(storage.getItem('phonics.matrix.session:https://matrix.example'), /saved-host/);
	await guest.close();
});

test('parallel replay decryption admits an envelope only once', async () => {
	const invitation = capability(), sender = '@guest:matrix.example';
	const senderCipher = new MatrixInviteCipher(invitation), receiver = new MatrixInviteCipher(invitation);
	const envelope = await senderCipher.seal('claim', sender, 'DEVICE', invitation.host, {claimId: 'one'});
	const context = {purpose: 'claim', sender, device: 'DEVICE', to: invitation.host};
	const results = await Promise.all([receiver.open(envelope, context), receiver.open(envelope, context)]);
	assert.equal(results.filter(Boolean).length, 1);
});

test('room journals from different tabs cannot overwrite each other', () => {
	const storage = memory();
	const first = hostFixture(undefined, storage).host, second = hostFixture(undefined, storage).host;
	first.journalRoom('!first:matrix.example', 'active');
	second.journalRoom('!second:matrix.example', 'pending');
	first.journalRoom('!first:matrix.example', 'done');
	assert.deepEqual(Object.keys(second.readJournal()), ['!second:matrix.example']);
});

test('limited sync backfills guest departure before processing newer signaling', async () => {
	const {host} = hostFixture(async call => {
		if(call.path.endsWith('/sync'))
		return reply({
			next_batch: 'next'
			, rooms: {
				join: {
					'!private:matrix.example': {timeline: {limited: true, prev_batch: 'older', events: []}}
				}
			}
		});

		if(call.path.endsWith('/messages'))
		return reply({
			chunk: [
				{
					event_id: '$left'
					, type: 'm.room.member'
					, state_key: '@guest:matrix.example'
					, content: {
						membership: 'leave'
					}
				}
				, {event_id: '$previous', type: 'ignored', content: {}}
			]
		});

		return reply({});
	});

	try
	{
		await host.createInviteLink({map: 'E1M1'});
		host.linkState.peerId = '@guest:matrix.example';
		host.linkState.lastEvent = '$previous';
		await host.syncOnce();
		assert.equal(host.linkState, null);
		assert.equal(host.since, 'next');
	}
	finally
	{
		await host.close();
	}
});

test('unavailable persistent storage retains cleanup obligations for the active session', async () => {
	const storage = {
		getItem: () => null
		, key: () => null
		, length: 0

		, setItem() { throw new Error('Quota exceeded'); }

		, removeItem() { throw new Error('Quota exceeded'); }
	};

	const {host} = hostFixture(
		async call => call.path.includes('/state/') ? reply({errcode: 'M_UNKNOWN'}, 503) : reply({})
		, storage
	);

	await host.createInviteLink({map: 'E1M1'});
	await host.cancelInviteLink();
	assert.equal(host.readJournal()['!private:matrix.example'].status, 'pending');
	await host.close();
});
