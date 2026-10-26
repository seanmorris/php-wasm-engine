import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
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
					, {name: 'phonics.game', value: '@example/game'}
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
	const extension = fileURLToPath(new URL('../extension/phonics.mjs', import.meta.url));
	const original = fileURLToPath(new URL(`../../${packageName}/worlds/`, import.meta.url));
	await fs.cp(original, directory, {recursive: true});
	const world = path.join(directory, worldName);
	const project = path.join(directory, 'test.tiled-project');
	await fs.writeFile(project, JSON.stringify({folders: ['.'],propertyTypes: []}));
	const extensions = path.join(directory, 'config/tiled/extensions');
	await fs.mkdir(extensions, {recursive: true});
	const resultFile = path.join(directory, 'result.txt');
	const resultLiteral = JSON.stringify(resultFile);

	try
	{
		for(const pass of [1,2])
		{
			await fs.rm(resultFile, {force: true});

			await fs.writeFile(
				path.join(extensions, 'check.mjs')
				, `import {configureWorld,applyClasses} from ${JSON.stringify(extension)};
let tested=false;
const check=() => {
 if(tested) return;
 if(!tiled.project || !tiled.worlds.length) { Qt.callLater(check); return; }
 tested=true;
 try {
  const world=tiled.worlds[0];
  if(${pass} === 1) {
   if(world.property('phonics.game') !== '@${packageName}/game') throw new Error('Lost initial world property');
   const classes=[{name:'Fixture.Actor',members:[{name:'speed',type:'float',value:3.5},{name:'reference',type:'string',value:'@fixture/actor'}]}];
   applyClasses(classes); applyClasses(classes);
   configureWorld(world,{fixture:'https://cdn.test/pkg@1.0.0/phonics.json'},'@fixture/game','${nextMap}',['@fixture/module']);
   if(!world.save()) throw new Error('World save failed');
  } else {
   if(world.property('phonics.game') !== '@fixture/game') throw new Error('Lost game after reopening');
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
	}
	finally
	{
		await fs.rm(directory, {recursive: true,force: true});
	}
});
