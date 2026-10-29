/* eslint-disable sm-no-saccade-style/max-line-length, sm-no-saccade-style/max-unseparated-lines,
	sm-no-saccade-style/statement-spacing, sm-no-saccade-style/object-layout,
	sm-no-saccade-style/parameter-layout */
import {spawn} from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const site = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const root = path.resolve(site, '..');
const pages = path.resolve(site, process.env.PAGES_DIR ?? '.cache/pages');
const drafts = path.join(site, '.cache/api-drafts');
const generic = ['Completes the operation', 'value used by this operation', 'Result of this operation'];
const phpTypes = new Map();

const gamePackages = [
	{
		directory: 'packages/sane-phonics'
		, slug: 'sane-phonics'
		, title: 'Sane-Phonics'
		, rootNamespace: 'SanePhonics'
		, description: 'Platformer gameplay, physics, rendering, and effects used by Sane-Phonics.'
	}
	, {
		directory: 'packages/php-noid', slug: 'php-noid', title: 'PHP-Noid'
		, rootNamespace: 'PhpNoid'
		, description: 'Brick-breaker components, systems, rendering, and campaign state.'
	}
	, {
		directory: 'packages/php-tris', slug: 'php-tris', title: 'PHPTris'
		, rootNamespace: 'PhpTris'
		, description: 'Falling-block game state, challenges, systems, and rendering.'
	}
	, {
		directory: 'packages/php-slalom', slug: 'php-slalom', title: 'PHP Slalom'
		, rootNamespace: 'PhpSlalom'
		, description: 'Deterministic slalom simulation, replay codec, input settings, and rendering.'
	}
	, {
		directory: 'packages/php-buster', slug: 'php-buster', title: 'PHP Buster'
		, rootNamespace: 'PhpBuster'
		, description: 'Blockade waves, actors, scoring, input settings, and interpolated rendering.'
	}
	, {
		directory: 'packages/doom-phonics'
		, slug: 'doom-phonics'
		, title: 'Doom-Phonics'
		, rootNamespace: 'DoomPhonics'
		, description: 'WAD adapters, Doom gameplay, input, networking, and direct GLES rendering.'
	}
];

const namespaceInfo = {
	Phonics: ['Core runtime', 'Frame scheduling, fixed-step simulation, lifecycle, and browser restoration.']
	, 'Phonics\\Asset': ['Assets', 'Synchronous PHP asset reads and canonical source-to-mount identity.']
	, 'Phonics\\Ecs': ['ECS', 'Entity storage, boolean queries, ordered systems, and Tiled object factories.']
	, 'Phonics\\Effects': ['Effects', 'Reusable particles, camera shake, and generic rumble scheduling.']
	, 'Phonics\\Input': ['Input', 'Logical controls, SDL events, gamepads, button names, and input sequences.']
	, 'Phonics\\Network': ['Networking', 'Typed match identities, binary packets, command validation, snapshots, and host-authoritative replication.']
	, 'Phonics\\Network\\Component': ['Network components', 'Explicit ECS identity, authority, scope, and prefab metadata used by replication profiles.']
	, 'Phonics\\Package': ['Packages', 'Session-scoped registration and cleanup for independently published modules.']
	, 'Phonics\\Physics': ['Physics', 'Generic poses, terrain construction, spatial collision queries, and continuous casts.']
	, 'Phonics\\Render': ['Rendering', 'WebGL devices, shaders, atlases, maps, sprites, images, cameras, and post-processing.']
	, 'Phonics\\Spatial': ['Spatial indexing', 'Immutable bounds indexing for broad-phase world queries.']
	, 'Phonics\\Tiled': ['Tiled data', 'Maps, worlds, layers, projections, GIDs, and streamed dependency loading.']
	, 'Phonics\\Ui': ['UI', 'Bitmap text, menus, cards, messages, editing, and DOM-texture modals.']
	, 'Phonics\\World': ['World runtime', 'Format-neutral content sources, scope lifecycles, declarative system bindings, and render passes.']
};

const namespaceExamples = {
	Phonics: `use Phonics\\Engine;\nuse Phonics\\Render\\Device;\n\n$engine = new Engine(new Device);\n$engine->start(static function(Engine $engine): void {\n    // Draw the current frame.\n});`
	, 'Phonics\\Asset': `use Phonics\\Asset\\Assets;\nuse Phonics\\Asset\\Resource;\n\n$url = Resource::resolve($mapUrl, '../images/tiles.png');\n$bytes = (new Assets)->read(Resource::local($url));`
	, 'Phonics\\Ecs': `use Phonics\\Ecs\\Query;\nuse Phonics\\Ecs\\Registry;\n\n$entities = new Registry;\n$id = $entities->create(new Position(10, 20), new Velocity(2, 0));\n$moving = $entities->query(Query::all(Position::class, Velocity::class));`
	, 'Phonics\\Effects': `use Phonics\\Effects\\CameraShake;\n\n$shake = new CameraShake;\n$shake->impulse(8, 3);\n$shake->update($dt);\n$view = $shake->view($camera);`
	, 'Phonics\\Input': `if($controls->pressed(0)) {\n    launch();\n}\n$horizontal = $controls->axis('left', 'right');`
	, 'Phonics\\Network': `use Phonics\\Network\\CallbackComponentCodec;\nuse Phonics\\Network\\ReplicationRegistry;\n\n$replication = new ReplicationRegistry;\n$replication->register(new CallbackComponentCodec(\n    'game.position',\n    1,\n    Position::class,\n    static fn(Position $position): array => ['x' => $position->x, 'y' => $position->y],\n    static fn(array $value): Position => new Position($value['x'], $value['y'])\n));`
	, 'Phonics\\Network\\Component': `use Phonics\\Network\\Component\\NetworkIdentity;\nuse Phonics\\Network\\NetworkEntityId;\nuse Phonics\\Network\\ReplicationScope;\n\n$entities->add($entity, new NetworkIdentity(\n    new NetworkEntityId(42),\n    new ReplicationScope('episode:E1M1')\n));`
	, 'Phonics\\Package': `return static function(Phonics\\Package\\Context $context): void {\n    $context->system('example.motion', update(...));\n    $context->factory('Example.Player', spawn(...));\n    $context->onClose(release(...));\n};`
	, 'Phonics\\Physics': `use Phonics\\Physics\\Pose;\n\n$body = new Pose(x: 20, y: 40, width: 12, height: 16);\n$hit = $terrain->castBody($body, $dx, $dy);`
	, 'Phonics\\Render': `$camera->viewport($width, $height);\n$mapRenderer->render($camera);\n$spriteRenderer->render($sprites, $camera);`
	, 'Phonics\\Spatial': `$index = new Phonics\\Spatial\\BoundsIndex($entries);\n$visible = $index->query($left, $top, $right, $bottom);`
	, 'Phonics\\Tiled': `$loader = new Phonics\\Tiled\\Loader;\n$map = $loader->map('/worlds/start.tmj');\n$spawn = $map->layer('Entities');`
	, 'Phonics\\Ui': `$messages->showCenter('READY', duration: 1.5);\n$renderer->renderMessages($messages, $width, $height);`
	, 'Phonics\\World': `$lease = $context->runtime->activate('episode', 'E1M1');\n// Use the active source scope.\n$lease->release();`
};

function phpApi(directory = '.')
{
	return new Promise((resolve, reject) => {
		const args = [path.join(site, 'tools/php-api.php')];
		if(directory !== '.') args.push(directory);
		const child = spawn('php', args, {cwd: root});
		const output = [], errors = [];
		child.stdout.on('data', chunk => output.push(chunk));
		child.stderr.on('data', chunk => errors.push(chunk));
		child.on('error', reject);
		child.on('close', code => {
			if(code) reject(new Error(Buffer.concat(errors).toString() || `PHP API reader exited ${code}`));
			else resolve(JSON.parse(Buffer.concat(output).toString()));
		});
	});
}

function slugClass(name)
{
	return 'api-' + name.replaceAll('\\', '-').replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
}

function kebab(value)
{
	return value.replace(/([a-z0-9])([A-Z])/g, '$1-$2').replaceAll('\\', '-').toLowerCase();
}

function gameNamespaceRelative(namespace, config)
{
	return namespace === config.rootNamespace ? 'root'
		: namespace.slice(config.rootNamespace.length + 1).replaceAll('\\', '/').toLowerCase();
}

function gameNamespaceId(namespace, config)
{
	return `game-${config.slug}-api-${gameNamespaceRelative(namespace, config).replaceAll('/', '-')}`;
}

function gameClassSlug(item, config)
{
	const relative = item.name.slice(config.rootNamespace.length).replace(/^\\/, '');
	const parts = relative.split('\\').map(kebab);

	return `games/${config.slug}/api/${parts.join('/')}`;
}

function gameNamespaceSlug(namespace, config)
{
	const relative = gameNamespaceRelative(namespace, config);

	return `games/${config.slug}/api/${relative === 'root' ? 'root' : relative + '/index'}`;
}

function relativePage(from, to)
{
	const result = path.posix.relative(path.posix.dirname(from), to + '.html');

	return result || path.posix.basename(to) + '.html';
}

function namespaceId(namespace)
{
	return namespace === 'Phonics' ? 'api-core' : 'api-' + namespace.slice(8).replaceAll('\\', '-').toLowerCase();
}

function anchor(kind, name)
{
	const member = name.replace(/([a-z0-9])([A-Z])/g, '$1-$2')
		.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toLowerCase();

	return `${kind}-${member}`;
}

function frontmatter(title, description, parent, weight = 0, fields = {})
{

	const metadata = value => JSON.stringify(String(value));

	const markdownMetadata = value => JSON.stringify(String(value).replaceAll('\\', '\\\\'));
	const displayTitle = fields['display-title'] ?? title;
	const extra = Object.entries(fields).filter(([name]) => name !== 'display-title')
		.map(([name, value]) => `${name}: ${typeof value === 'boolean' ? value : metadata(value)}`)
		.join('\n');

	return `---\ntitle: ${metadata(title)}\ndisplay-title: ${markdownMetadata(displayTitle)}\ndescription-meta: ${metadata(description)}\n${extra ? extra + '\n' : ''}menus:\n  documentation:\n    parent: ${parent}\n    weight: ${weight}\n---\n`;
}

function cell(value)
{
	return String(value ?? '').replaceAll('|', '\\|').replace(/\s+/g, ' ').trim();
}

function html(value)
{
	return String(value).replaceAll('&', '&amp;').replaceAll('\\', '&#92;')
		.replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
}

function code(value)
{
	const ticks = String(value).includes('`') ? '``' : '`';

	return ticks + value + ticks;
}

function typeMarkup(value, namespace = '', currentSlug = 'api')
{
	const text = String(value ?? 'mixed');
	const parts = text.split('|');
	let linked = false;
	const rendered = parts.map(part => {
		const nullable = part.startsWith('?'), name = nullable ? part.slice(1) : part;
		const target = phpTypes.get(namespace + '\\' + name) ?? phpTypes.get(name);

		if(!target) return code(part);

		linked = true;

		return `[${code(part)}](${relativePage(currentSlug, target.slug)})`;
	});

	return linked ? rendered.join(' | ') : code(text);
}

function tableTypeMarkup(value, namespace = '', currentSlug = 'api')
{
	return typeMarkup(value, namespace, currentSlug).replaceAll(' | ', ' \\| ');
}

function validateGamePhp(data)
{
	const failures = data.classes.filter(item => !item.doc.summary)
		.map(item => `${item.file}:${item.line} ${item.name}: missing class PHPDoc`);

	if(failures.length) throw new Error(`Incomplete game type documentation:\n${failures.join('\n')}`);
}

function validatePhp(data)
{
	const failures = [];

	for(const item of data.classes)
	{
		if(!item.doc.summary) failures.push(`${item.file}:${item.line} ${item.name}: missing class PHPDoc`);

		for(const value of [...item.constants, ...item.properties])
			if(!value.doc.summary) failures.push(`${item.file}:${value.line} ${item.name}::$${value.name}: missing public-member PHPDoc`);

		for(const method of item.methods)
		{
			if(!method.doc.summary) failures.push(`${item.file}:${method.line} ${item.name}::${method.name}(): missing PHPDoc`);
			const prose = [
				method.doc.summary
				, method.doc.description
				, method.doc.return?.description
				, ...Object.values(method.doc.params).map(param => param.description)
			].join(' ');

			if(generic.some(phrase => prose.includes(phrase)))
				failures.push(`${item.file}:${method.line} ${item.name}::${method.name}(): placeholder PHPDoc`);

			for(const param of method.params)
				if(!param.doc?.description) failures.push(`${item.file}:${method.line} ${item.name}::${method.name}(): missing $${param.name} description`);

			if(method.name !== '__construct' && !method.doc.return?.description)
				failures.push(`${item.file}:${method.line} ${item.name}::${method.name}(): missing return description`);
		}
	}

	if(failures.length) throw new Error(`Incomplete public PHP API documentation:\n${failures.join('\n')}`);
}

/** List the documented top-level parameter names, ignoring nested `options.name` properties. */
function documentedParams(doc)
{
	return doc.params.map(param => param.name.replace(/^\[|\]$/g, '').split('=')[0]).filter(name => !name.includes('.'));
}

/** Report AST parameters without a matching @param; destructured parameters match by position. */
function missingParams(params = [], doc)
{
	const documented = documentedParams(doc), missing = [];

	for(const [index, param] of params.entries())
	{
		const target = param.type === 'AssignmentPattern' ? param.left : param.type === 'RestElement' ? param.argument : param;

		if(target.type === 'Identifier' ? !documented.includes(target.name) : index >= documented.length)
			missing.push(target.type === 'Identifier' ? target.name : `parameter ${index + 1}`);
	}

	return missing;
}

function phpSignature(method)
{
	const params = method.params.map(param => {
		let result = `${param.type ? param.type + ' ' : ''}${param.byReference ? '&' : ''}${param.variadic ? '...' : ''}$${param.name}`;

		if(param.default !== null) result += ` = ${param.default}`;

		return result;
	}).join(', ');

	return `public ${method.static ? 'static ' : ''}function ${method.returnsReference ? '&' : ''}${method.name}(${params})${method.returnType ? ': ' + method.returnType : ''}`;
}

function memberDoc(doc)
{
	let result = doc.summary ? `${doc.summary}\n` : '';

	if(doc.description) result += `\n${doc.description}\n`;

	return result;
}

function declaration(item)
{
	const modifiers = [item.final ? 'final' : '', item.abstract ? 'abstract' : '', item.readonly ? 'readonly' : '', item.kind]
		.filter(Boolean).join(' ');
	let result = `${modifiers} ${item.shortName}`;

	if(item.enumBackingType) result += `: ${item.enumBackingType}`;
	if(item.parent) result += ` extends ${item.parent}`;
	if(item.interfaces.length) result += ` implements ${item.interfaces.join(', ')}`;

	return result;
}

function phpClassPage(item, aliases, config = null)
{
	const slug = config ? gameClassSlug(item, config) : slugClass(item.name);
	const source = `https://github.com/seanmorris/php-wasm-engine/blob/HEAD/${item.file}#L${item.line}`;
	const namespaceSlug = config ? gameNamespaceSlug(item.namespace, config)
		: `api-${item.namespace === 'Phonics' ? 'core' : item.namespace.slice(8).replaceAll('\\', '-').toLowerCase()}`;
	const namespaceTitle = config ? (item.namespace === config.rootNamespace ? config.title : item.namespace) : namespaceInfo[item.namespace][0];
	const parent = config ? gameNamespaceId(item.namespace, config) : namespaceId(item.namespace);
	const apiRoot = config ? `games/${config.slug}/api/index` : 'api';
	let body = frontmatter(config ? item.shortName : item.name, item.doc.summary, parent, 0, {
		'display-title': item.name
		, 'api-page': true
		, 'api-root-title': config ? `${config.title} PHP API` : 'PHP API'
		, 'api-root-url': relativePage(slug, apiRoot)
		, 'api-parent-title': namespaceTitle
		, 'api-parent-url': relativePage(slug, namespaceSlug)
		, 'source-url': source
		, ...(config ? {llms: 'games'} : {})
	});
	if(item.doc.description) body += `${item.doc.description}\n\n`;
	body += `## Declaration\n\n\`\`\`php\n${declaration(item)}\n\`\`\`\n`;
	const oldNames = Object.entries(aliases).filter(([, target]) => target === item.name).map(([old]) => old);

	if(oldNames.length) body += `\n**Deprecated compatibility names:** ${oldNames.map(code).join(', ')}. Use the canonical Phonics name above.\n`;

	if(item.traits.length) body += `\n**Uses:** ${item.traits.map(value => typeMarkup(value, item.namespace, slug)).join(', ')}\n`;

	if(item.cases.length)
	{
		body += '\n## Cases\n';
		for(const value of item.cases)
			body += `\n### ${value.name} {#${anchor('case', value.name)}}\n\n${memberDoc(value.doc)}\n\`\`\`php\ncase ${value.name}${value.value !== null ? ' = ' + value.value : ''};\n\`\`\`\n`;
	}

	if(item.constants.length)
	{
		body += '\n## Constants\n';

		for(const constant of item.constants)
			body += `\n### ${constant.name} {#${anchor('constant', constant.name)}}\n\n${memberDoc(constant.doc)}\n\`\`\`php\npublic const ${constant.name} = ${constant.value};\n\`\`\`\n`;
	}

	if(item.properties.length)
	{
		body += '\n## Properties\n';

		for(const property of item.properties)
		{
			const signature = `public ${property.static ? 'static ' : ''}${property.readonly ? 'readonly ' : ''}${property.type ? property.type + ' ' : ''}$${property.name}${property.default !== null ? ' = ' + property.default : ''};`;
			body += `\n### $${property.name} {#${anchor('property', property.name)}}\n\n${memberDoc(property.doc)}\n\`\`\`php\n${signature}\n\`\`\`\n`;

			if(property.type && typeMarkup(property.type, item.namespace, slug) !== code(property.type))
				body += `\n**Type:** ${typeMarkup(property.type, item.namespace, slug)}\n`;
		}
	}

	if(item.methods.length)
	{
		body += '\n## Methods\n';

		for(const method of item.methods)
		{
			body += `\n### ${code(method.name + '()')} {#${anchor('method', method.name)}}\n\n${memberDoc(method.doc)}\n`;
			body += `\`\`\`php\n${phpSignature(method)}\n\`\`\`\n`;

			if(method.params.length)
			{
				body += '\n| Parameter | Type | Description |\n|---|---|---|\n';

				for(const param of method.params)
					body += `| ${code('$' + param.name)} | ${tableTypeMarkup(param.doc?.type || param.type || 'mixed', item.namespace, slug)} | ${cell(param.doc?.description)} |\n`;
			}

			if(method.name !== '__construct' && method.doc.return)
				body += `\n**Returns:** ${typeMarkup(method.doc.return.type, item.namespace, slug)} — ${method.doc.return.description}\n`;

			if(method.doc.throws.length)
				body += `\n**Throws:** ${method.doc.throws.map(value => `${typeMarkup(value.type, item.namespace, slug)} — ${value.description}`).join('; ')}\n`;
		}
	}

	return {slug, body, outline: classOutline(item)};
}

function classOutline(item)
{
	const groups = [
		['Constants', item.constants.map(value => value.name)]
		, ['Public state', item.properties.map(value => '$' + value.name)]
		, ['Operations', item.methods.map(value => value.name + '()')]
	].filter(([, values]) => values.length);
	let result = `# ${item.name} — drafting outline\n\nI. Purpose and ownership\n    A. ${item.doc.summary}\n`;
	let numeral = 2;

	for(const [name, values] of groups)
	{
		result += `${['','I','II','III','IV'][numeral++]}. ${name}\n`;
		values.forEach((value, index) => { result += `    ${String.fromCharCode(65 + index)}. ${value}\n`; });
	}

	return result;
}

function namespacePage(namespace, classes)
{
	const [title, description] = namespaceInfo[namespace];
	let body = frontmatter(`${title} API`, description, namespaceId(namespace), -100);
	body += `[PHP API](api.html)\n\n${description}\n\n## Example\n\n\`\`\`php\n${namespaceExamples[namespace]}\n\`\`\`\n\n## Classes\n\n`;

	for(const item of classes)
		body += `- [${code(item.name)}](${slugClass(item.name)}.html) — ${item.doc.summary}\n`;

	return body;
}

function gameNamespacePage(namespace, classes, config)
{
	const relative = gameNamespaceRelative(namespace, config);
	const title = relative === 'root' ? `${config.title} root namespace` : namespace;
	const slug = gameNamespaceSlug(namespace, config);
	let body = frontmatter(title, `Public types in ${namespace}.`, gameNamespaceId(namespace, config), -100, {llms: 'games'});
	body += `[${config.title} PHP API](${relativePage(slug, `games/${config.slug}/api/index`)})\n\n`;
	body += `These types are declared in ${code(namespace)}.\n\n## Types\n\n`;

	for(const item of classes)
		body += `- [${code(item.shortName)}](${relativePage(slug, gameClassSlug(item, config))}) — ${item.doc.summary}\n`;

	return body;
}

function gameApiIndex(data, config)
{
	const slug = `games/${config.slug}/api/index`;
	const rows = data.classes.map(item => {
		const names = [...item.constants, ...item.properties, ...item.methods].map(value => value.name);
		return `<li data-api-entry data-api-search="${html([item.name, item.doc.summary, ...names].join(' ').toLowerCase())}"><a href="${relativePage(slug, gameClassSlug(item, config))}"><code>${html(item.name)}</code></a><span>${html(item.doc.summary)}</span><small>${html(names.join(' · '))}</small></li>`;
	});
	let body = frontmatter('PHP API', `Every public PHP type in ${config.title}.`, `game-${config.slug}-api`, -100, {
		'display-title': `${config.title} PHP API`, llms: 'games'
	});
	body += `${config.description}\n\nThe reference includes all public types and signatures from the package's declared ${code('src/')} files. Curated game chapters explain the executable modules and browser integration around them.\n\n`;
	body += `<div class="api-filter"><label for="api-filter">Filter classes and members</label><input id="api-filter" type="search" autocomplete="off" data-api-filter><p role="status" aria-live="polite" data-api-status></p></div>\n\n`;
	body += `<ul class="api-index" data-api-index>\n${rows.join('\n')}\n</ul>\n\n## Namespaces\n\n`;

	for(const namespace of [...new Set(data.classes.map(item => item.namespace))])
		body += `- [${code(namespace)}](${relativePage(slug, gameNamespaceSlug(namespace, config))})\n`;

	return body;
}

function apiIndex(data)
{
	const rows = [];

	for(const item of data.classes)
	{
		const names = [...item.constants, ...item.properties, ...item.methods].map(value => value.name);
		rows.push(`<li data-api-entry data-api-search="${html([item.name, item.doc.summary, ...names].join(' ').toLowerCase())}"><a href="${slugClass(item.name)}.html"><code>${html(item.name)}</code></a><span>${html(item.doc.summary)}</span><small>${html(names.join(' · '))}</small></li>`);
	}

	let body = frontmatter('PHP API', 'Every supported Phonics PHP class and member.', 'api', -100);
	body += `Phonics ${data.version} exposes the complete framework through Composer's ${code('Phonics\\')} namespace. Game packages have separate gameplay references.\n\n`;
	body += '<div class="api-filter"><label for="api-filter">Filter classes, methods, and properties</label><input id="api-filter" type="search" autocomplete="off" data-api-filter><p role="status" aria-live="polite" data-api-status></p></div>\n\n';
	body += `<ul class="api-index" data-api-index>\n${rows.join('\n')}\n</ul>\n\n`;
	body += '## Namespaces\n\n';

	for(const [namespace, [title, description]] of Object.entries(namespaceInfo))
		body += `- [${title}](api-${namespace === 'Phonics' ? 'core' : namespace.slice(8).replaceAll('\\', '-').toLowerCase()}.html) — ${description}\n`;

	body += '## Compatibility aliases\n\nThe pre-1.0 `PhpWasmEngine\\` names remain aliases. New code should use the canonical class shown here.\n\n| Deprecated name | Canonical class |\n|---|---|\n';

	for(const [old, target] of Object.entries(data.aliases))
		body += `| ${code(old)} | [${code(target)}](${slugClass(target)}.html) |\n`;

	return body;
}

async function write(page)
{
	await fs.mkdir(path.dirname(path.join(pages, page.slug + '.md')), {recursive: true});
	await fs.mkdir(path.dirname(path.join(drafts, page.slug + '.md')), {recursive: true});
	await fs.writeFile(path.join(pages, page.slug + '.md'), page.body);
	await fs.writeFile(path.join(drafts, page.slug + '.md'), page.outline);
}

const data = await phpApi();
const gameData = await Promise.all(gamePackages.map(async config => ({config, data: await phpApi(config.directory)})));
validatePhp(data);
for(const game of gameData) validateGamePhp(game.data);

for(const item of data.classes) phpTypes.set(item.name, {...item, slug: slugClass(item.name)});

for(const {config, data: packageData} of gameData)
	for(const item of packageData.classes)
		phpTypes.set(item.name, {...item, slug: gameClassSlug(item, config)});

for(const item of data.classes)
{
	const existing = phpTypes.get(item.shortName);
	phpTypes.set(item.shortName, existing && existing.name !== item.name ? null : phpTypes.get(item.name));
}

await fs.rm(drafts, {recursive: true, force: true});
await fs.mkdir(drafts, {recursive: true});

await write({
	slug: 'api'
	, body: apiIndex(data)
	, outline: '# PHP API — drafting outline\n\nI. Define the supported PHP framework surface\n    A. Namespaces and classes\nII. Find an API by symbol\n    A. Filter classes and members\n    B. Browse subsystem indexes\nIII. Preserve compatibility\n    A. Link deprecated aliases to canonical classes\n'
});

for(const [namespace] of Object.entries(namespaceInfo))
{
	const classes = data.classes.filter(item => item.namespace === namespace);
	const slug = 'api-' + (namespace === 'Phonics' ? 'core' : namespace.slice(8).replaceAll('\\', '-').toLowerCase());
	await write({
		slug
		, body: namespacePage(namespace, classes)
		, outline: `# ${namespaceInfo[namespace][0]} API — drafting outline\n\nI. Explain the subsystem\n    A. ${namespaceInfo[namespace][1]}\nII. Demonstrate ordinary use\n    A. Runnable example\nIII. Enumerate public classes\n${classes.map((value, index) => `    ${String.fromCharCode(65 + index)}. ${value.name}`).join('\n')}\n`
	});
}

for(const item of data.classes) await write(phpClassPage(item, data.aliases));

for(const {config, data: packageData} of gameData)
{
	await write({
		slug: `games/${config.slug}/api/index`
		, body: gameApiIndex(packageData, config)
		, outline: `# ${config.title} PHP API — drafting outline\n\nI. Package scope\n    A. ${config.description}\nII. Find a public type\nIII. Browse namespaces\n`
	});

	for(const namespace of [...new Set(packageData.classes.map(item => item.namespace))])
	{
		const classes = packageData.classes.filter(item => item.namespace === namespace);
		await write({
			slug: gameNamespaceSlug(namespace, config)
			, body: gameNamespacePage(namespace, classes, config)
			, outline: `# ${namespace} — drafting outline\n\nI. Namespace purpose\nII. Public types\n${classes.map((value, index) => `    ${String.fromCharCode(65 + index)}. ${value.name}`).join('\n')}\n`
		});
	}

	for(const item of packageData.classes) await write(phpClassPage(item, packageData.aliases, config));
}

console.log(`Generated ${data.classes.length} core PHP class pages and ${gameData.reduce((count, value) => count + value.data.classes.length, 0)} game PHP class pages.`);
