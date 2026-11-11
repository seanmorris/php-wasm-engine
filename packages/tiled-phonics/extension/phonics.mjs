/* Tiled 1.12.2 extension. The helper imports declarative metadata; it never runs game code. */
import {classCatalog} from './catalog.mjs';

function run(args)
{
	const process = new Process();

	try
	{
		const result = process.exec('tiled-phonics', args);
		const output = process.readStdOut();

		if(result !== 0) throw new Error(process.readStdErr() || output);

		return output;
	}
	finally
	{
		process.close();
	}
}

function cacheDirectory()
{
	if(!tiled.projectFilePath) throw new Error('Open or save a Tiled project before adding packages.');

	return FileInfo.joinPaths(FileInfo.path(tiled.projectFilePath), '.phonics-cache');
}

function currentWorld()
{
	const world = tiled.worlds.find(world => tiled.activeAsset && world.containsMap(tiled.activeAsset.fileName))
		|| tiled.worlds[0];

	if(!world) throw new Error('Open a .world file first.');

	return world;
}

function records()
{
	const directory = cacheDirectory();

	return File.directoryEntries(directory).filter(file => file.endsWith('.manifest.json')).map(name => {
		const file = new TextFile(FileInfo.joinPaths(directory, name), TextFile.ReadOnly);

		try { return JSON.parse(file.readAll()); } finally { file.close(); }
	});
}

function action(name, text, callback)
{
	const registered = tiled.registerAction(name, () => {
		try { callback(); } catch(error) { tiled.alert(error.message, 'Phonics'); }
	});

	registered.text = text;
}

export function applyClasses(definitions)
{
	const created = [];

	// Register names first so a preset may refer to a component declared later.
	for(const definition of definitions)
	{
		const existing = tiled.project.findTypeByName(definition.name);
		const source = JSON.stringify(definition);

		if(existing)
		{
			if(tiled.project.property('phonics.class.' + definition.name) === source) continue;

			throw new Error('Conflicting class ' + definition.name);
		}

		const type = tiled.project.addClassType(definition.name);
		tiled.project.setProperty('phonics.class.' + definition.name, source);

		if(definition.color) type.color = tiled.color(definition.color);

		created.push({type, definition});
	}

	for(const {type, definition} of created)
	for(const member of definition.members || [])
	{
		let value = member.value;

		if(member.type === 'class')
		value = tiled.propertyValue(member.propertyType || member.propertytype, value || {});

		if(member.type === 'file') value = tiled.filePath(value);

		if(member.type === 'color') value = tiled.color(value);

		type.setMember(member.name, value);
	}
}

/** Publish project defaults as portable runtime data, next to the project file. */
export function exportClasses(world)
{
	if(!world.property('phonics.classes')) return;

	const project = new TextFile(tiled.projectFilePath, TextFile.ReadOnly);
	let data;

	try { data = JSON.parse(project.readAll()); } finally { project.close(); }

	const catalog = classCatalog(data.propertyTypes || [], records());
	const target = FileInfo.joinPaths(FileInfo.path(tiled.projectFilePath), 'phonics.classes.json');
	const file = new TextFile(target, TextFile.WriteOnly);
	file.write(JSON.stringify(catalog, null, 2) + '\n');
	file.commit();
}

function enableComposition(world)
{
	const path = FileInfo.joinPaths(FileInfo.path(tiled.projectFilePath), 'phonics.classes.json');
	world.setProperty('phonics.classes', tiled.filePath(path));
	exportClasses(world);
}

export function configureWorld(world, imports, start, modules = [])
{
	world.macro('Configure Phonics world', () => {
		for(const alias of Object.keys(imports)) world.setProperty('phonics.import.' + alias, imports[alias]);
		world.setProperty('phonics.start', start);
		world.setProperty('phonics.modules', JSON.stringify(modules));
	});
}

action('PhonicsAddPackage', 'Add Package URL…', () => {
	const world = currentWorld();
	const dialog = new Dialog('Add Phonics package');

	const url = dialog.addTextInput(
		'Manifest URL'
		, 'https://cdn.jsdelivr.net/npm/@seanmorris/php-noid@0.1.0/phonics.json'
	);

	const alias = dialog.addTextInput('Pallet alias', 'php-noid');
	dialog.addButton('Import').clicked.connect(() => dialog.accept());
	dialog.addButton('Cancel').clicked.connect(() => dialog.reject());

	if(dialog.exec() !== Dialog.Accepted) return;

	if(!/^[A-Za-z][\w-]*$/.test(alias.text)) throw new Error('Use a simple, unique pallet alias.');

	if(world.property('phonics.import.' + alias.text)) throw new Error('This pallet alias is already registered.');

	const imported = JSON.parse(run(['import', url.text, cacheDirectory()]));
	applyClasses(imported.classes);
	world.setProperty('phonics.import.' + alias.text, imported.primary);
	world.save();
	tiled.log('Phonics cached ' + imported.packages.length + ' packages in ' + imported.cache);
});

action('PhonicsWorldSettings', 'Phonics World Settings…', () => {
	const world = currentWorld(), cached = records(), pallets = [], modules = [];

	for(const [name, url] of Object.entries(world.properties()))
	{
		if(!name.startsWith('phonics.import.')) continue;

		const alias = name.slice(15), record = cached.find(record => record.source === url);

		if(!record) throw new Error('Import is missing from the cache: ' + url);

		for(const [name, entry] of Object.entries(record.manifest.exports))
		{
			pallets.push('@' + alias + '/' + name + ' (' + entry.type + ')');


			if(entry.type === 'module') modules.push('@' + alias + '/' + name);
		}
	}


	const dialog = new Dialog('Phonics World Settings');
	const maps = world.maps.map(map => FileInfo.relativePath(FileInfo.path(world.fileName), map.fileName));
	const start = dialog.addComboBox('Starting map', maps, Math.max(0, maps.indexOf(world.property('phonics.start'))));
	const selected = JSON.parse(world.property('phonics.modules') || '[]');
	const checks = modules.map(reference => dialog.addCheckBox(reference, selected.includes(reference)));
	dialog.addLabel('Available pallet exports:\n' + pallets.join('\n'));
	dialog.addButton('Save').clicked.connect(() => dialog.accept());
	dialog.addButton('Cancel').clicked.connect(() => dialog.reject());

	if(dialog.exec() !== Dialog.Accepted) return;

	configureWorld(world, {}, maps[start.currentIndex], modules.filter((_, i) => checks[i].checked));

	world.save();
});


action('PhonicsPlaceEntity', 'Place Phonics Entity…', () => {
	const map = tiled.activeAsset;

	if(!map || !map.isTileMap || !map.currentLayer || !map.currentLayer.isObjectLayer)
		throw new Error('Select an object layer in a map first.');

	const world = currentWorld(), exports = [], definitions = [];

	for(const [name, url] of Object.entries(world.properties()))
	{
		if(!name.startsWith('phonics.import.')) continue;

		const record = records().find(record => record.source === url);

		if(!record) throw new Error('Import has not been cached: ' + url);

		for(const [key, entry] of Object.entries(record.manifest.exports))
			if(entry.type === 'factory' || entry.type === 'component')
			{
				exports.push('@' + name.slice(15) + '/' + key);

				definitions.push(
					{entry, definition: (record.manifest.tiled?.classes || []).find(c => c.name === entry.class)}
				);
			}
	}

	if(!exports.length) throw new Error('Add a package with entity exports first.');

	const dialog = new Dialog('Place Phonics entity');
	const choice = dialog.addComboBox('Entity', exports);
	dialog.addLabel('The entity is placed at the map origin. Move it and edit its typed properties in Tiled.');
	dialog.addButton('Place').clicked.connect(() => dialog.accept());
	dialog.addButton('Cancel').clicked.connect(() => dialog.reject());

	if(dialog.exec() !== Dialog.Accepted) return;

	const {entry, definition} = definitions[choice.currentIndex];

	map.macro('Place Phonics entity', () => {
		const object = new MapObject(entry.class);
		object.className = entry.class;
		object.width = definition?.width || 32;
		object.height = definition?.height || 32;

		for(const member of definition?.members || []) object.setProperty(member.name, member.value);
		object.setProperty('phonics.entity', exports[choice.currentIndex]);
		map.currentLayer.addObject(object);
	});
});

action('PhonicsAttachComponents', 'Attach Phonics Components…', () => {
	const asset = tiled.activeAsset, world = currentWorld();
	const targets = [{label: 'World', values: [world], owner: world}];

	if(asset && asset.isTileMap)
	{
		targets.push({label: 'Map', values: [asset], owner: asset});

		if(asset.currentLayer) targets.push({label: 'Current layer', values: [asset.currentLayer], owner: asset});

		if(asset.selectedObjects.length)
		targets.push({label: 'Selected objects', values: asset.selectedObjects, owner: asset});
	}

	if(asset && asset.isTileset && asset.selectedTiles.length)
	targets.push({label: 'Selected tiles', values: asset.selectedTiles, owner: asset});

	const classes = tiled.project.propertyTypes.filter(type => type.isClass).map(type => type.name);

	if(!classes.length) throw new Error('Define or import component classes first.');

	const dialog = new Dialog('Attach component or preset');
	const target = dialog.addComboBox('Target', targets.map(target => target.label));
	const choice = dialog.addComboBox('Class', classes);
	dialog.addButton('Attach').clicked.connect(() => dialog.accept());
	dialog.addButton('Cancel').clicked.connect(() => dialog.reject());

	if(dialog.exec() !== Dialog.Accepted) return;

	const selected = targets[target.currentIndex], name = classes[choice.currentIndex];

	selected.owner.macro('Attach Phonics components', () => {
		for(const item of selected.values) item.setProperty(name, tiled.propertyValue(name, {}));
	});

	enableComposition(world);
});

action('PhonicsScopeSystems', 'Phonics Scope Systems…', () => {
	const asset = tiled.activeAsset, world = currentWorld();
	const targets = [world];

	if(asset && asset.isTileMap) targets.push(asset);

	const available = type => records().flatMap(record => {
		const entries = Object.entries(record.manifest.exports || {}).filter(([, entry]) => entry.type === type);

		return entries.map(([name]) => record.source + '#' + name);
	});

	const dialog = new Dialog('Scope systems and lifecycle');
	const target = dialog.addComboBox('Scope', targets.map(item => item === world ? 'World' : 'Map'));

	const choices = available('system-set').map(reference => ({
		reference
		, checkbox: dialog.addCheckBox(reference, false)
	}));

	const policies = ['retain', 'snapshot', 'reset'].concat(available('scope-policy'));

	for(const item of targets)
	{
		const policy = item.property('phonics.lifecycle');

		if(policy && !policies.includes(policy)) policies.push(policy);
	}

	const lifecycle = dialog.addComboBox('Lifecycle', policies);
	let existing = [];

	const refresh = () => {
		existing = JSON.parse(targets[target.currentIndex].property('phonics.systems') || '[]');

		for(const choice of choices)
		choice.checkbox.checked = existing.some(
			binding => (typeof binding === 'string' ? binding : binding.use) === choice.reference
		);

		lifecycle.currentIndex = policies.indexOf(
			targets[target.currentIndex].property('phonics.lifecycle') || 'reset'
		);
	};

	target.currentIndexChanged.connect(refresh);
	refresh();
	dialog.addLabel('Dependencies determine execution order. Existing binding options are preserved.');
	dialog.addButton('Save').clicked.connect(() => dialog.accept());
	dialog.addButton('Cancel').clicked.connect(() => dialog.reject());

	if(dialog.exec() !== Dialog.Accepted) return;

	const systems = existing.filter(binding => {
		const reference = typeof binding === 'string' ? binding : binding.use;
		const choice = choices.find(item => item.reference === reference);

		return !choice || choice.checkbox.checked;
	});

	for(const choice of choices)
	if(choice.checkbox.checked
		&& !systems.some(binding => (typeof binding === 'string' ? binding : binding.use) === choice.reference)
	)
	systems.push(choice.reference);
	const policy = policies[lifecycle.currentIndex];

	targets[target.currentIndex].macro('Configure scope systems', () => {
		targets[target.currentIndex].setProperty('phonics.systems', JSON.stringify(systems));
		targets[target.currentIndex].setProperty('phonics.lifecycle', policy);
	});

	const modules = JSON.parse(world.property('phonics.modules') || '[]');

	for(const binding of systems)
	{
		const reference = typeof binding === 'string' ? binding : binding.use;

		if(!modules.includes(reference)) modules.push(reference);
	}

	if(!['retain', 'snapshot', 'reset'].includes(policy) && !modules.includes(policy)) modules.push(policy);

	world.setProperty('phonics.modules', JSON.stringify(modules));
});

action('PhonicsTileEntities', 'Phonics Tile Entities…', () => {
	const asset = tiled.activeAsset;

	if(!asset) throw new Error('Select a map or tileset.');

	const targets = asset.isTileMap ? [asset] : asset.isTileset ? asset.selectedTiles : [];

	if(!targets.length) throw new Error('Select a map or tiles in the tileset editor.');

	const dialog = new Dialog('Tile entities');
	const modes = ['disabled', 'collection', 'placement', 'region'];

	const mode = dialog.addComboBox(
		'Spawn mode'
		, modes
		, Math.max(0, modes.indexOf(targets[0].property('phonics.tiles')))
	);

	const corners = dialog.addCheckBox(
		'Connect diagonal corners'
		, targets[0].property('phonics.connectivity') === 'corners'
	);

	dialog.addLabel('Map defaults can be overridden on individual tiles. Geometry retains each occupied cell.');
	dialog.addButton('Save').clicked.connect(() => dialog.accept());
	dialog.addButton('Cancel').clicked.connect(() => dialog.reject());

	if(dialog.exec() !== Dialog.Accepted) return;

	asset.macro('Configure tile entities', () => {
		for(const item of targets)
		{
			item.setProperty('phonics.tiles', modes[mode.currentIndex]);
			item.setProperty('phonics.connectivity', corners.checked ? 'corners' : 'edges');
		}
	});
});

tiled.assetSaved.connect(() => {
	if(!tiled.projectFilePath) return;

	Qt.callLater(() => {
		try
		{
			for(const world of tiled.worlds) exportClasses(world);
		}
		catch(error)
		{
			tiled.error('Phonics class export: ' + error.message);
		}
	});
});

action('PhonicsPreview', 'Validate and Preview Phonics World…', () => {
	const world = currentWorld();

	for(const asset of tiled.openAssets) if(asset.modified) asset.save();
	world.save();
	exportClasses(world);
	run(['validate', world.fileName, cacheDirectory()]);
	const dialog = new Dialog('Phonics preview');

	const host = dialog.addTextInput(
		'Preview host URL'
		, tiled.project.property('phonics.previewHost') || 'http://localhost:9130/preview/'
	);

	const application = dialog.addTextInput(
		'Application PHAR URL'
		, tiled.project.property('phonics.application')
			|| 'http://localhost:9130/engine/packages/sane-phonics/dist/game.phar'
	);

	const url = dialog.addTextInput(
		'Served world URL'
		, world.property('phonics.previewUrl') || 'http://localhost:9130/'
	);

	dialog.addButton('Launch').clicked.connect(() => dialog.accept());
	dialog.addButton('Cancel').clicked.connect(() => dialog.reject());

	if(dialog.exec() !== Dialog.Accepted) return;

	tiled.project.setProperty('phonics.previewHost', host.text);
	tiled.project.setProperty('phonics.application', application.text);
	world.setProperty('phonics.previewUrl', url.text);
	world.save();

	run(
		['open'
			, host.text + (host.text.includes('?')
				? '&'
				: '?'
			) + 'application=' + encodeURIComponent(application.text) + '&world=' + encodeURIComponent(url.text)
		]
	);
});

if(tiled.menus.includes('File'))
tiled.extendMenu(
	'File'
	, [{separator: true}
		, {action: 'PhonicsAddPackage'}
		, {action: 'PhonicsWorldSettings'}
		, {
			action: 'PhonicsPlaceEntity'
		}
		, {action: 'PhonicsAttachComponents'}
		, {action: 'PhonicsScopeSystems'}
		, {action: 'PhonicsTileEntities'}
		, {action: 'PhonicsPreview'}
	]
);
