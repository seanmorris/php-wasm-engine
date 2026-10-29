import {Phonics} from '../engine/browser/Phonics.mjs';
import {PreviewPreloader} from './Preloader.mjs';

window.Phonics = Phonics;
window.previewSession = null;
window.previewLoadProgress = null;

const policyList = (element, name) => JSON.parse(element.dataset[name] ?? '[]');

/**
 * Mount the reusable application preview in either the generic loader or a game documentation page.
 *
 * @param {ParentNode} root Element containing the canvas and preview controls.
 * @param {object} options Preview configuration.
 * @returns {{launch: Function, stop: Function, session: Function}} Preview controller.
 */
export function mountPreview(root = document, options = {})
{
	const input = root.querySelector('#world-url');
	const form = root.querySelector('#world-form');
	const status = root.querySelector('#status');
	const canvas = root.querySelector('canvas');
	const stopButton = root.querySelector('#stop');

	if(!status || !canvas || !stopButton) throw Error('Preview host markup is incomplete');

	const frame = canvas.closest('#game-frame') ?? canvas.parentElement;
	const title = options.title ?? root.querySelector('h1')?.textContent
		?? document.querySelector('h1')?.textContent ?? 'Phonics';

	const preloader = new PreviewPreloader(frame, title);
	let session, pending, generation = 0;

	const codeOrigins = options.codeOrigins ?? policyList(document.documentElement, 'codeOrigins');
	const configuredDataOrigins = options.dataOrigins
		?? (document.documentElement.dataset.dataOrigins === undefined
			? undefined
			: policyList(document.documentElement, 'dataOrigins')
		);

	async function launch(url)
	{
		const current = ++generation;
		pending?.abort();
		pending = new AbortController();
		preloader.begin();
		status.textContent = 'Loading application…';

		try
		{
			await session?.dispose();

			if(current !== generation) return;

			session = null;
			window.previewSession = null;

			session = await Phonics.run(url, {
				canvas
				, init: {
					...options.init
					, ...(new URLSearchParams(location.search).has('world')
						? {world: new URL(new URLSearchParams(location.search).get('world'), location.href).href}
						: {}
					)
				}
				, signal: pending.signal
				, runtime: {url: new URL('../runtime/php8.4-sdl.mjs', import.meta.url).href}
				, onProgress: progress => {
					if(current !== generation) return;

					window.previewLoadProgress = progress;
					preloader.update(progress);
					status.textContent = progress.label;
				}
				, policy: {
					codeOrigins: [location.origin, ...codeOrigins]
					, ...(configuredDataOrigins === undefined
						? {}
						: {dataOrigins: configuredDataOrigins}
					)
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

	async function stop()
	{
		generation++;
		pending?.abort();
		const active = session;
		session = null;
		await active?.dispose();
		window.previewSession = null;
		window.previewLoadProgress = null;
		preloader.stop();
		status.textContent = 'Stopped';
	}

	form?.addEventListener('submit', event => {
		event.preventDefault();
		void launch(input.value);
	});

	stopButton.addEventListener('click', () => void stop());

	const configuredApplication = options.application
		?? new URLSearchParams(location.search).get('application')
		?? document.documentElement.dataset.application;

	if(configuredApplication)
	{
		const world = new URL(configuredApplication, location.href).href;

		if(input) input.value = world;

		void launch(world);
	}

	return {launch, stop, session: () => session};
}

// Standalone package pages remain development fixtures, while the generic loader is public.
// Documentation embeds have no world form and call mountPreview explicitly after activation.
if(document.querySelector('#world-form')) mountPreview(document);
