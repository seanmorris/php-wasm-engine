import {test, expect} from '@playwright/test';

// Play a seated in-memory match in the embedded room lobby and fast-forward the page clock through a step deadline.

const startMatch = async page => {
	await page.clock.install({time: new Date('2026-11-07T12:00:00Z')});
	await page.goto('/preview/');

	await page.evaluate(async() => {
		const {InMemoryLobbyHub, InMemoryLobbyProvider} = await import(
			'/engine/browser/network/InMemoryLobbyProvider.mjs'
		);

		const {RoomGameProtocol, EmbeddedRoomLobby} = await import('/engine/browser/network/RoomGameLobby.mjs');
		const values = new Map;

		const storage = () => ({
			getItem: key => values.get(key) ?? null
			, setItem: (key, value) => values.set(key, value)
			, removeItem: key => values.delete(key)
		});

		const game = {
			id: 'org.example.cards'
			, protocol: '1.0.0'
			, rules: 'cards-v1'
			, title: 'Cards'
			, actionTimeoutMs: 120_000
		};

		const hub = new InMemoryLobbyHub;
		const protocols = {}, ports = {}, stops = [];

		for(const id of ['host','guest'])
		{
			const provider = new InMemoryLobbyProvider(
				{hub, peerId: id, displayName: id === 'host' ? 'Host' : 'Guest'}
			);

			protocols[id] = new RoomGameProtocol({provider, game, session: {}, storage: storage()});

			ports[id] = protocols[id].attachGame({
				start: () => void ports[id].reportReady('h0')
				, action: () => {}
				, confirmed: () => {}
				, stop: detail => { if(id === 'host') stops.push(detail); }
			});
		}

		const frame = document.createElement('div');
		frame.style.cssText = 'position:relative;width:960px;height:640px';
		const canvas = document.createElement('canvas');
		frame.append(canvas);
		document.body.append(frame);
		const overlay = new EmbeddedRoomLobby({protocol: protocols.host, canvas, title: 'Cards Online'});
		await protocols.guest.connect();
		await overlay.open();
		const root = await protocols.host.create();
		await protocols.guest.join(root);
		Object.assign(window, {protocols, root, stops});
	});

	await page.waitForFunction(() => protocols.host.sessions.get(root)?.status === 'active');

	return page.evaluate(() => protocols.host.sessions.get(root).turn);
};

test('the in-game sidebar counts down the last minute of a step and reports the timeout result', async({page}) => {
	const actor = await startMatch(page);
	const clock = page.locator('.phonics-room-clock');
	await expect(page.locator('.phonics-room-game')).toHaveClass(/has-game/);
	await expect(clock).toBeHidden();

	// The countdown appears only in the step's last minute.
	await page.clock.runFor(61_000);
	await expect(clock).toBeVisible();

	if(actor === 'host')
	{
		await expect(clock).toHaveText(/^Your move · 0:5\d left$/);
		await expect(clock).toHaveClass(/is-urgent/);
	}
	else
	{
		await expect(clock).toHaveText(/^Waiting for Guest · 0:5\d left$/);
		await expect(clock).not.toHaveClass(/is-urgent/);
	}

	// Past the deadline the waiting player's browser claims the match five seconds later.
	await page.clock.runFor(64_000);
	await page.waitForFunction(() => protocols.host.sessions.get(root).status === 'complete');
	const outcome = await page.evaluate(() => stops.at(-1));
	const waiting = actor === 'host' ? 'guest' : 'host';
	expect(outcome).toEqual({winner: waiting, loser: actor, reason: 'timeout', timeout: true});
	await expect(clock).toBeHidden();
});
