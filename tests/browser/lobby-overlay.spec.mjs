import {test, expect} from '@playwright/test';

const ME = '@sean:matrix.org';
const ALICE = '@alice_w:matrix.org';

// Build the world lobby over a Matrix-shaped provider stub: real identifiers, display names, and invitations.

const openLobby = async(page, {signedIn = true} = {}) => {
	await page.goto('/preview/');

	await page.evaluate(async({ME, ALICE, signedIn}) => {
		const {LobbyOverlay} = await import('/engine/browser/network/LobbyOverlay.mjs');
		const {LobbyController} = await import('/engine/browser/network/LobbyController.mjs');
		const {GameLauncher} = await import('/engine/browser/launcher/GameLauncher.mjs');

		const event = (type, detail) => new CustomEvent(type, {detail});

		class Provider extends EventTarget
		{
			homeserver = 'https://matrix.org';
			room = '#lobby:matrix.org';
			sent = [];
			cancelled = [];

			profile() { return {id: ME, displayName: 'Sean'}; }

			async connect()
			{
				if(signedIn) return this.profile();

				const error = new Error('Sign in');
				error.code = 'matrix_auth_required';

				throw error;
			}

			async invite(to, payload) { return {id: 'out-1', from: ME, to, payload, expiresAt: Date.now() + 60_000}; }

			async respond() {}

			async cancel(id) { this.cancelled.push(id); }

			async chat(text)
			{
				this.sent.push(text);
				this.dispatchEvent(event('chat', {from: ME, text, sentAt: Date.now()}));
			}

			async signalPeer() {}
		}

		const fields = [
			{
				name: 'mode'
				, label: 'Mode'
				, type: 'select'
				, default: 'deathmatch'
				, options: [{value: 'deathmatch', label: 'Deathmatch'}, {value: 'cooperative', label: 'Co-op'}]
			}
			, {name: 'skill', label: 'Skill', type: 'select', default: '2', options: ['0', '1', '2', '3', '4']}
		];

		const world = {
			network: {lobby: {title: 'Test Online'}, match: {fields}}
			, resourceSlots: {}
			, sources: {}
			, start: {source: 'test', scope: 'MAP01'}
		};

		const frame = document.createElement('div');
		document.body.append(frame);
		const provider = new Provider;

		const controller = new LobbyController({
			provider
			, transportFactory: () => new Promise(() => {})
			, prepareInvitation: async value => ({publicPayload: value})
		});

		const launcher = new GameLauncher({negotiator: {preview: async() => ({})}, frame, world});
		const overlay = new LobbyOverlay({controller, launcher, frame, world, configuration: {}});
		Object.assign(window, {provider, controller, launcher, overlay, event});
		overlay.open();
		await controller.connect().catch(() => {});

		if(signedIn)
		{
			provider.dispatchEvent(
				event('presence', {peers: [provider.profile(), {id: ALICE, displayName: 'Alice W.'}]})
			);

			provider.dispatchEvent(event('chat', {from: ALICE, text: 'deathmatch?', sentAt: Date.now()}));
		}
	}, {ME, ALICE, signedIn});

	return page.locator('.phonics-lobby');
};

test('the world lobby explains sign-in before showing the room', async({page}) => {
	const lobby = await openLobby(page, {signedIn: false});

	await expect(lobby).toHaveAttribute('open', '');
	await expect(lobby).toHaveAttribute('data-view', 'gate');
	await expect(lobby.locator('.phonics-lobby-status')).toHaveText('Sign-in required');
	await expect(lobby.locator('.phonics-lobby-status')).toHaveAttribute('data-state', 'auth-required');
	await expect(lobby.getByRole('button', {name: 'Sign in with Matrix'})).toBeVisible();
	await expect(lobby.getByRole('log', {name: 'Lobby chat'})).toBeHidden();
	await expect(lobby.getByRole('button', {name: 'Game settings', exact: true})).toBeVisible();
});

test('the world lobby names people, offers, and chat by display name', async({page}) => {
	const lobby = await openLobby(page);

	await expect(lobby).toHaveAttribute('data-view', 'room');
	await expect(lobby.locator('.phonics-lobby-status')).toHaveText('Connected');
	await expect(lobby.getByRole('list', {name: 'Available players'})).toContainText('Alice W.');
	await expect(lobby.getByRole('list', {name: 'Available players'})).toContainText('Sean (you)');
	await expect(lobby.getByRole('log', {name: 'Lobby chat'})).toContainText('Alice W.');
	await expect(lobby).not.toContainText(ALICE);

	await page.evaluate(({ME, ALICE}) => provider.dispatchEvent(event('invitation', {
		invitation: {
			id: 'in-1'
			, from: ALICE
			, to: ME
			, expiresAt: Date.now() + 90_000
			, payload: {
				protocol: '1.0.0'
				, sessionId: 's'
				, start: {scope: 'E1M3'}
				, settings: {mode: 'deathmatch', skill: '3'}
				, content: [{order: 0, label: 'DOOM1.WAD'}]
			}
		}
	})), {ME, ALICE});

	const offers = lobby.getByRole('list', {name: 'Game offers'});
	await expect(offers).toContainText('Alice W. invited you');
	await expect(offers).toContainText('Deathmatch · E1M3 · Skill 3');
	await expect(offers).toContainText('Uses DOOM1.WAD');
	await expect(offers).toContainText(/1:[23]\d left/);
	await expect(lobby.locator('.phonics-lobby-status')).toHaveText('You have an invite');
	await lobby.getByRole('button', {name: 'Decline Alice W.’s invite'}).click();
	await expect(offers.locator('li')).toHaveCount(0);
	await expect(lobby.locator('.phonics-lobby-status')).toHaveAttribute('data-state', 'available');

	// Escape clears an unsent message before it closes the lobby.
	const message = lobby.getByRole('textbox', {name: 'Message'});
	await message.fill('draft');
	await message.press('Escape');
	await expect(message).toHaveValue('');
	await expect(lobby).toHaveAttribute('open', '');
	await message.fill('hello');
	await message.press('Enter');
	await expect(lobby.locator('.phonics-ui-message.is-own')).toContainText('hello');
	await expect(lobby.locator('.phonics-ui-message.is-own b')).toHaveText('You');
	await expect(message).toHaveValue('');
});

test('a sent invite can be withdrawn from its waiting card', async({page}) => {
	const lobby = await openLobby(page);

	await lobby.getByRole('button', {name: 'Invite Alice W.'}).click();
	const launcher = page.locator('.phonics-launcher');
	await expect(launcher.getByRole('heading', {name: 'Invite Alice W.'})).toBeVisible();
	await launcher.getByRole('button', {name: 'Send invite'}).click();
	await expect(launcher).not.toHaveAttribute('open', '');

	await expect(lobby).toContainText('Waiting for Alice W. to accept');
	await expect(lobby).toContainText(/Deathmatch · MAP01 · Skill 2\. They have \d:\d\d to answer\./);
	await expect(lobby.getByRole('list', {name: 'Available players'})).toContainText('Invite sent');
	await lobby.getByRole('button', {name: 'Cancel invite'}).click();
	await expect(lobby.locator('.phonics-lobby-status')).toHaveAttribute('data-state', 'available');
	await expect(lobby.getByRole('button', {name: 'Invite Alice W.'})).toBeEnabled();
	expect(await page.evaluate(() => provider.cancelled)).toEqual(['out-1']);
});
