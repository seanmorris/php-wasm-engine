export const OAUTH_POPUP_PARAMETER = 'phonics.oauth.popup';
export const OAUTH_POPUP_MESSAGE = 'phonics.oauth.callback';

const callbackValues = value => {
	const url = new URL(value);
	const fragment = new URLSearchParams(url.hash.startsWith('#') ? url.hash.slice(1) : '');
	const source = fragment.has('code') || fragment.has('error') || fragment.has('state')
		? fragment
		: url.searchParams;

	return {url, source};
};

const failure = (code, message) => Object.assign(new Error(message), {code});

const channelName = id => `phonics.oauth.${id}`;

/** Build the exact same-origin redirect URI used to relay an OAuth response to its opener. */
export const oauthPopupReturnUrl = (value, id) => {
	const url = new URL(value);

	url.hash = '';

	for(const name of ['code','state','error','error_description','error_uri'])
	url.searchParams.delete(name);

	url.searchParams.set(OAUTH_POPUP_PARAMETER, id);

	return url.href;
};

/**
 * Relay one popup callback before the ordinary host starts loading its world.
 * The authorization response stays same-origin and contains no access token.
 */
export const relayOAuthPopup = (
	{
		location = globalThis.location
		, opener = globalThis.opener
		, history = globalThis.history
		, document = globalThis.document
		, BroadcastChannel = globalThis.BroadcastChannel
		, close = globalThis.close?.bind(globalThis)
		, schedule = globalThis.setTimeout?.bind(globalThis)
	} = {}
) => {
	if(!location?.href) return false;

	let callback;

	try { callback = callbackValues(location.href); } catch { return false; }

	const id = callback.url.searchParams.get(OAUTH_POPUP_PARAMETER);

	if(!id || !/^[A-Za-z0-9_-]{16,128}$/.test(id) || !callback.source.get('state')
		|| (!callback.source.has('code') && !callback.source.has('error'))
	) return false;

	const href = callback.url.href;
	const message = Object.freeze({type: OAUTH_POPUP_MESSAGE, id, href});
	let channel = null;

	try
	{
		channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel(channelName(id)) : null;
		channel?.postMessage(message);
	}
	catch
	{
		channel = null;
	}

	try { opener?.postMessage?.(message, callback.url.origin); } catch {}

	callback.url.hash = '';
	callback.url.searchParams.delete(OAUTH_POPUP_PARAMETER);

	for(const name of ['code','state','error','error_description','error_uri'])
	callback.url.searchParams.delete(name);

	try { history?.replaceState?.(history.state ?? null, '', callback.url.href); } catch {}

	if(document)
	{
		document.title = 'Matrix sign-in complete';

		if(document.body)
		{
			if(typeof document.createElement === 'function')
			{
				const notice = document.createElement('p');
				notice.textContent = 'Matrix sign-in complete. This window can now close.';
				notice.setAttribute('role', 'status');
				notice.style.cssText = 'position:fixed;inset:0;z-index:2147483647;margin:0;padding:2rem;'
					+ 'color:#f7f0d0;background:#080a14;font:18px/1.5 system-ui,sans-serif';

				document.body.append(notice);
			}
			else document.body.textContent = 'Matrix sign-in complete. This window can now close.';
		}
	}

	const finish = () => {
		try { channel?.close(); } catch {}

		try { close?.(); } catch {}
	};

	if(schedule) schedule(finish, 100);
	else finish();

	return true;
};

const popupFeatures = windowRef => {
	const width = Math.min(520, Math.max(360, windowRef.screen?.availWidth ?? 520));
	const height = Math.min(720, Math.max(520, windowRef.screen?.availHeight ?? 720));
	const left = Math.max(0, (windowRef.screenX ?? 0) + ((windowRef.outerWidth ?? width) - width) / 2);
	const top = Math.max(0, (windowRef.screenY ?? 0) + ((windowRef.outerHeight ?? height) - height) / 2);

	return `popup=yes,width=${width},height=${height},left=${Math.round(left)},top=${Math.round(top)}`;
};

const waitForCallback = ({windowRef, popup, id, channel, signal, timeout}) => new Promise((resolve, reject) => {
	let closedChecks = 0;
	let settled = false;

	const cleanup = () => {
		windowRef.removeEventListener('message', onMessage);

		if(channel) channel.onmessage = null;

		windowRef.clearInterval(closedTimer);
		windowRef.clearTimeout(timeoutTimer);
		signal?.removeEventListener('abort', onAbort);
	};

	const finish = (method, value) => {
		if(settled) return;

		settled = true;
		cleanup();
		method(value);
	};

	const accept = data => {
		if(data?.type !== OAUTH_POPUP_MESSAGE || data.id !== id || typeof data.href !== 'string') return;

		let callback;

		try { callback = callbackValues(data.href); } catch { return; }

		if(callback.url.origin !== windowRef.location.origin) return;

		finish(resolve, data.href);
	};

	const onMessage = event => {
		if(event.origin !== windowRef.location.origin || event.source !== popup) return;

		accept(event.data);
	};

	const onAbort = () => finish(
		reject
		, signal.reason ?? failure('oauth_popup_cancelled', 'Matrix sign-in was cancelled')
	);

	windowRef.addEventListener('message', onMessage);

	if(channel)

	channel.onmessage = event => accept(event.data);

	if(signal) signal.addEventListener('abort', onAbort, {once: true});

	const closedTimer = channel
		? null
		: windowRef.setInterval(() => {
			closedChecks = popup.closed ? closedChecks + 1 : 0;

			if(closedChecks >= 2)
		finish(reject, failure('oauth_popup_closed', 'Matrix sign-in was cancelled'));
		}, 250
		);

	const timeoutTimer = windowRef.setTimeout(
		() => finish(reject, failure('oauth_popup_timeout', 'Matrix sign-in timed out'))
		, timeout
	);
});

/** Open Matrix authorization without navigating the running game away from its active session. */
export async function authorizeWithPopup(
	{
		begin
		, complete
		, window: windowRef = globalThis
		, signal
		, timeout = 10 * 60_000
		, id = windowRef.crypto.randomUUID()
	} = {}
) {
	if(typeof begin !== 'function' || typeof complete !== 'function')
	throw new TypeError('OAuth popup requires begin and complete callbacks');

	const popup = windowRef.open('', `phonics-matrix-${id}`, popupFeatures(windowRef));

	if(!popup)
	throw failure('oauth_popup_blocked', 'Allow popups to continue with Matrix');

	let channel = null;

	try
	{
		popup.document.title = 'Opening Matrix sign-in';
		popup.document.body.textContent = 'Opening Matrix sign-in…';
		const returnUrl = oauthPopupReturnUrl(windowRef.location.href, id);
		const authorizationUrl = await begin(returnUrl);

		signal?.throwIfAborted();

		if(popup.closed) throw failure('oauth_popup_closed', 'Matrix sign-in was cancelled');

		try
		{
			channel = typeof windowRef.BroadcastChannel === 'function'
				? new windowRef.BroadcastChannel(channelName(id))
				: null;

			if(channel) popup.opener = null;
		}
		catch
		{
			channel = null;
		}

		const callback = waitForCallback({windowRef, popup, id, channel, signal, timeout});
		popup.location.replace(authorizationUrl);
		const href = await callback;

		return await complete(href);
	}
	finally
	{
		try { channel?.close(); } catch {}

		try { popup.close(); } catch {}
	}
}
