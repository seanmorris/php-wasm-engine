/** Read capability metadata without registering a guest or contacting a server. */
export function readInviteLink(url = globalThis.location?.href)
{
	const fragment = new URL(url).hash;

	if(!fragment.startsWith('#phonics-invite=')) return null;

	if(fragment.length > 4096) throw new Error('Invitation link is too large');

	let value;

	try
	{
		value = JSON.parse(
			new TextDecoder().decode(
				Uint8Array.from(
					atob(fragment.slice(16).replaceAll('-', '+').replaceAll('_', '/'))
					, c => c.charCodeAt(0)
				)
			)
		);
	}
	catch
	{
		throw new Error('Invalid invitation link');
	}

	if(value?.v !== 1 || value.provider !== 'matrix' || typeof value.server !== 'string'
		|| !/^[\w-]{43}$/.test(value.secret) || !/^[\w-]{36}$/.test(value.id)
		|| typeof value.host !== 'string' || typeof value.device !== 'string'
		|| !Number.isSafeInteger(value.expiresAt)
		|| Object.keys(value).sort().join(',') !== 'device,expiresAt,host,id,provider,secret,server,v'
	)
	throw new Error('Invalid invitation link');

	const server = new URL(value.server);

	if(!['https:', 'http:'].includes(server.protocol) || server.username || server.password
		|| server.origin !== value.server
	)
	throw new Error('Invalid invitation homeserver');

	return Object.freeze(value);
}
