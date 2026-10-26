import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync,readFileSync,readdirSync,rmSync,writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {basename,dirname,join,resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';

const assets = fileURLToPath(new URL('../examples/assets/', import.meta.url));

const read = path => JSON.parse(readFileSync(path, 'utf8'));

const properties = value => Object.fromEntries(
	(value??[]).map(
		p => [p.name
			, p.type === 'color'
				? (p.value.length === 7 ? '#ff' + p.value.slice(1) : p.value).toLowerCase()
				: p.value
		]
	)
);

const sourcePath = (file, relative) => resolve(dirname(file), relative);

function roundTrip(file, kind, check)
{
	const directory = mkdtempSync(join(tmpdir(), 'php-engine-tiled-'));
	const exported = join(directory, basename(file));

	try
	{
		const result = spawnSync(process.env.TILED_BIN??'tiled', [
			'--export-' + kind,'json',file,exported
		], {
			encoding: 'utf8'
			, timeout: 30000
			, env: {
				...process.env
				, QT_QPA_PLATFORM: process.env.QT_QPA_PLATFORM??(process.env.DISPLAY ? 'xcb' : 'offscreen')
			}
		});

		assert.ifError(result.error);
		assert.equal(result.status, 0, result.stderr||result.stdout);
		check(read(file), read(exported), exported);
	}
	finally
	{
		rmSync(directory, {recursive: true,force: true});
	}
}

// Tiled can resize/repartition infinite chunks when saving. Compare occupied
// cells at their coordinates instead of expecting the same chunk boundaries.

function cells(layer)
{
	const result = new Map();

	for(const chunk of layer.chunks??[layer])
	{
		assert.ok(Array.isArray(chunk.data));
		assert.equal(chunk.data.length, chunk.width*chunk.height);

		chunk.data.forEach((gid, index) => {
			if(gid) result.set(`${(chunk.x??0)+index%chunk.width},${(chunk.y??0)+Math.floor(index/chunk.width)}`, gid);
		});
	}

	return result;
}

function objects(before, after)
{
	assert.equal(after.length, before.length);

	before.forEach((object, index) => {
		const imported = after[index];
		assert.equal(imported.visible, object.visible??true);
		assert.equal(imported.type??imported.class??'', object.type??object.class??'');
		assert.deepEqual(properties(imported.properties), properties(object.properties));

		for(const key of ['id','name','gid','x','y','width','height','rotation','polygon','polyline','point'])
		{
			if(key in object) assert.deepEqual(imported[key], object[key], key);
		}
	});
}

for(const name of readdirSync(assets).filter(name => name.endsWith('.tmj')))
{
	test('Tiled imports ' + name + ' with visible content and intact layers', () => {
		const file = join(assets, name);

		roundTrip(file, 'map', (before, after, exported) => {
			assert.equal(after.orientation, before.orientation);
			assert.equal(after.infinite, before.infinite??false);
			assert.equal(after.layers.length, before.layers.length);
			let visibleTerrain = 0, visibleImages = 0;

			before.layers.forEach((layer, index) => {
				const imported = after.layers[index];

				for(const key of ['id','name','type']) assert.equal(imported[key], layer[key]);
				assert.equal(imported.visible, layer.visible??true, layer.name + ' visibility');
				assert.equal(imported.opacity, layer.opacity??1, layer.name + ' opacity');

				for(const key of ['offsetx','offsety']) assert.equal(imported[key]??0, layer[key]??0);
				assert.deepEqual(properties(imported.properties), properties(layer.properties));

				if(layer.type==='tilelayer')
				{
					assert.deepEqual(cells(imported), cells(layer), layer.name + ' cells');

					if(imported.visible&&imported.opacity>0) visibleTerrain += cells(imported).size;
				}

				if(layer.type==='imagelayer')
				{
					if(imported.visible&&imported.opacity>0) visibleImages++;

					assert.equal(sourcePath(exported, imported.image), sourcePath(file, layer.image));

					for(const key of ['repeatx','repeaty']) assert.equal(imported[key]??false, layer[key]??false);
					assert.equal(imported.parallaxx??1, layer.parallaxx??1);
					const anchored = 'from' in properties(layer.properties);

					assert.equal(
						imported.parallaxy??1
						, layer.parallaxy??(anchored ? 0 : 1)
						, layer.name + ' vertical parallax'
					);
				}

				if(layer.type==='objectgroup') objects(layer.objects, imported.objects);
			});

			if(before.layers.some(layer => layer.type==='tilelayer'))
			{
				assert.ok(visibleTerrain>0, 'the editor must have visible terrain');
			}
			else
			{
				assert.ok(visibleImages>0, 'an image-only map must have visible image layers');
			}

			assert.equal(after.tilesets.length, before.tilesets.length);

			before.tilesets.forEach((set, index) => {
				assert.equal(after.tilesets[index].firstgid, set.firstgid);
				assert.equal(sourcePath(exported, after.tilesets[index].source), sourcePath(file, set.source));
			});
		});
	});
}

for(const name of readdirSync(assets).filter(name => name.endsWith('.tsj')))
{
	test('Tiled preserves ' + name + ' images, animations and collision shapes', () => {
		const file = join(assets, name);

		roundTrip(file, 'tileset', (before, after, exported) => {
			assert.deepEqual(properties(after.properties), properties(before.properties));

			for(const key of ['tilewidth','tileheight','tilecount','columns','imagewidth','imageheight'])
			{
				assert.equal(after[key], before[key], key);
			}

			if(before.image)
			{
				assert.equal(sourcePath(exported, after.image), sourcePath(file, before.image));
			}
			else
			{
				assert.equal(after.image, undefined);
			}

			for(const tile of before.tiles??[])
			{
				const imported = after.tiles.find(candidate => candidate.id===tile.id);
				assert.ok(imported);
				assert.equal(imported.type??imported.class??'', tile.type??tile.class??'');
				assert.deepEqual(properties(imported.properties), properties(tile.properties));
				assert.deepEqual(imported.animation, tile.animation);

				if(tile.image)
				{
					assert.equal(sourcePath(exported, imported.image), sourcePath(file, tile.image));
					assert.equal(imported.imagewidth, tile.imagewidth);
					assert.equal(imported.imageheight, tile.imageheight);
				}

				if(tile.objectgroup) objects(tile.objectgroup.objects, imported.objectgroup.objects);
			}
		});
	});
}

const campaign = fileURLToPath(new URL('../packages/php-noid/worlds/', import.meta.url));

for(const name of readdirSync(campaign).filter(name => name.endsWith('.tmj')))
{
	test('Tiled preserves PhpNoid ' + name + ' entity factories and typed configuration', () => {
		roundTrip(join(campaign, name), 'map', (before, after) => {
			assert.deepEqual(properties(after.properties), properties(before.properties));
			assert.equal(after.layers.length, before.layers.length);

			before.layers.forEach((layer, i) => {
				if(layer.type === 'objectgroup') objects(layer.objects, after.layers[i].objects);
				else if(layer.type === 'tilelayer') assert.deepEqual(cells(after.layers[i]), cells(layer));
			});
		});
	});
}

test('Tiled saves template references and provenance on supported object properties', () => {
	const directory = mkdtempSync(join(tmpdir(), 'phonics-template-'));

	try
	{
		const template = {
			type: 'template'
			, object: {
				type: 'Fixture.Actor'
				, width: 24
				, height: 24
				, properties: [
					{name: 'phonics.source',type: 'string',value: 'https://cdn.test/templates/actor.tx'}
					, {name: 'phonics.entity',type: 'string',value: 'https://cdn.test/phonics.json#actor'}
					, {name: 'speed',type: 'float',value: 3.5}
					, {name: 'texture',type: 'file',value: '../image.png'}
				]
			}
		};

		writeFileSync(join(directory, 'actor.tx'), JSON.stringify(template));
		const file = join(directory, 'level.tmj');

		writeFileSync(
			file
			, JSON.stringify({
				type: 'map'
				, orientation: 'orthogonal'
				, width: 10
				, height: 10
				, tilewidth: 16
				, tileheight: 16
				, layers: [{type: 'objectgroup',id: 1,objects: [{id: 1,template: 'actor.tx',x: 30,y: 40}]}]
				, tilesets: []
			})
		);

		roundTrip(file, 'map', (_before, after, exported) => {
			const object = after.layers[0].objects[0];
			assert.equal(sourcePath(exported, object.template), join(directory, 'actor.tx'));
			assert.deepEqual(read(join(directory, 'actor.tx')), template);

			roundTrip(exported, 'map', (_first, reopened) => {
				assert.equal(reopened.layers[0].objects[0].x, 30);
			});
		});
	}
	finally
	{
		rmSync(directory, {recursive: true,force: true});
	}
});

test('PHPTris challenge grids and typed properties survive Tiled export', () => {
	for(const mode of ['endless','race','time','squares','cascade','targets','bonds','reactor'])
	{
		const file = resolve('packages/php-tris/worlds', mode + '-01.tmj');

		roundTrip(file, 'map', (before, after) => {
			assert.deepEqual(properties(after.properties), properties(before.properties));
			assert.deepEqual(after.layers[0].data, before.layers[0].data);

			assert.deepEqual(
				properties(after.layers[1].objects[0].properties)
				, properties(before.layers[1].objects[0].properties)
			);
		});
	}
});
