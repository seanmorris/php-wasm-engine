import {test, expect} from '@playwright/test';

// Drive the embedded room lobby over two in-memory Matrix-shaped accounts, with a game that publishes house rules.

const openRoom = async page => {
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

		const game = {id: 'org.example.cards', protocol: '1.0.0', rules: 'cards-v1', title: 'Cards'};
		const hub = new InMemoryLobbyHub;
		const hostProvider = new InMemoryLobbyProvider({hub, peerId: 'host', displayName: 'Host'});
		const guestProvider = new InMemoryLobbyProvider({hub, peerId: 'guest', displayName: 'Guest'});
		const host = new RoomGameProtocol({provider: hostProvider, game, session: {}, storage: storage()});
		const guest = new RoomGameProtocol({provider: guestProvider, game, session: {}, storage: storage()});
		const chosen = [], forms = [];

		const defaults = {ante: 1, wild: [], custom: []};
		const adapter = {
			start: () => {}
			, action: () => {}
			, confirmed: () => {}
			, validSettings: settings => {
				if(!Number.isSafeInteger(settings?.ante) || settings.ante < 1 || settings.ante > 5)
				return 'Ante must be 1 to 5';

				if(settings.custom.length && !settings.wild.includes('custom')) return 'Custom cards need Custom wild';

				return true;
			}
			, describeSettings: settings => `Ante ${settings.ante}`
				+ (settings.wild.length ? ` · ${settings.wild.join(', ')} wild` : '')
				+ (settings.custom.length ? ` (${settings.custom.join(' ')})` : '')
			, settingsForm: ({target}) => {
				forms.push(target);

				return {
					title: 'House rules'
					, values: chosen.at(-1) ?? defaults
					, defaults
					, groups: [
						{legend: 'Players', fields: [], summary: () => 'Two, always'}
						, {
							legend: 'Opening'
							, fields: [
								{
									name: 'variant'
									, label: 'Game'
									, type: 'select'
									, default: 'anything'
									, options: [
										{value: 'anything', label: 'Anything Opens'}
										, {value: 'jackpots', label: 'Jackpots (offline only)', disabled: true}
									]
								}
								, {name: 'ante', label: 'Ante', type: 'number', min: 1, max: 5, default: 1}
							]
						}
						, {
							legend: 'Wild cards'
							, fields: [
								{
									name: 'wild'
									, label: 'Wild'
									, type: 'checkboxes'
									, options: [{value: 'deuces', label: 'Deuces'}, {value: 'custom', label: 'Custom'}]
								}
								, {
									name: 'custom'
									, label: 'Custom wild cards'
									, type: 'checkboxes'
									, visibleWhen: {field: 'wild', includes: 'custom'}
									, options: [{value: 'AS', label: 'A♠'}, {value: 'KH', label: 'K♥'}]
								}
							]
						}
					]
				};
			}
			, settingsChosen: settings => chosen.push(settings)
		};

		host.attachGame(adapter);
		guest.attachGame({...adapter, settingsForm: undefined, settingsChosen: undefined});

		const frame = document.createElement('div');
		frame.style.cssText = 'position:relative;width:960px;height:640px';
		const canvas = document.createElement('canvas');
		frame.append(canvas);
		document.body.append(frame);
		const overlay = new EmbeddedRoomLobby({protocol: host, canvas, title: 'Cards Online'});
		await guest.connect();
		await overlay.open();
		Object.assign(window, {host, guest, hostProvider, overlay, chosen, forms});
	});

	return page.locator('.phonics-room-game');
};

test('opening a table asks for house rules in a launcher-style dialog and labels the table', async({page}) => {
	const room = await openRoom(page);
	await room.getByRole('button', {name: 'Open a table'}).click();

	const dialog = page.locator('dialog.phonics-match-settings');
	await expect(dialog.getByRole('heading', {name: 'House rules'})).toBeVisible();
	await expect(dialog.getByRole('button', {name: 'Open table'})).toBeEnabled();
	await expect(dialog.locator('.phonics-match-settings-summary')).toContainText('Ante 1');

	// Every section starts folded, and its header shows the values inside.

	const section = name => dialog.locator('details', {has: page.locator('summary', {hasText: name})});
	await expect(dialog.locator('details[open]')).toHaveCount(0);
	await expect(dialog.locator('details')).toHaveCount(2);
	await expect(dialog.locator('.is-fixed')).toHaveText('PlayersTwo, always');
	await expect(dialog.getByRole('spinbutton', {name: 'Ante'})).toBeHidden();
	await expect(section('Opening').locator('summary')).toHaveText('OpeningAnything Opens · Ante 1');
	await expect(section('Wild cards').locator('summary')).toHaveText('Wild cardsNone');
	await section('Opening').locator('summary').click();
	await section('Wild cards').locator('summary').click();
	await expect(dialog.locator('details[open]')).toHaveCount(2);

	expect(
		await dialog.getByRole('option', {name: 'Jackpots (offline only)'}).evaluate(option => option.disabled)
	).toBe(true);

	await expect(dialog.getByRole('checkbox', {name: 'A♠'})).toBeHidden();

	// An out-of-range value explains itself and blocks the table.
	await dialog.getByRole('spinbutton', {name: 'Ante'}).fill('9');
	await expect(dialog.locator('output')).toHaveText('Ante must be 1 to 5');
	await expect(dialog.getByRole('button', {name: 'Open table'})).toBeDisabled();
	await expect(dialog.locator('.phonics-match-settings-summary')).toBeHidden();

	// Escape cancels without posting anything, and does not also close the lobby behind the dialog.
	await page.keyboard.press('Escape');
	await expect(dialog).toHaveCount(0);
	await expect(room).toHaveClass(/is-open/);
	expect(await page.evaluate(() => host.snapshot().tables.length)).toBe(0);

	await room.getByRole('button', {name: 'Open a table'}).click();
	await section('Opening').locator('summary').click();
	await section('Wild cards').locator('summary').click();
	await dialog.getByRole('spinbutton', {name: 'Ante'}).fill('3');
	await dialog.getByRole('checkbox', {name: 'Custom'}).check();
	await dialog.getByRole('checkbox', {name: 'K♥'}).check();
	await expect(dialog.locator('.phonics-match-settings-summary')).toContainText('Ante 3 · custom wild (KH)');

	const value = name => section(name).locator('.phonics-match-settings-section-value');
	await expect(value('Opening')).toHaveText('Anything Opens · Ante 3');
	await expect(value('Wild cards')).toHaveText('Custom · K♥');

	// Hiding the custom list clears it from the settings, and the choice comes back when Custom is checked again.
	await dialog.getByRole('checkbox', {name: 'Custom'}).uncheck();
	await expect(dialog.getByRole('checkbox', {name: 'K♥'})).toBeHidden();
	await expect(dialog.locator('.phonics-match-settings-summary')).toHaveText(/Ante 3$/);
	await dialog.getByRole('checkbox', {name: 'Custom'}).check();
	await expect(dialog.getByRole('checkbox', {name: 'K♥'})).toBeChecked();
	await dialog.getByRole('button', {name: 'Open table'}).click();
	await expect(dialog).toHaveCount(0);

	await expect(room.locator('.is-waiting')).toContainText('Waiting for an opponent');
	await expect(room.locator('.is-waiting')).toContainText('House rules: Ante 3 · custom wild (KH)');

	const offered = await page.evaluate(() => ({
		chosen: chosen.at(-1)
		, guest: guest.snapshot().tables[0]
		, message: hostProvider.hub.history(hostProvider.room)
			.find(item => item.type === 'm.room.message')?.content.body
	}));

	expect(offered.chosen).toEqual({variant: 'anything', ante: 3, wild: ['custom'], custom: ['KH']});
	expect(offered.guest.settings).toEqual(offered.chosen);
	expect(offered.guest.joinable).toBe(true);
	expect(offered.message).toContain('opened a Cards table (Ante 3 · custom wild (KH)).');
});

test('a challenge uses the same dialog, starts from the last rules, and resets to defaults', async({page}) => {
	const room = await openRoom(page);
	await page.evaluate(() => chosen.push({variant: 'anything', ante: 4, wild: ['deuces'], custom: []}));

	await room.getByRole('button', {name: 'Challenge'}).click();
	const dialog = page.locator('dialog.phonics-match-settings');
	await expect(dialog.locator('summary', {hasText: 'Opening'})).toHaveText('OpeningAnything Opens · Ante 4');

	for(const header of await dialog.locator('summary').all()) await header.click();
	await expect(dialog.getByRole('spinbutton', {name: 'Ante'})).toHaveValue('4');
	await expect(dialog.getByRole('checkbox', {name: 'Deuces'})).toBeChecked();
	await dialog.getByRole('button', {name: 'Defaults'}).click();
	await expect(dialog.getByRole('spinbutton', {name: 'Ante'})).toHaveValue('1');
	await expect(dialog.getByRole('checkbox', {name: 'Deuces'})).not.toBeChecked();
	await dialog.getByRole('button', {name: 'Send challenge'}).click();
	await expect(dialog).toHaveCount(0);

	await expect(room.locator('.is-waiting')).toContainText('Waiting for Guest to accept');
	await expect(room.locator('.is-waiting')).toContainText('House rules: Ante 1');
	expect(await page.evaluate(() => forms)).toEqual(['guest']);
	expect(await page.evaluate(() => guest.snapshot().tables[0].forMe)).toBe(true);
});
