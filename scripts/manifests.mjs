import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {root} from './files.mjs';

async function walk(directory, prefix = '')
{
	let result = [];

	for(const entry of await fs.readdir(directory, {withFileTypes: true}))
	{
		const name = prefix + entry.name;

		if(entry.isDirectory()) result.push(...await walk(path.join(directory, entry.name), name + '/'));
		else if(entry.isFile()) result.push(name);
	}

	return result.sort();
}

const definitions = [
	['.'
		, {
			name: '@seanmorris/phonics'
			, php: {autoload: {'Phonics\\': 'src/'}, bootstrap: ['autoload.php']}
			, exports: {
				font: {type: 'asset', path: 'resources/font.tsj'}
				, buttons: {
					type: 'asset'
					, path: 'resources/buttons.tsj'
				}
				, webrtc: {type: 'transport'}
				, 'memory-lobby': {type: 'lobby-provider'}
			}
			, preload: ['resources/font.tsj', 'resources/font.png', 'resources/buttons.tsj', 'resources/buttons.png']
			, directories: ['src', 'browser', 'shaders', 'resources']
			, files: ['autoload.php', 'legacy.php']
		}
	]
	, ['packages/sane-phonics'
		, {
			name: '@seanmorris/sane-phonics'
			, php: {autoload: {'SanePhonics\\': 'src/'}, bootstrap: ['autoload.php']}
			, exports: {
				game: {type: 'game', php: 'examples/game.php', browser: 'browser/game.mjs', preloadStart: false}
				, platformer: {type: 'module', php: 'modules/platformer.php', preloadStart: true}
				, player: {type: 'factory', class: 'SanePhonics.Player', module: '#platformer'}
				, demo: {type: 'asset', path: 'worlds/demo.world'}
			}
			, tiled: {
				templates: ['templates/player.tx']
				, classes: [{
					name: 'SanePhonics.Player'
					, members: [{
						name: 'profile'
						, type: 'string'
						, value: 'runner'
					}]
				}]
			}
			, preload: []
			, directories: ['src', 'browser', 'shaders', 'modules', 'examples', 'worlds', 'templates']
			, files: ['autoload.php'
				, 'legacy.php'
			]
		}
	]
	, ['packages/php-noid'
		, {
			name: '@seanmorris/php-noid'
			, php: {autoload: {'PhpNoid\\': 'src/'}, bootstrap: ['autoload.php']}
			, exports: {
				game: {type: 'game', php: 'game.php', browser: 'browser/game.mjs', modules: ['#factories']}
				, factories: {type: 'module', php: 'modules/factories.php'}
				, brick: {type: 'factory', class: 'PhpNoid.Brick', module: '#factories'}
				, paddle: {type: 'factory', class: 'PhpNoid.Paddle', module: '#factories'}
				, enemy: {type: 'factory', class: 'PhpNoid.Enemy', module: '#factories'}
				, campaign: {type: 'asset', path: 'worlds/campaign.world'}
				, firstStage: {type: 'asset', path: 'worlds/stage-01.tmj'}
			}
			, tiled: {
				templates: ['templates/brick.tx','templates/paddle.tx','templates/enemy.tx']
				, previews: ['assets/preview.svg']
				, classes: [
					{
						name: 'PhpNoid.Brick'
						, color: '#ff33ccbb'
						, members: [{name: 'hits', type: 'int', value: 1}
							, {name: 'unbreakable', type: 'bool', value: false}
							, {name: 'material', type: 'string', value: 'normal'}
							, {name: 'capsule', type: 'string', value: ''}
							, {name: 'score', type: 'int', value: 100}, {
								name: 'phonics.entity'
								, type: 'string'
								, value: '@php-noid/brick'
							}
						]
					}
					, {name: 'PhpNoid.Paddle', color: '#ffbbddff', members: []}
					, {
						name: 'PhpNoid.Enemy'
						, color: '#ffcc3366'
						, members: [{name: 'family', type: 'string', value: 'menacer'}
							, {name: 'effect', type: 'string', value: 'copper'}
							, {name: 'interval', type: 'float', value: 5}
							, {name: 'hits', type: 'int', value: 3}
							, {name: 'capsule', type: 'string', value: 'expand'}
						]
					}
				]
			}
			, preload: [
				'assets/capsules.json'
				, 'assets/font.json'
				, 'assets/font.png'
				, 'assets/font-sdl.png'
				, 'assets/font.tsj'
				, 'assets/hud-rail.png'
				, 'assets/hud.tsj'
			]
			, directories: ['src', 'browser', 'modules', 'worlds', 'assets', 'templates']
			, files: [
				'autoload.php', 'game.php'
			]
		}
	]
];

definitions.push(
	["packages/php-tris"
		, {
			"name": "@seanmorris/php-tris"
			, "php": {
				"autoload": {"PhpTris\\": "src/"}
				, "bootstrap": [
					"autoload.php"
				]
			}
			, "exports": {
				"game": {
					"type": "game"
					, "php": "game.php"
					, "browser": "browser/game.mjs"
					, "modules": ["#rules"]
				}
				, "rules": {"type": "module", "php": "modules/rules.php"}
				, "board": {"type": "factory", "class": "PhpTris.Board", "module": "#rules"}
				, "arcade": {"type": "asset", "path": "worlds/arcade.world"}
			}
			, "tiled": {
				"templates": ["templates/board.tx"]
				, "previews": ["assets/blocks.png"]
				, "classes": [{
					"name": "PhpTris.Board"
					, "members": [
						{"name": "mode", "type": "string", "value": "endless"}
						, {"name": "seed", "type": "int", "value": 1}
						, {"name": "phonics.entity", "type": "string", "value": "@php-tris/board"}
					]
				}]
			}
			, "preload": []
			, "directories": ["src", "browser", "modules", "worlds", "assets", "templates"]
			, "files": ["autoload.php", "game.php"]
		}
	]
);

definitions.push(
	['packages/doom-phonics'
		, {
			name: '@seanmorris/doom-phonics'
			, php: {autoload: {'DoomPhonics\\': 'src/'}, bootstrap: ['autoload.php']}
			, exports: {
				game: {type: 'game', php: 'game.php', browser: 'browser/game.mjs'}
				, wad: {type: 'adapter', php: 'adapters/wad.php'}
				, 'wad-inspector': {type: 'resource-inspector', browser: 'browser/wad-inspector.mjs'}
				, episode: {type: 'system-set', php: 'modules/episode.php'}
				, decorate: {type: 'asset', path: 'resources/decor.dec'}
				, sounds: {type: 'asset', path: 'resources/sndinfo.txt'}
			}
			, preload: [
				'resources/decor.dec'
				, 'resources/objects.dec'
				, 'resources/weaps.dec'
				, 'resources/doom1-enemies.dec'
				, 'resources/wiki-actors.dec'
				, 'resources/sndinfo.txt'
			]
			, directories: ['src','browser','shaders','modules','adapters','resources']
			, files: ['autoload.php','game.php']
		}
	]
);

definitions.push(
	['packages/phonics-matrix'
		, {
			name: '@seanmorris/phonics-matrix'
			, php: {autoload: {}}
			, exports: {lobby: {type: 'lobby-provider', browser: 'browser/matrix.mjs'}}
			, preload: []
			, directories: ['browser']
			, files: []
		}
	]
);

for(const [directory, definition] of definitions)
{
	const base = path.join(root, directory);
	const files = [...definition.files];

	for(const name of definition.directories) files.push(...(await walk(path.join(base, name), name + '/')));
	const production = files.filter(file => !/\bgenerate-|(?:^|\/)tests\/|legacy-host\.mjs/.test(file));
	const {directories: _directories, files: _files, ...manifest} = definition;

	Object.assign(
		manifest
		, {schemaVersion: 1, version: '0.1.0', requires: {phonics: '^0.1.0', php: '>=8.0.0'}, dependencies: {}}
	);

	manifest.php.files = production.filter(file => file.endsWith('.php'));
	manifest.browser = {files: production.filter(file => file.endsWith('.mjs'))};
	manifest.assets = production.filter(file => !/\.(php|mjs)$/.test(file));
	manifest.preload = [...manifest.preload, ...manifest.assets.filter(path => path.startsWith('shaders/'))];
	manifest.integrity = {};

	for(const file of production)
		manifest.integrity[file] = 'sha256-'
			+ createHash('sha256').update(await fs.readFile(path.join(base, file))).digest('base64');
	await fs.writeFile(path.join(base, 'phonics.json'), JSON.stringify(manifest, null, 2) + '\n');

	if(directory !== '.')
	{
		const packageFile = path.join(base, 'package.json');
		const pkg = JSON.parse(await fs.readFile(packageFile));
		pkg.files = [...production, 'phonics.json', 'composer.json', 'README.md', 'LICENSE'];
		await fs.writeFile(packageFile, JSON.stringify(pkg, null, 2) + '\n');
	}
}

const editor = path.join(root, 'packages/tiled-phonics');

const editorFiles = (await Promise.all(
	['extension','lib','bin'].map(directory => walk(path.join(editor, directory), directory + '/'))
)).flat();

const editorManifest = {
	schemaVersion: 1
	, name: '@seanmorris/tiled-phonics'
	, version: '0.1.0'
	, requires: {phonics: '^0.1.0',tiled: '1.12.2'}
	, dependencies: {}
	, php: {files: [],autoload: {}}
	, browser: {files: []}
	, assets: editorFiles
	, exports: {extension: {type: 'asset',path: 'extension/phonics.mjs'}}
	, integrity: {}
};

for(const file of editorFiles)
editorManifest.integrity[file] = 'sha256-'
	+ createHash('sha256').update(await fs.readFile(path.join(editor, file))).digest('base64');
await fs.writeFile(path.join(editor, 'phonics.json'), JSON.stringify(editorManifest, null, 2) + '\n');

for(const name of ['motion','color'])
{
	const directory = path.join(root, 'tests/fixtures/composition', name);
	const files = (await walk(directory)).filter(file => file.endsWith('.php'));
	const manifest = {
		schemaVersion: 1
		, name: '@fixture/' + name
		, version: '1.0.0'
		, requires: {phonics: '^0.1.0'}
		, dependencies: {}
		, php: {files,autoload: {}}
		, exports: {module: {type: 'module',php: 'module.php'}}
		, integrity: {}
	};

	if(name === 'motion')
	{
		manifest.exports.game = {type: 'game',php: 'game.php',modules: ['#module']};
		manifest.exports.position = {type: 'factory',class: 'Fixture.Position',module: '#module'};
	}

	if(name === 'color')
	{
		manifest.exports.tuning = {type: 'asset',path: 'tuning.json'};
		manifest.assets = ['tuning.json'];
		manifest.preload = ['tuning.json'];
		manifest.php.files = [...files];
		files.push('tuning.json');
	}

	for(const file of files)
	manifest.integrity[file] = 'sha256-'
		+ createHash('sha256').update(await fs.readFile(path.join(directory, file))).digest('base64');
	await fs.writeFile(path.join(directory, 'phonics.json'), JSON.stringify(manifest, null, 2) + '\n');

	await fs.writeFile(
		path.join(directory, 'package.json')
		, JSON.stringify({name: manifest.name,version: manifest.version,files: [...files,'phonics.json']}, null, 2)
			+ '\n'
	);
}
