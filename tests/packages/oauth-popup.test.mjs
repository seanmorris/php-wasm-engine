import test from 'node:test';
import assert from 'node:assert/strict';
import {
	authorizeWithPopup
	, oauthPopupReturnUrl
	, OAUTH_POPUP_MESSAGE
	, OAUTH_POPUP_PARAMETER
	, relayOAuthPopup
} from '../../browser/network/OAuthPopup.mjs';

class FakeChannel
{

	static channels = new Map;

	constructor(name)
	{
		this.name = name;
		const channels = FakeChannel.channels.get(name) ?? new Set;
		channels.add(this);
		FakeChannel.channels.set(name, channels);
	}

	postMessage(data)
	{
		for(const channel of FakeChannel.channels.get(this.name) ?? [])
		if(channel !== this) queueMicrotask(() => channel.onmessage?.({data}));
	}

	close()
	{
		FakeChannel.channels.get(this.name)?.delete(this);
	}
}

test('OAuth popup return URLs retain the host route without stale authorization values', () => {
	const id = '12345678-1234-1234-1234-123456789abc';

	const value = new URL(oauthPopupReturnUrl('https://game.example/play?world=episode&code=stale#state=stale', id));

	assert.equal(value.origin + value.pathname, 'https://game.example/play');
	assert.equal(value.searchParams.get('world'), 'episode');
	assert.equal(value.searchParams.get(OAUTH_POPUP_PARAMETER), id);
	assert.equal(value.searchParams.has('code'), false);
	assert.equal(value.hash, '');
});

test('OAuth callback relay sends only the response URL and cleans the popup history', () => {
	FakeChannel.channels.clear();
	const id = '12345678-1234-1234-1234-123456789abc';
	const href = `https://game.example/play?world=episode&${OAUTH_POPUP_PARAMETER}=${id}`
		+ '#state=oauth-state&code=authorization-code';

	const received = [];
	const listener = new FakeChannel(`phonics.oauth.${id}`);

	listener.onmessage = ({data}) => received.push(data);
	let cleaned = null, closed = false;

	assert.equal(relayOAuthPopup({
		location: {href}
		, history: {state: null, replaceState: (_state, _title, url) => { cleaned = url; }}
		, document: {title: '', body: {textContent: ''}}
		, BroadcastChannel: FakeChannel
		, close: () => { closed = true; }
		, schedule: callback => callback()
	}), true
	);

	assert.equal(received.length, 0);

	return new Promise(resolve => queueMicrotask(() => {
		assert.deepEqual(received, [{type: OAUTH_POPUP_MESSAGE, id, href}]);
		assert.equal(cleaned, 'https://game.example/play?world=episode');
		assert.equal(closed, true);
		listener.close();
		resolve();
	}));
});

test('OAuth authorization completes through a popup while the opener stays loaded', async () => {
	FakeChannel.channels.clear();
	const id = '12345678-1234-1234-1234-123456789abc';
	const browser = new EventTarget;
	let callbackUrl, authorizationUrl, completedUrl;
	const popup = {
		closed: false

		, close() { this.closed = true; }
		, document: {title: '', body: {textContent: ''}}
		, location: {

			replace(value) {
				authorizationUrl = value;
				const callback = new URL(callbackUrl);
				callback.hash = '#state=oauth-state&code=authorization-code';
				const channel = new FakeChannel(`phonics.oauth.${id}`);
				channel.postMessage({type: OAUTH_POPUP_MESSAGE, id, href: callback.href});
				queueMicrotask(() => channel.close());
			}
		}
	};

	Object.assign(browser, {
		location: {href: 'https://game.example/play?world=episode', origin: 'https://game.example'}
		, crypto: {randomUUID: () => id}
		, BroadcastChannel: FakeChannel
		, screen: {availWidth: 1280, availHeight: 800}
		, screenX: 20
		, screenY: 30
		, outerWidth: 1000
		, outerHeight: 700
		, open: () => popup
		, setInterval
		, clearInterval
		, setTimeout
		, clearTimeout
	});

	const result = await authorizeWithPopup({
		window: browser
		, id
		, begin: value => {
			callbackUrl = value;

			return 'https://auth.example/authorize';
		}
		, complete: value => {
			completedUrl = value;

			return 'connected';
		}
	});

	assert.equal(result, 'connected');
	assert.equal(authorizationUrl, 'https://auth.example/authorize');
	assert.equal(new URL(callbackUrl).searchParams.get(OAUTH_POPUP_PARAMETER), id);
	assert.equal(completedUrl.endsWith('#state=oauth-state&code=authorization-code'), true);
	assert.equal(browser.location.href, 'https://game.example/play?world=episode');
	assert.equal(popup.closed, true);
});
