const embeddedGame = new URLSearchParams(location.search).get('embed') === '1';

if(embeddedGame) document.documentElement.dataset.gameEmbed = 'true';

const narrow = window.matchMedia('(max-width: 700px)');
const menus = document.querySelectorAll('.page-navigation');
const systemDark = window.matchMedia('(prefers-color-scheme: dark)');
const themeToggle = document.querySelector('[data-theme-toggle]');

const activeTheme = () => document.documentElement.dataset.theme
	?? (systemDark.matches ? 'dark' : 'light');

const applyThemeClass = theme => {
	document.documentElement.classList.toggle('dark', theme === 'dark');
	document.documentElement.classList.toggle('light', theme === 'light');
};

const updateThemeToggle = () => {
	if(!themeToggle) return;

	const theme = activeTheme();
	const target = theme === 'dark' ? 'light' : 'dark';

	applyThemeClass(theme);

	themeToggle.hidden = false;
	themeToggle.dataset.targetTheme = target;
	themeToggle.setAttribute('aria-label', `Use ${target} theme`);
	themeToggle.setAttribute('title', `Use ${target} theme`);

	for(const icon of themeToggle.querySelectorAll('[data-theme-icon]'))
		icon.toggleAttribute('hidden', icon.dataset.themeIcon !== target);
};

if(themeToggle)
{
	themeToggle.addEventListener('click', () => {
		const theme = activeTheme() === 'dark' ? 'light' : 'dark';

		document.documentElement.dataset.theme = theme;

		try
		{
			localStorage.setItem('phonics-doc-theme', theme);
		}
		catch
		{
			// The selected theme still works for this page when storage is unavailable.
		}

		updateThemeToggle();
	});

	systemDark.addEventListener('change', updateThemeToggle);
	updateThemeToggle();
}

const siteSearch = document.querySelector('[data-site-search]');

if(siteSearch)
{
	const toggle = siteSearch.querySelector('[data-search-toggle]');
	const panel = siteSearch.querySelector('[data-search-panel]');
	const input = siteSearch.querySelector('#search-query');
	const resultsElement = siteSearch.querySelector(input?.dataset.searchResults ?? '#search-results');
	const baseUrl = document.querySelector('meta[name="smgen-base-url"]')?.content.replace(/\/$/, '') ?? '';
	let readerPromise;
	let searchRequest = 0;

	const setOpen = open => {
		siteSearch.toggleAttribute('data-open', open);
		toggle?.setAttribute('aria-expanded', String(open));
		panel?.setAttribute('aria-hidden', String(!open));

		if(open) requestAnimationFrame(() => input?.focus());
	};

	const showMessage = message => {
		if(!resultsElement) return;

		const item = document.createElement('li');
		const label = document.createElement('span');
		label.textContent = message;
		item.append(label);
		resultsElement.replaceChildren(item);
	};

	const resultHref = path => {
		const cleanPath = String(path).replace(/^\/+|\/+$/g, '');

		if(cleanPath === 'index') return `${baseUrl}/`;

		if(cleanPath.endsWith('/index')) return `${baseUrl}/${cleanPath.slice(0, -5)}`;

		return `${baseUrl}/${cleanPath}.html`;
	};

	const loadReader = () => {
		if(readerPromise) return readerPromise;

		const indexUrl = input?.dataset.searchIndex;
		const readerUrl = input?.dataset.searchReader;

		if(!indexUrl || !readerUrl) return Promise.reject(new Error('Search assets are not configured.'));

		readerPromise = Promise.all([
			import(readerUrl)
			, fetch(indexUrl).then(response => {
				if(!response.ok) throw new Error(`Search index returned ${response.status}.`);

				return response.arrayBuffer();
			})
		]).then(([module, buffer]) => new module.SearchReader(buffer));

		return readerPromise;
	};

	const runSearch = async() => {
		const request = ++searchRequest;
		const query = input?.value.trim() ?? '';

		if(query.length < 3)
		{
			resultsElement?.replaceChildren();

			return;
		}

		try
		{
			const reader = await loadReader();

			if(request !== searchRequest) return;

			const matches = reader.search(query, .5).slice(0, 10);

			if(!matches.length)
			{
				showMessage('No results.');

				return;
			}

			const items = matches.map(([result]) => {
				const item = document.createElement('li');
				const link = document.createElement('a');
				link.textContent = result.title;
				link.href = resultHref(result.path);
				item.append(link);

				return item;
			});

			resultsElement?.replaceChildren(...items);
		}
		catch(error)
		{
			if(request !== searchRequest) return;

			console.error('Unable to load search.', error);
			showMessage('Search unavailable.');
		}
	};

	toggle?.addEventListener('click', () => setOpen(!siteSearch.hasAttribute('data-open')));
	input?.addEventListener('focus', () => loadReader().catch(() => {}), {once: true});
	input?.addEventListener('input', runSearch);

	input?.addEventListener('keydown', event => {
		if(event.key !== 'ArrowDown') return;

		const firstResult = resultsElement?.querySelector('a');

		if(firstResult)
		{
			event.preventDefault();
			firstResult.focus();
		}
	});

	document.addEventListener('pointerdown', event => {
		if(siteSearch.hasAttribute('data-open') && !siteSearch.contains(event.target)) setOpen(false);
	});

	document.addEventListener('keydown', event => {
		if(event.key !== 'Escape' || !siteSearch.hasAttribute('data-open')) return;

		setOpen(false);
		toggle?.focus();
	});
}

const fitNavigation = () => {
	for(const menu of menus) menu.open = !narrow.matches;
};

fitNavigation();
narrow.addEventListener('change', fitNavigation);

const menuStatePrefix = 'openMenu-';

for(const menu of document.querySelectorAll('details[data-id-path]'))
{
	const key = menuStatePrefix + menu.dataset.idPath;

	try
	{
		const saved = localStorage.getItem(key);

		if(saved !== null) menu.open = JSON.parse(saved) === true;
	}
	catch
	{
		// Storage can be unavailable or contain stale data. Keep the authored state.
	}

	menu.addEventListener('toggle', () => {
		try
		{
			localStorage.setItem(key, JSON.stringify(menu.open));
		}
		catch
		{
			// Navigation still works when storage is unavailable.
		}
	});
}

let activeMenu = document.querySelector('.site-navigation .active-link')?.closest('details');

while(activeMenu)
{
	activeMenu.open = true;
	activeMenu = activeMenu.parentElement?.closest('details');
}

let mousedownNavigationAnchor;
let dispatchingMousedownNavigation = false;

document.addEventListener('click', event => {
	const anchor = event.target.closest?.('a');

	if(dispatchingMousedownNavigation || !anchor || anchor !== mousedownNavigationAnchor) return;

	mousedownNavigationAnchor = null;
	event.preventDefault();
	event.stopImmediatePropagation();
}, true
);

document.addEventListener('mousedown', event => {
	if(event.defaultPrevented || event.button !== 0
		|| event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
	){
		return;
	}

	const anchor = event.target.closest?.('a[href]');

	if(!anchor) return;

	event.preventDefault();
	mousedownNavigationAnchor = anchor;
	dispatchingMousedownNavigation = true;

	try
	{
		anchor.click();
	}
	finally
	{
		dispatchingMousedownNavigation = false;
	}
});

document.addEventListener('mouseup', () => {
	if(!mousedownNavigationAnchor) return;

	const anchor = mousedownNavigationAnchor;

	setTimeout(() => {
		if(mousedownNavigationAnchor === anchor) mousedownNavigationAnchor = null;
	}, 0
	);
}, true
);

window.addEventListener('blur', () => {
	mousedownNavigationAnchor = null;
});

const prefetchedUrls = new Set();
let prefetchTimer;

document.addEventListener('mouseover', event => {
	const anchor = event.target.closest?.('a[href]');

	if(!anchor || anchor.contains(event.relatedTarget)) return;

	clearTimeout(prefetchTimer);

	prefetchTimer = setTimeout(
		() => {
			const connection = navigator.connection;

			if(connection?.saveData || /(^|-)2g$/.test(connection?.effectiveType ?? '')) return;

			if(anchor.target || anchor.hasAttribute('download')) return;

			const url = new URL(anchor.href, location.href);

			if(!/^https?:$/.test(url.protocol) || url.origin !== location.origin) return;

			if(url.pathname === location.pathname && url.search === location.search) return;

			url.hash = '';

			if(prefetchedUrls.has(url.href)) return;

			prefetchedUrls.add(url.href);

			const link = document.createElement('link');
			link.rel = 'prefetch';
			link.href = url.href;
			link.as = 'document';
			document.head.append(link);
		}
		, 75
	);
});

document.addEventListener('mouseout', event => {
	const anchor = event.target.closest?.('a[href]');

	if(anchor && !anchor.contains(event.relatedTarget)) clearTimeout(prefetchTimer);
});

const apiFilter = document.querySelector('[data-api-filter]');

if(apiFilter)
{
	const entries = [...document.querySelectorAll('[data-api-entry]')];
	const status = document.querySelector('[data-api-status]');

	const update = () => {
		const terms = apiFilter.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
		let visible = 0;

		for(const entry of entries)
		{
			const matches = terms.every(term => entry.dataset.apiSearch.includes(term));
			entry.hidden = !matches;

			if(matches) visible++;
		}

		status.textContent = `${visible} of ${entries.length} API entries`;
	};

	apiFilter.addEventListener('input', update);
	update();
}

for(const embed of document.querySelectorAll('.game-embed[data-title]'))
{
	const trigger = embed.querySelector('.game-embed-poster');
	const runtime = embed.querySelector('[data-game-runtime]');

	trigger?.addEventListener('click', async() => {
		if(!runtime || embed.dataset.loading !== undefined) return;

		embed.dataset.loading = '';
		trigger.disabled = true;

		try
		{
			const host = new URL(embed.dataset.host, location.href).href;
			const world = new URL(embed.dataset.world, location.href).href;
			const dataOrigins = embed.dataset.dataOrigins?.split(',').filter(Boolean);
			const {mountPreview} = await import(host);
			runtime.hidden = false;
			trigger.remove();
			mountPreview(runtime, {world, title: embed.dataset.title, dataOrigins});
		}
		catch(error)
		{
			delete embed.dataset.loading;
			trigger.disabled = false;
			const status = runtime?.querySelector('#status');

			if(runtime) runtime.hidden = false;

			if(status) status.textContent = `Could not start ${embed.dataset.title}: ${error.message}`;

			console.error(error);
		}
	});
}
