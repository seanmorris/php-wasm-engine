import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {spawn, spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {classCatalog} from '../extension/catalog.mjs';
import {importPackage, validateWorld} from '../lib/authoring.mjs';

const integrity = value => 'sha256-' + createHash('sha256').update(value).digest('base64');

test(
	'editor cache preserves canonical sources, typed defaults, templates and integrity without running code'
	, async () => {
		const cache = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-editor-'));
		const url = 'https://cdn.test/pkg/phonics.json';

		const template = JSON.stringify({
			type: 'template'
			, object: {
				type: 'Example.Actor'
				, properties: [
					{name: 'speed', type: 'float', value: 3.5}
					, {name: 'url', type: 'string', value: '@example/art'}
					, { name: 'texture', type: 'file', value: 'pixel.png' }
				]
			}
		});

		const manifest = {
			schemaVersion: 1
			, name: '@example/pkg'
			, version: '0.1.0'
			, requires: {phonics: '^0.1.0'}
			, php: {files: ['never-run.php']}
			, exports: {game: {type: 'game', php: 'never-run.php'}}
			, tiled: {
				templates: ['actor.tx']
				, classes: [{
					name: 'Example.Actor'
					, members: [{
						name: 'speed'
						, type: 'float'
						, value: 3.5
					}]
				}]
			}
			, integrity: {'actor.tx': integrity(template), 'pixel.png': integrity('pixels')}
		};

		const requests = [];

		try
		{
			const result = await importPackage(url, cache, {
				fetch: async requested => {
					requests.push(requested);

					return new Response(
						requested === url
							? JSON.stringify(manifest)
							: requested.endsWith('pixel.png') ? 'pixels' : template
					);
				}
			});

			assert.deepEqual(requests, [url, 'https://cdn.test/pkg/actor.tx', 'https://cdn.test/pkg/pixel.png']);
			const preview = result.packages[0].previews[0];
			const cached = JSON.parse(await fs.readFile(path.join(cache, preview.file)));
			assert.equal(cached.object.properties.find(p => p.name === 'phonics.source').value, preview.source);
			assert.equal(cached.object.properties[0].type, 'float');
			assert.equal(cached.object.properties.find(p => p.name === 'texture').type, 'file');

			assert.equal(
				cached.object.properties.find(p => p.name === 'phonics.source.property.texture').value
				, 'https://cdn.test/pkg/pixel.png'
			);

			const world = {
				properties: [{name: 'phonics.import.example', value: url}
					, {name: 'phonics.start', value: 'level.tmj'}
				]
			};

			assert.equal(validateWorld(world, result.packages).resolve('@example/game').type, 'game');
			assert.throws(() => validateWorld(world, []), /not been cached/);
			manifest.integrity['actor.tx'] = integrity('bad');

			await assert.rejects(importPackage(url, cache, {
				fetch: async requested => new Response(
					requested === url ? JSON.stringify(manifest) : requested.endsWith('pixel.png') ? 'pixels' : template
				)
			}), /Integrity mismatch/
			);
		}
		finally
		{
			await fs.rm(cache, {recursive: true, force: true});
		}
	}
);

for(const [packageName, worldName, nextMap] of [
	['php-noid', 'campaign.world', 'stage-02.tmj']
	, ['php-tris', 'arcade.world', 'reactor-02.tmj']
])
test(`Tiled 1.12.2 saves and reopens ${packageName} worlds and typed classes`, async () => {
	if(!process.env.TILED_BIN) throw new Error('TILED_BIN must point to Tiled 1.12.2 for editor tests');

	const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-tiled-'));
	const original = fileURLToPath(new URL(`../../${packageName}/worlds/`, import.meta.url));
	await fs.cp(original, directory, {recursive: true});
	const world = path.join(directory, worldName);
	const project = path.join(directory, 'test.tiled-project');
	const projectData = packageName === 'php-noid'
		? JSON.parse(await fs.readFile(path.join(original, 'campaign.tiled-project')))
		: {folders: ['.'], propertyTypes: []};

	await fs.writeFile(project, JSON.stringify(projectData));
	const extensions = path.join(directory, 'config/tiled/extensions');
	await fs.mkdir(extensions, {recursive: true});
	await fs.cp(new URL('../extension/', import.meta.url), extensions, {recursive: true});
	const resultFile = path.join(directory, 'result.txt');
	const resultLiteral = JSON.stringify(resultFile);

	try
	{
		for(const pass of [1,2])
		{
			await fs.rm(resultFile, {force: true});

			await fs.writeFile(
				path.join(extensions, 'check.mjs')
				, `import {configureWorld,applyClasses,exportClasses} from ${JSON.stringify(
					path.join(extensions, 'phonics.mjs')
				)};
let tested=false;
const check=() => {
 if(tested) return;
 if(!tiled.project || !tiled.worlds.length) { Qt.callLater(check); return; }
 tested=true;
 try {
  const world=tiled.worlds[0];
  if('${packageName}' === 'php-noid') {
   const map=tiled.open(FileInfo.joinPaths(FileInfo.path(tiled.projectFilePath), 'stage-01.tmj'));
   const brickType=tiled.project.findTypeByName('PhpNoid.Brick');
   if(!brickType || brickType.members.hits !== 1 || brickType.members.unbreakable !== false) throw new Error('Brick defaults missing from the active project');
   if(map.layerAt(1).objects[1].property('Brick').typeName !== 'PhpNoid.Brick') throw new Error('Nested Brick property did not resolve in Tiled');
   if(${pass} === 1) {
    map.layerAt(1).objects[1].setProperty('Brick', tiled.propertyValue('PhpNoid.Brick', {hits:4,unbreakable:true}));
    if(!map.save()) throw new Error('Component map save failed');
   }
  }
  if(${pass} === 1) {
   if(!world.property('phonics.start')) throw new Error('Lost initial world property');
   const classes=[{name:'Fixture.Actor',members:[{name:'speed',type:'float',value:3.5},{name:'reference',type:'string',value:'@fixture/actor'}]},{name:'Fixture.Preset',members:[{name:'actor',type:'class',propertyType:'Fixture.Actor',value:{speed:7.5}}]}];
   applyClasses(classes); applyClasses(classes);
   configureWorld(world,{fixture:'https://cdn.test/pkg@1.0.0/phonics.json'},'${nextMap}',['@fixture/module']);
   const cache = FileInfo.joinPaths(FileInfo.path(tiled.projectFilePath), '.phonics-cache');
   if('${packageName}' !== 'php-noid') File.makePath(cache);
   world.setProperty('phonics.classes', tiled.filePath(FileInfo.joinPaths(FileInfo.path(tiled.projectFilePath), 'phonics.classes.json')));
   if(!world.save()) throw new Error('World save failed');
   exportClasses(world);
   const catalogFile = new TextFile(FileInfo.joinPaths(FileInfo.path(tiled.projectFilePath), 'phonics.classes.json'), TextFile.ReadOnly);
   const catalog = JSON.parse(catalogFile.readAll()); catalogFile.close();
   if(!catalog.classes.some(c => c.name === 'Fixture.Preset')) throw new Error('Missing nested project class');
   if('${packageName}' === 'php-noid' && catalog.bindings['PhpNoid.Brick'] !== '@php-noid/brick-component') throw new Error('Lost local component binding without a package cache');
  } else {
   if(world.property('phonics.start') !== '${nextMap}') throw new Error('Lost start after reopening');
   if(world.property('phonics.import.fixture') !== 'https://cdn.test/pkg@1.0.0/phonics.json') throw new Error('Lost import');
   if(world.property('phonics.modules') !== '["@fixture/module"]') throw new Error('Lost module selection');
   if(!tiled.project.findTypeByName('Fixture.Actor')) throw new Error('Lost typed class');
  }
  const file=new TextFile(${resultLiteral},TextFile.WriteOnly);file.write('passed');file.commit();
 } catch(error) { const file=new TextFile(${resultLiteral},TextFile.WriteOnly);file.write(error.message);file.commit(); }
};
tiled.worldsChanged.connect(check);
tiled.activeAssetChanged.connect(check);
tiled.assetOpened.connect(check);
Qt.callLater(check);`
			);

			let output = '';

			const child = spawn(
				process.env.TILED_BIN
				, ['--new-instance','--disable-opengl',project,world]
				, {
					env: {...process.env,XDG_CONFIG_HOME: path.join(directory, 'config'),QT_QPA_PLATFORM: 'xcb'}
				}
			);

			const exited = new Promise(resolve => child.once('exit', resolve));
			child.stderr.on('data', data => {output += data;});

			child.stdout.on('data', data => {output += data;});

			try
			{
				let result;

				for(let attempt = 0; attempt<100; attempt++)
				{
					try {result = await fs.readFile(resultFile, 'utf8');break;} catch { /* Wait for GUI. */ }

					await new Promise(resolve => setTimeout(resolve, 50));
				}

				assert.equal(result, 'passed', output);
			}
			finally
			{
				child.kill('SIGTERM');
				await exited;
			}
		}

		const saved = JSON.parse(await fs.readFile(world));
		assert.equal(saved.maps.length, JSON.parse(await fs.readFile(path.join(original, worldName))).maps.length);
		const types = JSON.parse(await fs.readFile(project)).propertyTypes;
		assert.equal(types.find(type => type.name==='Fixture.Actor').members.find(p => p.name==='speed').type, 'float');

		if(packageName === 'php-noid')
		{
			const catalog = JSON.parse(await fs.readFile(path.join(directory, 'phonics.classes.json')));
			const originalCatalog = JSON.parse(await fs.readFile(path.join(original, 'phonics.classes.json')));
			assert.deepEqual(catalog.bindings, originalCatalog.bindings);
			const map = JSON.parse(await fs.readFile(path.join(directory, 'stage-01.tmj')));
			const brick = map.layers[1].objects[1].properties.find(property => property.name === 'Brick');
			assert.equal(brick.propertytype, 'PhpNoid.Brick');
			assert.deepEqual(brick.value, {hits: 4, unbreakable: true});
		}
	}
	finally
	{
		await fs.rm(directory, {recursive: true,force: true});
	}
});


test('runtime catalogs preserve nested presets and reject cycles and duplicate bindings', () => {
	const definitions = [
		{name: 'Motion', type: 'class', members: [{name: 'speed', type: 'float', value: 3.5}]}
		, {
			name: 'Title'
			, type: 'class'
			, members: [{
				name: 'motion'
				, type: 'class'
				, propertyType: 'Motion'
				, value: {
					speed: 7
				}
			}]
		}
	];

	const records = [{
		source: 'https://game.test/phonics.json'
		, manifest: {
			exports: {
				motion: {
					type: 'component'
					, class: 'Motion'
				}
			}
		}
	}];

	const catalog = classCatalog(definitions, records);
	assert.equal(catalog.bindings.Motion, 'https://game.test/phonics.json#motion');
	assert.equal(catalog.classes[1].members[0].value.speed, 7);

	assert.throws(
		() => classCatalog([{name: 'Loop', members: [{name: 'loop', type: 'class', propertyType: 'Loop'}]}])
		, /cycle/
	);

	assert.throws(() => classCatalog([...definitions, definitions[0]]), /Duplicate/);

	assert.throws(
		() => classCatalog(
			definitions
			, [...records, {source: 'https://other.test/phonics.json', manifest: records[0].manifest}]
		)
		, /Conflicting/
	);
});

test('local catalog bindings survive missing caches and follow authoritative cached exports', () => {
	const definitions = [{name: 'Brick', members: [{name: 'hits', type: 'int', value: 2}]}];
	const previous = {Brick: '@noid/brick', Removed: '@noid/removed'};
	const catalog = classCatalog(definitions, [], previous);
	assert.deepEqual(catalog.bindings, {Brick: '@noid/brick'});
	assert.equal(catalog.classes[0].members[0].value, 2);
	assert.deepEqual(previous, {Brick: '@noid/brick', Removed: '@noid/removed'});
	const records = [{
		source: 'https://game.test/phonics.json'
		, manifest: {exports: {brick: {type: 'component', class: 'Brick'}}}
	}];

	assert.equal(classCatalog(definitions, records, previous).bindings.Brick, 'https://game.test/phonics.json#brick');
	assert.throws(() => classCatalog(definitions, [], {Brick: 42}), /Invalid component binding/);
});

test('catalog CLI retains local bindings without a cache and leaves invalid exports untouched', async () => {
	const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-local-catalog-'));
	const project = path.join(directory, 'game.tiled-project');
	const output = path.join(directory, 'phonics.classes.json');
	const cli = fileURLToPath(new URL('../bin/tiled-phonics.mjs', import.meta.url));

	try
	{
		await fs.writeFile(project, JSON.stringify({propertyTypes: [{name: 'Brick', members: []}]}));
		await fs.writeFile(output, JSON.stringify({bindings: {Brick: '@noid/brick'}}));
		const args = [cli, 'catalog', project, path.join(directory, 'missing-cache'), output];
		const result = spawnSync(process.execPath, args, {encoding: 'utf8'});
		assert.equal(result.status, 0, result.stderr);
		const saved = await fs.readFile(output, 'utf8');
		assert.deepEqual(JSON.parse(saved).bindings, {Brick: '@noid/brick'});
		await fs.writeFile(project, '{invalid json');
		assert.notEqual(spawnSync(process.execPath, args).status, 0);
		assert.equal(await fs.readFile(output, 'utf8'), saved);
	}
	finally
	{
		await fs.rm(directory, {recursive: true, force: true});
	}
});
