const embeddedGame = new URLSearchParams(location.search).get('embed') === '1';

if(embeddedGame) document.documentElement.dataset.gameEmbed = 'true';

const narrow = window.matchMedia('(max-width: 700px)');
const compact = window.matchMedia('(max-width: 1240px)');
const menus = document.querySelectorAll('.page-navigation');
const siteMenu = document.querySelector('.site-menu');
const layout = document.querySelector('.layout');
const main = document.querySelector('main');
const contents = document.querySelector('.contents');
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
	const toggle = document.querySelector('[data-search-toggle]');
	const panel = siteSearch.querySelector('[data-search-panel]');
	const input = siteSearch.querySelector('#search-query');
	const resultsElement = siteSearch.querySelector(input?.dataset.searchResults ?? '#search-results');
	const wideSearch = window.matchMedia('(min-width: 1100px)');
	const baseUrl = document.querySelector('meta[name="smgen-base-url"]')?.content.replace(/\/$/, '') ?? '';
	let readerPromise;
	let searchRequest = 0;

	const setOpen = (open, focus = false) => {
		siteSearch.toggleAttribute('data-open', open);
		toggle?.setAttribute('aria-expanded', String(open));
		panel?.setAttribute('aria-hidden', String(!open && !wideSearch.matches));
		resultsElement?.setAttribute('aria-hidden', String(!open));

		if(open && focus) requestAnimationFrame(() => input?.focus());
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

	const resultKind = path => {
		const cleanPath = String(path).replace(/^\/+/, '');

		if(cleanPath.startsWith('api-js/')) return 'JavaScript API';

		if(/^api[-.]/.test(cleanPath)) return 'PHP API';

		if(/^games\/[^/]+\/api\//.test(cleanPath)) return 'Game API';

		if(cleanPath.startsWith('games/')) return 'Games';

		return 'Guide';
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
				const kind = document.createElement('span');
				link.textContent = result.title;
				link.href = resultHref(result.path);
				kind.className = 'search-kind';
				kind.textContent = resultKind(result.path);
				kind.setAttribute('aria-hidden', 'true');
				item.append(link, kind);

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

	toggle?.addEventListener('click', () => setOpen(!siteSearch.hasAttribute('data-open'), true));
	input?.addEventListener('click', () => setOpen(true));

	input?.addEventListener('focus', () => {
		setOpen(true);
		loadReader().catch(() => {});
	});

	input?.addEventListener('input', () => {
		setOpen(true);
		runSearch();
	});

	input?.addEventListener('keydown', event => {
		if(event.key !== 'ArrowDown') return;

		const firstResult = resultsElement?.querySelector('a');

		if(firstResult)
		{
			event.preventDefault();
			setOpen(true);
			firstResult.focus();
		}
	});

	document.addEventListener('pointerdown', event => {
		if(!siteSearch.hasAttribute('data-open')) return;

		if(!siteSearch.contains(event.target) && !toggle?.contains(event.target)) setOpen(false);
	});

	siteSearch.addEventListener('focusout', event => {
		if(!siteSearch.contains(event.relatedTarget) && event.relatedTarget !== toggle) setOpen(false);
	});

	document.addEventListener('keydown', event => {
		const shortcut = (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey)
			|| (event.key.toLowerCase() === 'k' && (event.ctrlKey || event.metaKey) && !event.altKey);

		if(!shortcut || event.defaultPrevented) return;

		const target = event.target;

		if(event.key === '/' && (target.isContentEditable || target.closest?.('input, textarea, select'))) return;

		if(target.closest?.('canvas, .game-embed-runtime, [data-game-runtime]')) return;

		event.preventDefault();

		if(wideSearch.matches) input?.focus();
		else setOpen(true, true);
	});

	document.addEventListener('keydown', event => {
		if(event.key !== 'Escape' || !siteSearch.hasAttribute('data-open')) return;

		event.preventDefault();

		if(wideSearch.matches) input?.focus();
		else toggle?.focus();

		setOpen(false);
	});

	const fitSearch = () => {
		const open = siteSearch.hasAttribute('data-open');
		setOpen(open);

		if(wideSearch.matches && document.activeElement === toggle) input?.focus();
		else if(!wideSearch.matches && !open && siteSearch.contains(document.activeElement)) toggle?.focus();
	};

	fitSearch();
	wideSearch.addEventListener('change', fitSearch);
}

/**
 * Without a third column, place the page contents between the page header and the article.
 *
 * @returns {void}
 */
const placeContents = () => {
	if(!contents || !layout || !main) return;

	if(compact.matches)
	{
		if(contents.parentElement !== main) main.insertBefore(contents, main.querySelector(':scope > article'));
	}
	else if(contents.parentElement !== layout) layout.append(contents);
};

const fitNavigation = () => {
	placeContents();

	for(const menu of menus) menu.open = !compact.matches;

	if(siteMenu) siteMenu.open = !narrow.matches;
};

fitNavigation();
narrow.addEventListener('change', fitNavigation);
compact.addEventListener('change', fitNavigation);

const sidebar = document.querySelector('.sidebar');
const activeSidebarLink = sidebar?.querySelector('.active-link');

if(sidebar && activeSidebarLink && sidebar.scrollHeight > sidebar.clientHeight)
{
	const linkTop = activeSidebarLink.getBoundingClientRect().top - sidebar.getBoundingClientRect().top;

	if(linkTop > sidebar.clientHeight * .7) sidebar.scrollTop = linkTop - sidebar.clientHeight / 3;
}

for(const heading of document.querySelectorAll('article :is(h2, h3, h4)[id]'))
{
	const anchor = document.createElement('a');
	anchor.className = 'heading-anchor';
	anchor.href = `#${heading.id}`;
	anchor.setAttribute('aria-hidden', 'true');
	anchor.tabIndex = -1;
	heading.prepend(anchor);
}

for(const block of document.querySelectorAll('article div.sourceCode'))
{
	const code = block.querySelector('pre code');

	if(!code || !navigator.clipboard) continue;

	const button = document.createElement('button');
	let reset;

	button.type = 'button';
	button.className = 'copy-code';
	button.textContent = 'Copy';
	button.setAttribute('aria-label', 'Copy code');

	button.addEventListener('click', async() => {
		try
		{
			await navigator.clipboard.writeText(code.innerText.replace(/\n$/, ''));

			button.textContent = 'Copied';
			button.toggleAttribute('data-copied', true);
		}
		catch
		{
			button.textContent = 'Copy failed';
		}

		clearTimeout(reset);

		reset = setTimeout(() => {
			button.textContent = 'Copy';
			button.removeAttribute('data-copied');
		}, 1600
		);
	});

	block.append(button);
}

const contentLinks = new Map();

for(const link of document.querySelectorAll('.contents nav a[href^="#"]'))
{
	const id = decodeURIComponent(link.hash.slice(1));

	if(id && id !== 'content') contentLinks.set(id, link);
}

if(contentLinks.size)
{
	const headings = [...contentLinks.keys()].map(id => document.getElementById(id)).filter(Boolean);
	let frame = 0;

	const markCurrent = () => {
		frame = 0;

		const offset = (document.querySelector('.masthead')?.getBoundingClientRect().bottom ?? 0) + innerHeight / 4;
		const current = headings.findLast(heading => heading.getBoundingClientRect().top <= offset) ?? headings[0];

		for(const [id, link] of contentLinks)
		{
			if(id === current?.id) link.setAttribute('aria-current', 'true');
			else link.removeAttribute('aria-current');
		}
	};

	addEventListener('scroll', () => {
		frame ||= requestAnimationFrame(markCurrent);
	}, {passive: true}
	);

	markCurrent();
}

const primaryLinks = [...document.querySelectorAll('.masthead nav a[href]:not(.masthead-source)')];

if(primaryLinks.length && !primaryLinks.some(link => link.classList.contains('active-link')))
{
	const section = primaryLinks
		.map(link => ({link, path: new URL(link.href, location.href).pathname.replace(/index\.html$/, '')}))
		.filter(({path}) => location.pathname.startsWith(path))
		.sort((left, right) => right.path.length - left.path.length)[0];

	section?.link.classList.add('active-section');
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
