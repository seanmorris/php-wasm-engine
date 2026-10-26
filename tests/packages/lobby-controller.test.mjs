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
