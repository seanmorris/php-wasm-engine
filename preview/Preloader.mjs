const resourceName = value => {
	if(!value) return '';

	try
	{
		const url = new URL(value, document.baseURI);
		const name = decodeURIComponent(url.pathname.split('/').filter(Boolean).at(-1) ?? url.hostname);

		return name + url.search + url.hash;
	}
	catch
	{
		return String(value);
	}
};

/** Loading overlay driven by Phonics LoadProgress snapshots. */
export class PreviewPreloader
{

	constructor(frame, title = 'Phonics')
	{
		this.frame = frame;
		this.root = document.createElement('section');
		this.root.className = 'phonics-preloader';
		this.root.setAttribute('role', 'status');
		this.root.setAttribute('aria-live', 'polite');
		this.root.setAttribute('aria-atomic', 'true');
		this.root.setAttribute('aria-label', `Loading ${title}`);
		this.root.hidden = true;
		this.meter = document.createElement('progress');
		this.meter.max = 1;
		this.meter.setAttribute('aria-label', 'World loading progress');
		this.status = document.createElement('output');
		this.status.className = 'phonics-preloader-status';
		this.root.append(this.meter, this.status);
		frame.append(this.root);
	}

	begin()
	{
		delete this.root.dataset.failed;
		delete this.root.dataset.phase;
		this.root.hidden = false;
		this.frame.setAttribute('aria-busy', 'true');
		this.meter.removeAttribute('value');
		this.status.textContent = 'Reading world definition';
		this.root.setAttribute('aria-label', 'Reading world definition');
	}

	update(progress)
	{
		this.root.hidden = false;
		this.root.dataset.phase = progress.phase;
		this.meter.value = progress.fraction;
		const detail = progress.total > 1
			? `${progress.label} · ${progress.completed} / ${progress.total}`
			: progress.label;

		const resource = resourceName(progress.resource);
		this.status.textContent = detail;
		this.root.setAttribute('aria-label', resource ? `${detail}: ${resource}` : detail);
	}

	complete()
	{
		this.meter.value = 1;
		this.root.hidden = true;
		this.frame.removeAttribute('aria-busy');
	}

	fail(error)
	{
		this.root.hidden = false;
		this.root.dataset.failed = '';
		this.root.dataset.phase = 'failed';
		this.frame.removeAttribute('aria-busy');
		this.meter.removeAttribute('value');
		const detail = `World load failed: ${error?.message ?? String(error)}`;

		this.status.textContent = detail;
		this.root.setAttribute('aria-label', detail);
	}

	stop()
	{
		this.root.hidden = true;
		this.frame.removeAttribute('aria-busy');
	}
}
