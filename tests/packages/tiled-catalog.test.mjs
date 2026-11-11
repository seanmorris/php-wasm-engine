import test from 'node:test';
import assert from 'node:assert/strict';
import {tiledCatalog} from '../../browser/TiledCatalog.mjs';

test('portable class validation retains independent members and nested file origins', () => {
	const data = {
		schemaVersion: 1
		, classes: [
			{name: 'Movement', members: [{name: 'speed', type: 'float', value: 2}]}
			, {
				name: 'Animation'
				, members: [{name: 'speed', type: 'float', value: 12}
					, {
						name: 'image'
						, type: 'file'
						, value: 'idle.png'
					}
				]
			}
			, {
				name: 'Actor'
				, source: '../presets/actors.json'
				, members: [
					{name: 'motion', type: 'class', propertyType: 'Movement', value: {speed: 3}}
					, {
						name: 'animation'
						, type: 'class'
						, propertyType: 'Animation'
						, value: {
							speed: 24
							, image: 'run.png'
						}
					}
				]
			}
		]
		, bindings: {Movement: '@game/motion', Animation: '@game/animation'}
	};

	const result = tiledCatalog(data, 'https://game.test/classes/catalog.json');
	assert.deepEqual(result.bindings, ['@game/motion', '@game/animation']);
	assert.deepEqual(result.files, ['https://game.test/classes/idle.png', 'https://game.test/presets/run.png']);
	data.classes[2].members.push({name: 'loop', type: 'class', propertyType: 'Actor', value: {}});
	assert.throws(() => tiledCatalog(data, 'https://game.test/classes/catalog.json'), /cycle/);
	data.classes[2].members.pop();
	data.bindings.Missing = '@game/missing';
	assert.throws(() => tiledCatalog(data, 'https://game.test/classes/catalog.json'), /binding Missing/);
});
