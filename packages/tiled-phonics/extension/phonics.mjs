/* Tiled 1.12.2 extension. The helper imports declarative metadata; it never runs game code. */

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

		for(const member of definition.members || [])
		{
			let value = member.value;

			if(member.type === 'file' && !/^https?:|^@/.test(value)) value = tiled.filePath(value);

			if(member.type === 'color') value = tiled.color(value);

			type.setMember(member.name, value);
		}
	}
}

export function configureWorld(world, imports, game, start, modules = [])
{
	world.macro('Configure Phonics world', () => {
		for(const alias of Object.keys(imports)) world.setProperty('phonics.import.' + alias, imports[alias]);
		world.setProperty('phonics.game', game);
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
	const world = currentWorld(), cached = records(), games = [], pallets = [], modules = [];

	for(const [name, url] of Object.entries(world.properties()))
	{
		if(!name.startsWith('phonics.import.')) continue;

		const alias = name.slice(15), record = cached.find(record => record.source === url);

		if(!record) throw new Error('Import is missing from the cache: ' + url);

		for(const [name, entry] of Object.entries(record.manifest.exports))
		{
			pallets.push('@' + alias + '/' + name + ' (' + entry.type + ')');

			if(entry.type === 'game') games.push('@' + alias + '/' + name);

			if(entry.type === 'module') modules.push('@' + alias + '/' + name);
		}
	}

	if(!games.length) throw new Error('Add a package containing a game first.');

	const dialog = new Dialog('Phonics World Settings');
	const game = dialog.addComboBox('Game', games, Math.max(0, games.indexOf(world.property('phonics.game'))));
	const maps = world.maps.map(map => FileInfo.relativePath(FileInfo.path(world.fileName), map.fileName));
	const start = dialog.addComboBox('Starting map', maps, Math.max(0, maps.indexOf(world.property('phonics.start'))));
	const selected = JSON.parse(world.property('phonics.modules') || '[]');
	const checks = modules.map(reference => dialog.addCheckBox(reference, selected.includes(reference)));
	dialog.addLabel('Available pallet exports:\n' + pallets.join('\n'));
	dialog.addButton('Save').clicked.connect(() => dialog.accept());
	dialog.addButton('Cancel').clicked.connect(() => dialog.reject());

	if(dialog.exec() !== Dialog.Accepted) return;

	configureWorld(
		world
		, {}
		, games[game.currentIndex]
		, maps[start.currentIndex]
		, modules.filter((_, i) => checks[i].checked)
	);

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

action('PhonicsPreview', 'Validate and Preview Phonics World…', () => {
	const world = currentWorld();

	for(const asset of tiled.openAssets) if(asset.modified) asset.save();
	world.save();
	run(['validate', world.fileName, cacheDirectory()]);
	const dialog = new Dialog('Phonics preview');

	const host = dialog.addTextInput(
		'Preview host URL'
		, tiled.project.property('phonics.previewHost') || 'http://localhost:9130/preview/'
	);

	const url = dialog.addTextInput(
		'Served world URL'
		, world.property('phonics.previewUrl') || 'http://localhost:9130/'
	);

	dialog.addButton('Launch').clicked.connect(() => dialog.accept());
	dialog.addButton('Cancel').clicked.connect(() => dialog.reject());

	if(dialog.exec() !== Dialog.Accepted) return;

	tiled.project.setProperty('phonics.previewHost', host.text);
	world.setProperty('phonics.previewUrl', url.text);
	world.save();
	run(['open', host.text + (host.text.includes('?') ? '&' : '?') + 'world=' + encodeURIComponent(url.text)]);
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
		, {action: 'PhonicsPreview'}
	]
);
