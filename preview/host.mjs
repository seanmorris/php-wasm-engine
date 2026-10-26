import {Phonics} from '../engine/browser/Phonics.mjs';
import {PreviewPreloader} from './Preloader.mjs';
const input = document.querySelector('#world-url');
const status = document.querySelector('#status');
const canvas = document.querySelector('canvas');
const frame = canvas.closest('#game-frame') ?? canvas.parentElement;
const preloader = new PreviewPreloader(frame, document.querySelector('h1')?.textContent ?? 'Phonics');
let session, pending, generation = 0;
window.Phonics = Phonics;
window.previewSession = null;
window.previewLoadProgress = null;

const policyList = name => JSON.parse(document.documentElement.dataset[name] ?? '[]');

const dataPolicy = document.documentElement.dataset.dataOrigins === undefined
	? {}
	: {dataOrigins: policyList('dataOrigins')};

async function launch(url)
{
	const current = ++generation;
	pending?.abort();
	pending = new AbortController();
	preloader.begin();
	status.textContent = 'Loading world…';

	try
	{
		await session?.dispose();

		if(current !== generation) return;

		session = null;
		window.previewSession = null;

		session = await Phonics.loadWorld(url, {
			canvas
			, signal: pending.signal
			, runtime: {url: new URL('../runtime/php8.4-sdl.mjs', import.meta.url).href}
			, onProgress: progress => {
				if(current !== generation) return;

				window.previewLoadProgress = progress;
				preloader.update(progress);
				status.textContent = progress.label;
			}
			, policy: {
				codeOrigins: [location.origin
					, ...policyList('codeOrigins')
				]
				, ...dataPolicy
			}
		});

		if(current !== generation)
		{
			await session.dispose();

			return;
		}

		window.previewSession = session;
		preloader.complete();
		status.textContent = 'Ready';
		canvas.focus();
	}
	catch(error)
	{
		if(current !== generation) return;

		preloader.fail(error);
		status.textContent = error.message;
		console.error(error);
	}
}

document.querySelector('#world-form').addEventListener(
	'submit'
	, event => { event.preventDefault(); launch(input.value); }
);

document.querySelector('#stop').addEventListener('click', async () => {
	generation++;
	pending?.abort();
	const active = session;
	session = null;
	await active?.dispose();
	window.previewSession = null;
	window.previewLoadProgress = null;
	preloader.stop();
	status.textContent = 'Stopped';
});

const world = new URLSearchParams(location.search).get('world') ?? document.documentElement.dataset.world;

if(world)
{
	input.value = new URL(world, location.href).href;
	launch(input.value);
}
