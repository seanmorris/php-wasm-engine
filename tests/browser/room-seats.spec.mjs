import {test, expect} from '@playwright/test';

// Two lobbies for one account share this page the way two tabs share a browser: the same Web Locks and the same
// BroadcastChannel. Only one of them may play the seat; the other offers to switch tabs or take the seat over.

const openTabs = async page => {
	await page.goto('/preview/');

	await page.evaluate(async() => {
		const {InMemoryLobbyHub, InMemoryLobbyProvider} = await import(
			'/engine/browser/network/InMemoryLobbyProvider.mjs'
		);

		const {RoomGameProtocol, EmbeddedRoomLobby} = await import('/engine/browser/network/RoomGameLobby.mjs');

		const memory = () => {
			const values = new Map;

			return {
				getItem: key => values.get(key) ?? null
				, setItem: (key, value) => values.set(key, value)
				, removeItem: key => values.delete(key)
			};
		};

		const game = {id: `org.example.seats.${crypto.randomUUID()}`, protocol: '1.0.0', rules: 'seats-v1'};
		const hub = new InMemoryLobbyHub;
		const shared = memory();
		const starts = {first: [], second: []};

		const host = new RoomGameProtocol({
			provider: new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'})
			, game
			, session: {}
			, storage: memory()
		});

		const adapter = (name, protocol) => {
			const port = protocol.attachGame({
				start: detail => {
					starts[name]?.push(detail);

					if(detail.role === 'player') void port.reportReady('h0').catch(() => {});
				}
				, action: () => {}
				, confirmed: () => {}
				, stop: () => {}
			});

			return port;
		};

		adapter('host', host);
		const tabs = {};

		for(const name of ['first','second'])
		{
			const protocol = new RoomGameProtocol({
				provider: new InMemoryLobbyProvider({hub, peerId: 'guest', displayName: 'Guest', tab: name})
				, game
				, session: {}
				, storage: shared
			});

			adapter(name, protocol);
			const frame = document.createElement('div');
			frame.dataset.tab = name;
			frame.style.cssText = 'position:relative;display:inline-block;width:620px;height:600px';
			const canvas = document.createElement('canvas');
			frame.append(canvas);
			document.body.append(frame);
			const overlay = new EmbeddedRoomLobby({protocol, canvas, title: `Seats ${name}`});
			tabs[name] = {protocol, overlay};
		}

		await host.connect();
		await tabs.first.protocol.connect();
		await tabs.second.protocol.connect();
		await tabs.first.overlay.open();
		await tabs.second.overlay.open();
		Object.assign(window, {hub, host, tabs, starts});
	});

	return name => page.locator(`[data-tab="${name}"] .phonics-room-game`);
};

const active = page => page.waitForFunction(() => {
	const root = window.host.selected;

	return root && window.tabs.first.protocol.sessions.get(root)?.status === 'active';
});

test('a second tab on the same seat stands by, takes the seat over, and hands it back', async({page}) => {
	const tab = await openTabs(page);
	const root = await page.evaluate(() => window.host.create());
	await page.evaluate(table => window.tabs.first.protocol.join(table), root);
	await active(page);

	const seat = name => tab(name).locator('.phonics-room-seat');
	await expect(seat('first')).toBeHidden();

	// The second tab resumes the same seat from its table list and stands by instead of playing.
	await tab('second').getByRole('button', {name: 'Resume'}).click();
	await expect(seat('second')).toBeVisible();
	await expect(seat('second').locator('strong')).toHaveText('This game is open in another tab');
	await expect(seat('first')).toBeHidden();
	await expect(tab('second').locator('.phonics-room-match button')).toHaveText('Back to lobby');
	await expect(tab('first').locator('.phonics-room-match button')).toHaveText('Leave table');
	expect(await page.evaluate(() => window.starts.second.at(-1)?.standby)).toBe(true);

	// Playing here sends the other tab to the same card.
	await seat('second').getByRole('button', {name: 'Play here instead'}).click();
	await expect(seat('first')).toBeVisible();
	await expect(seat('second')).toBeHidden();
	await expect(tab('first').locator('.phonics-room-match button')).toHaveText('Back to lobby');
	await expect(tab('second').locator('.phonics-room-match button')).toHaveText('Leave table');
	await page.waitForFunction(() => window.starts.second.at(-1)?.role === 'player');

	// Going to the other tab asks it forward; it flashes its title because browsers rarely let it raise itself.
	const title = await page.title();
	await seat('first').getByRole('button', {name: 'Go to that tab'}).click();
	await expect(seat('first').locator('p')).toContainText('title is flashing');
	await expect(page).toHaveTitle('● Your game is here');

	// The card restores its text when it appears again, and the first tab can take the seat back.
	await seat('first').getByRole('button', {name: 'Play here instead'}).click();
	await expect(seat('second')).toBeVisible();
	await expect(seat('second').locator('p')).toContainText('Play continues in that tab');
	await expect(seat('first')).toBeHidden();

	// Leaving a standby tab needs no forfeit confirmation and resigns nothing.
	await tab('second').locator('.phonics-room-match button').click();
	await expect(seat('second')).toBeHidden();
	await expect(tab('second')).not.toHaveClass(/has-game/);
	await expect(tab('first')).toHaveClass(/has-game/);

	const outcome = table => ({
		status: window.host.sessions.get(table).status
		, resigns: window.hub.history('phonics').filter(item => item.content?.kind === 'resign').length
	});

	expect(await page.evaluate(outcome, root)).toEqual({status: 'active', resigns: 0});

	await expect(page).toHaveTitle(title, {timeout: 20_000});
});

test('tabs on different tables both play', async({page}) => {
	const tab = await openTabs(page);

	await page.evaluate(async() => {
		const {InMemoryLobbyProvider} = await import('/engine/browser/network/InMemoryLobbyProvider.mjs');
		const {RoomGameProtocol} = await import('/engine/browser/network/RoomGameLobby.mjs');

		const rival = new RoomGameProtocol({
			provider: new InMemoryLobbyProvider({hub: window.hub, peerId: 'rival', displayName: 'Rival'})
			, game: window.host.game
			, session: {}
		});

		rival.attachGame({start: () => {}, action: () => {}, confirmed: () => {}});
		await rival.connect();
		const roots = [await window.host.create(), await rival.create()];
		await window.tabs.first.protocol.join(roots[0]);
		await window.tabs.second.protocol.join(roots[1]);
	});

	await expect(tab('first')).toHaveClass(/has-game/);
	await expect(tab('second')).toHaveClass(/has-game/);
	await expect(tab('first').locator('.phonics-room-seat')).toBeHidden();
	await expect(tab('second').locator('.phonics-room-seat')).toBeHidden();
});
