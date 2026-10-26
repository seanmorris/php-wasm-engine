import {Phonics} from './core/browser/Phonics.mjs';
const status = document.querySelector('#status');
const controller = new AbortController();
let session;

try
{
	const query = new URLSearchParams(location.search);

	session = await Phonics.loadWorld(new URL(query.get('world') ?? '/worlds/game.world', location.href), {
		canvas: document.querySelector('canvas')
		, signal: controller.signal
		, runtime: {url: new URL('./runtime/php8.4-sdl.mjs', import.meta.url).href}
		, policy: {codeOrigins: [location.origin, ...query.getAll('origin')]}
	});

	window.previewSession = session;
	status.textContent = 'Ready — move with arrows or WASD';
	document.querySelector('canvas').focus();
}
catch(error)
{
	status.textContent = error.message;
	console.error(error);
}

document.querySelector('#stop').addEventListener('click', async() => {
	controller.abort();
	await session?.dispose();
	window.previewSession = null;
	status.textContent = 'Stopped';
});
