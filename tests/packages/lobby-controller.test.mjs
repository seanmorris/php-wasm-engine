import test from 'node:test';
import assert from 'node:assert/strict';
import {LobbyController} from '../../browser/network/LobbyController.mjs';
import {
	InMemoryLobbyHub
	, InMemoryLobbyProvider
} from '../../browser/network/InMemoryLobbyProvider.mjs';

class FakeTransport extends EventTarget
{

	constructor(options) { super(); this.options = options; this.signals = []; this.closed = false; }

	async start({initiator}) { this.initiator = initiator; return this; }

	async receiveSignal(value) { this.signals.push(value); }

	close(reason)
	{
		if(this.closed) return;

		this.closed = true;
		this.dispatchEvent(new CustomEvent('close', {detail: {reason}}));
	}
}

const waitState = (controller, state) => controller.state === state
	? Promise.resolve(controller.snapshot())
	: new Promise(resolve => {

		const changed = ({detail}) => {
			if(detail.state !== state) return;

			controller.removeEventListener('statechange', changed);
			resolve(detail);
		};

		controller.addEventListener('statechange', changed);
	});

test('lobby controller carries invitations into role-aware peer transports', async () => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest'});
	const transports = [];

	const factory = options => {
		const transport = new FakeTransport(options);
		transports.push(transport);

		return transport;
	};

	const host = new LobbyController({provider: hostProvider, transportFactory: factory});
	const guest = new LobbyController({provider: guestProvider, transportFactory: factory});

	await host.connect();
	await guest.connect();
	const invited = waitState(guest, 'invited');
	await host.invite('guest', {mode: 'deathmatch'});
	const invitation = (await invited).invitations[0];
	const hostConnected = waitState(host, 'connected');
	const guestConnected = waitState(guest, 'connected');
	await guest.accept(invitation);
	await Promise.all([hostConnected, guestConnected]);

	assert.equal(host.match.peerId, 'guest');
	assert.equal(guest.match.peerId, 'host');
	assert.equal(transports.find(value => value.initiator).options.polite, false);
	assert.equal(transports.find(value => !value.initiator).options.polite, true);
	host.close();
	guest.close();
});

test('a peer channel that closes mid-match ends the match and keeps the lobby available', async () => {
	const hub = new InMemoryLobbyHub;
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host'});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest'});
	const transports = [];

	const factory = options => {
		const transport = new FakeTransport(options);
		transports.push(transport);

		return transport;
	};

	const host = new LobbyController({provider: hostProvider, transportFactory: factory});
	const guest = new LobbyController({provider: guestProvider, transportFactory: factory});
	await host.connect();
	await guest.connect();

	const connect = async () => {
		const invited = waitState(guest, 'invited');
		await host.invite('guest', {mode: 'deathmatch'});
		const invitation = (await invited).invitations[0];
		const connected = [waitState(host, 'connected'), waitState(guest, 'connected')];
		await guest.accept(invitation);
		await Promise.all(connected);
	};

	await connect();
	const ended = [], errors = [];
	guest.addEventListener('matchend', ({detail}) => ended.push(detail.reason));
	guest.addEventListener('error', ({detail}) => errors.push(detail));

	// WebRtcTransport reports a data channel the peer closed as channel_closed.
	const closed = new Error('control channel closed');
	closed.code = 'channel_closed';
	transports.find(value => !value.initiator).dispatchEvent(new CustomEvent('error', {detail: closed}));

	assert.equal(guest.state, 'available', 'the lobby stays usable');
	assert.deepEqual(ended, ['control channel closed']);
	assert.deepEqual(errors, []);
	assert.equal(guest.transport, null);

	// The same controllers can start another match.
	host.closeMatch('peer disconnected');
	await waitState(host, 'available');
	await connect();
	assert.equal(guest.state, 'connected');

	// Any other transport failure still fails the lobby.
	const broken = new Error('signaling broke');
	broken.code = 'webrtc_signal';
	const guestTransport = transports.filter(value => !value.initiator).at(-1);
	guestTransport.dispatchEvent(new CustomEvent('error', {detail: broken}));
	assert.equal(guest.state, 'failed');
	host.close();
	guest.close();
});

test('unanswered and withdrawn invitations release both lobbies', async () => {
	const hub = new InMemoryLobbyHub;

	const factory = options => new FakeTransport(options);
	const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host', invitationTtl: 80});
	const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest'});
	const host = new LobbyController({provider: hostProvider, transportFactory: factory});
	const guest = new LobbyController({provider: guestProvider, transportFactory: factory});
	await host.connect();
	await guest.connect();

	// Nobody answers: the offer leaves the guest's list and the host may invite again.
	const invited = waitState(guest, 'invited');
	await host.invite('guest', {mode: 'deathmatch'});
	await invited;
	const hostReleased = waitState(host, 'available');
	const guestReleased = waitState(guest, 'available');
	const [hostState, guestState] = await Promise.all([hostReleased, guestReleased]);
	assert.deepEqual(hostState.expired, {direction: 'outgoing', to: 'guest'});
	assert.ok(guestState.expired?.from === 'host' || guestState.withdrawn?.from === 'host');
	assert.equal(guest.invitations.size, 0);

	// The host withdraws a fresh offer before it expires.
	hostProvider.invitationTtl = 30_000;
	const again = waitState(guest, 'invited');
	await host.invite('guest', {mode: 'cooperative'});
	await again;
	const withdrawn = waitState(guest, 'available');
	await host.cancel();
	assert.equal((await withdrawn).withdrawn.from, 'host');
	assert.equal(guest.invitations.size, 0);
	assert.equal(host.expiries.size, 0);
	assert.equal(guest.expiries.size, 0);
	await guest.invite('host', {mode: 'deathmatch'});
	assert.equal(guest.state, 'inviting');
	host.close();
	guest.close();
	assert.equal(guest.expiries.size, 0);
});

test('cancelled signaling cannot fail a replacement match', async () => {
	for(const queued of [true, false])
	{
		let rejectSignal, applying;
		const entered = new Promise(resolve => { applying = resolve; });

		const transport = new FakeTransport;

		transport.receiveSignal = () => new Promise((resolve, reject) => {
			rejectSignal = reject;
			applying();
		});

		let created = 0;

		const controller = new LobbyController({
			provider: new InMemoryLobbyProvider({hub: new InMemoryLobbyHub, peerId: 'local'})
			, transportFactory: () => ++created === 1 ? transport : new FakeTransport
		});

		await controller.connect();
		const signal = {from: 'peer', payload: {sessionId: 'old', payload: {description: {type: 'offer'}}}};

		if(queued) await controller.receiveSignal(signal);

		const connecting = controller.connectPeer({peerId: 'peer', sessionId: 'old', initiator: false});

		if(!queued) await connecting;

		const pending = queued ? connecting : controller.receiveSignal(signal);
		await entered;
		controller.closeMatch();
		const replacement = await controller.connectPeer({peerId: 'peer', sessionId: 'new', initiator: true});
		rejectSignal(new Error('old peer connection closed'));
		await assert.doesNotReject(() => pending);
		assert.equal(controller.transport, replacement);
		assert.equal(controller.match.sessionId, 'new');
		assert.equal(controller.state, 'connected');
		controller.close();
	}
});

test('lobby controller exposes authentication-required state', async () => {

	class AuthProvider extends EventTarget
	{

		async connect() { const error = new Error('login'); error.code = 'matrix_auth_required'; throw error; }

		close() {}
	}

	const controller = new LobbyController({provider: new AuthProvider, transportFactory: () => new FakeTransport});

	await assert.rejects(() => controller.connect(), /login/);
	assert.equal(controller.state, 'auth-required');
	controller.close();
});

test('lobby controller completes popup OAuth before reconnecting', async () => {

	class AuthProvider extends EventTarget
	{

		authorized = false;

		async connect()
		{
			if(!this.authorized)
			{
				const error = new Error('login');
				error.code = 'matrix_auth_required';

				throw error;
			}

			return {id: '@player:matrix.example'};
		}

		async completeOAuth(value, options)
		{
			this.callback = value;
			this.options = options;
			this.authorized = true;

			return true;
		}

		close() {}
	}

	const provider = new AuthProvider;
	const controller = new LobbyController({provider, transportFactory: () => new FakeTransport});

	await assert.rejects(() => controller.connect(), /login/);
	const profile = await controller.completeOAuth('https://game.example/#state=value&code=code');

	assert.equal(controller.state, 'available');
	assert.equal(profile.id, '@player:matrix.example');
	assert.equal(provider.callback, 'https://game.example/#state=value&code=code');
	assert.deepEqual(provider.options, {replaceHistory: false});
	controller.close();
});

test('lobby controller signs out an idle authenticated provider and can authenticate again', async () => {

	class AuthProvider extends EventTarget
	{

		authorized = true;

		async connect()
		{
			if(!this.authorized)
			{
				const error = new Error('login');
				error.code = 'matrix_auth_required';

				throw error;
			}

			return {id: '@player:matrix.example'};
		}

		async logout()
		{
			this.authorized = false;

			return {remote: true, failures: []};
		}

		async completeOAuth()
		{
			this.authorized = true;

			return true;
		}

		close() {}
	}

	const provider = new AuthProvider;
	const controller = new LobbyController({provider, transportFactory: () => new FakeTransport});
	await controller.connect();
	const result = await controller.logout();

	assert.equal(controller.state, 'auth-required');
	assert.equal(controller.snapshot().profile, null);
	assert.equal(controller.snapshot().logoutResult.remote, true);
	assert.deepEqual(result, {remote: true, failures: []});
	await controller.completeOAuth('https://game.example/#state=value&code=code');
	assert.equal(controller.state, 'available');
	assert.equal(controller.snapshot().profile.id, '@player:matrix.example');
	controller.close();
});

test('lobby controller hides private links until homeserver guest support is verified', async () => {
	const checked = Promise.withResolvers();

	class LinkProvider extends EventTarget
	{
		homeserver = 'https://matrix.example';

		async connect() { return {id: '@host:matrix.example'}; }

		async inviteLinkCapability() { return checked.promise; }

		async createInviteLink() { throw new Error('must remain hidden'); }

		close() {}
	}

	const controller = new LobbyController({provider: new LinkProvider, transportFactory: () => new FakeTransport});
	await controller.connect();
	assert.equal(controller.snapshot().inviteLinkSupport, 'checking');
	const updated = new Promise(resolve => controller.addEventListener('update', resolve, {once: true}));
	checked.resolve({supported: false, reason: 'guest-registration-disabled'});
	await updated;
	assert.equal(controller.snapshot().inviteLinkSupport, 'unsupported');
	await assert.rejects(controller.createInviteLink({}), {code: 'invite_unsupported'});
	controller.close();
});
