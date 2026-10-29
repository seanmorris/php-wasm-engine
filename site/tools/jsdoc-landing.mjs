/**
 * Render the JavaScript API landing page with the same searchable card index
 * used by the PHP reference.
 *
 * @param {{graph: object, config: object}} context JSDoc render context.
 * @returns {string} Markdown and trusted site HTML.
 */
export default function renderJavascriptLanding({graph, config})
{
	const modules = new Map();

	for(const symbol of graph.symbols)
	{
		const exports = modules.get(symbol.module) ?? [];
		exports.push(symbol);
		modules.set(symbol.module, exports);
	}

	const version = graph.package.version === '' ? '' : ` ${graph.package.version}`;
	let body = `Phonics${version} exposes ${graph.symbols.length} public JavaScript exports from ${modules.size} browser modules. These APIs load worlds, manage package resources, provide browser services, and connect multiplayer sessions.\n\n`;
	body += '## Modules\n\n';
	body += '<ul class="api-index">\n';

	for(const [module, symbols] of modules)
	{
		const counts = kindCounts(symbols);

		body += apiCard({
			link: moduleRoute(module)
			, title: module
			, summary: `${symbols.length} public export${symbols.length === 1 ? '' : 's'}`
			, details: counts
		});
	}

	body += '</ul>\n\n## Exports\n\n';
	body += '<div class="api-filter"><label for="api-filter">Filter modules, exports, and class members</label><input id="api-filter" type="search" autocomplete="off" data-api-filter><p role="status" aria-live="polite" data-api-status></p></div>\n\n';
	body += '<ul class="api-index" data-api-index>\n';

	for(const symbol of graph.symbols)
	{
		const summary = symbol.docs.summary || `${kindName(symbol.kind)} ${symbol.name} from ${symbol.module}.`;
		const members = symbol.members.map(member => member.name);
		const search = [symbol.name, symbol.module, summary, ...members].join(' ').toLowerCase();
		const details = [symbol.module, ...members].join(' · ');

		body += apiCard({
			link: symbolRoute(symbol, config)
			, title: symbol.name
			, summary
			, details
			, search
		});
	}

	body += '</ul>\n';

	return body;
}

function apiCard({link, title, summary, details, search = ''})
{
	const attributes = search === '' ? '' : ` data-api-entry data-api-search="${html(search)}"`;

	return [
		`<li${attributes}>`
		, `<a href="${link}"><code>${html(title)}</code></a>`
		, `<span>${html(summary)}</span>`
		, `<small>${html(details)}</small>`
		, '</li>\n'
	].join('');
}

function moduleRoute(module)
{
	return `modules/${module.split('/').map(slug).join('/')}/`;
}

function symbolRoute(symbol, config)
{
	const override = config.routes.overrides[symbol.id];

	if(override) return `${override}.html`;

	const groups = {
		class: 'classes'
		, function: 'functions'
		, constant: 'values'
		, variable: 'values'
		, typedef: 'types'
		, callback: 'types'
	};

	const segments = symbol.module.split('/').map(slug);

	return [groups[symbol.kind] ?? 'symbols', ...segments, `${slug(symbol.name)}.html`].join('/');
}

function kindCounts(symbols)
{
	const counts = new Map();

	for(const symbol of symbols)
	{
		const label = kindName(symbol.kind);
		counts.set(label, (counts.get(label) ?? 0) + 1);
	}

	return [...counts].map(([label, count]) => `${count} ${label.toLowerCase()}${count === 1 ? '' : 's'}`).join(' · ');
}

function kindName(kind)
{
	return {
		class: 'Class'
		, function: 'Function'
		, constant: 'Constant'
		, variable: 'Variable'
		, typedef: 'Type'
		, callback: 'Callback'
	}[kind] ?? 'Export';
}

function slug(value)
{
	const separated = value.replace(/([a-z0-9])([A-Z])/g, '$1-$2');

	return separated.replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase() || 'symbol';
}

function html(value)
{
	return String(value)
		.replaceAll('&', '&amp;')
		.replaceAll('<', '&lt;')
		.replaceAll('>', '&gt;')
		.replaceAll('"', '&quot;');
}
