import {Phonics} from './core/browser/Phonics.mjs';
const status = document.querySelector('#status');
const controller = new AbortController();
let session;

document.querySelector('#stop').addEventListener('click', async() => {
	controller.abort();
	await session?.dispose();
	window.previewSession = null;
	status.textContent = 'Stopped';
});

try
{
	const query = new URLSearchParams(location.search);

	session = await Phonics.run(new URL(query.get('application') ?? '/dist/game.phar', location.href), {
		init: query.has('world') ? {world: new URL(query.get('world'), location.href).href} : {}
		, canvas: document.querySelector('canvas')
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
	status.textContent = controller.signal.aborted ? 'Stopped' : error.message;

	if(!controller.signal.aborted) console.error(error);
}
