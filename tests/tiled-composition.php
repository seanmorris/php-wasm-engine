<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Phonics\Asset\Assets;
use Phonics\Ecs\ComponentFactories;
use Phonics\Tiled\
{
	Adapter, ClassCatalog, Loader, Target, TileCells, TileGroups
};
use Phonics\World\
{
	AdapterContext, SourceDefinition, WorldRuntime
};
$checks = 0;

function checkTiled(bool $ok, string $message): void
{
	global $checks;
	$checks++;

	if(!$ok) throw new RuntimeException($message);
}

function propTiled(string $name, mixed $value, string $type = 'string'): array
{
	return compact('name', 'value', 'type');
}

final class CompositionSpeed
{

	public function __construct(public float $speed) {} }

$classes = [
	'classes' => [
		['name' => 'Motion', 'members' => [['name' => 'speed', 'type' => 'float', 'value' => 2]]]
		, [
			'name' => 'Background'
			, 'members' => [
				[
					'name' => 'motion'
					, 'type' => 'class'
					, 'propertyType' => 'Motion'
					, 'value' => ['speed' => 3]
				]
			]
		]
	]
	, 'bindings' => ['Motion' => 'motion']
];

$catalog = new ClassCatalog($classes, '/game/classes.json');

checkTiled($catalog->components([
	'class' => 'Motion'
], '/game/map.json'
)[0]['values']['speed'] === 2, 'omitted member uses project default'
);

checkTiled($catalog->components([
	'class' => 'Background'
], '/game/map.json'
)[0]['values']['speed'] === 3, 'preset overrides component defaults'
);

checkTiled($catalog->components([
	'class' => 'Background'
	, 'properties' => [['name' => 'motion', 'type' => 'class', 'propertytype' => 'Motion', 'value' => ['speed' => 7]]]
], '/game/map.json'
)[0]['values']['speed'] === 7, 'instance overrides preset'
);

$nested = new ClassCatalog([
	'bindings' => ['Movement' => 'movement', 'Animation' => 'animation']
	, 'classes' => [
		['name' => 'Movement', 'members' => [['name' => 'speed', 'type' => 'float', 'value' => 2]]]
		, ['name' => 'Animation', 'members' => [['name' => 'speed', 'type' => 'float', 'value' => 12]]]
		, [
			'name' => 'Actor'
			, 'members' => [
				['name' => 'movement', 'type' => 'class', 'propertyType' => 'Movement', 'value' => ['speed' => 3]]
				, ['name' => 'animation', 'type' => 'class', 'propertyType' => 'Animation', 'value' => ['speed' => 24]]
			]
		]
	]
]);

$values = $nested->components([
	'class' => 'Actor'
	, 'properties' => [
		['name' => 'movement', 'type' => 'class', 'propertytype' => 'Movement', 'value' => ['speed' => 7]]
	]
], '/game/map.json'
);

checkTiled(
	array_column($values, 'values', 'use') === ['movement' => ['speed' => 7], 'animation' => ['speed' => 24]]
	, 'same-named fields remain independent across nested component defaults and instance overrides'
);

$mapData = [
	'type' => 'map'
	, 'orientation' => 'orthogonal'
	, 'tilewidth' => 16
	, 'tileheight' => 16
	, 'width' => 4
	, 'height' => 4
	, 'class' => 'Motion'
	, 'tilesets' => [['firstgid' => 1, 'source' => 'tiles.json']]
	, 'layers' => [
		[
			'id' => 1
			, 'name' => 'group'
			, 'type' => 'group'
			, 'class' => 'Motion'
			, 'offsetx' => 5
			, 'layers' => [
				['id' => 2, 'name' => 'image', 'type' => 'imagelayer', 'class' => 'Background', 'image' => 'sky.png']
				, [
					'id' => 3
					, 'name' => 'objects'
					, 'type' => 'objectgroup'
					, 'class' => 'Motion'
					, 'objects' => [
						[
							'id' => 1
							, 'class' => 'Motion'
							, 'x' => 2
							, 'y' => 3
						]
					]
				]
				, [
					'id' => 4
					, 'name' => 'tiles'
					, 'type' => 'tilelayer'
					, 'class' => 'Motion'
					, 'chunks' => [
						['x' => -1, 'y' => 0, 'width' => 2, 'height' => 2, 'data' => [1,1,1,0]]
						, ['x' => 1, 'y' => 0, 'width' => 2, 'height' => 2, 'data' => [2147483649.0,0,0,1]]
					]
				]
			]
		]
		, ['id' => 5, 'name' => 'second', 'type' => 'tilelayer', 'width' => 1, 'height' => 1, 'data' => [1]]
	]
];

$files = [
	'/game/world.world' => [
		'type' => 'world'
		, 'class' => 'Motion'
		, 'properties' => [propTiled('phonics.classes', 'classes.json', 'file')]
		, 'maps' => [
			['fileName' => 'map.json', 'x' => 100, 'y' => 200, 'width' => 64, 'height' => 64]
			, ['fileName' => 'map.json', 'x' => 300, 'y' => 200, 'width' => 64, 'height' => 64]
		]
	]
	, '/game/classes.json' => $classes
	, '/game/map.json' => $mapData
	, '/game/tiles.json' => [
		'tilewidth' => 16
		, 'tileheight' => 16
		, 'tilecount' => 1
		, 'columns' => 1
		, 'image' => 'tile.png'
		, 'tiles' => [['id' => 0, 'class' => 'Motion']]
	]
];

$reads = [];

$reader = function($path) use (&$files, &$reads): string {
	$reads[] = $path;

	return json_encode($files[$path] ?? throw new RuntimeException($path));
};

$loader = new Loader(new Assets($reader));
$map = $loader->map('/game/map.json');
checkTiled(count($map->layers) === 4 && count($map->allLayers) === 5, 'retain groups separately from rendering leaves');
$copy = clone $map;

checkTiled(
	$copy->allLayers[2] === $copy->layers[0] && $copy->allLayers[2] !== $map->allLayers[2]
	, 'layer targets refer to the cloned render layers'
);

checkTiled(TileGroups::collect($map) === [], 'tile spawning is disabled by default');
$map->data['properties'] = [propTiled('phonics.tiles', 'collection')];
$groups = TileGroups::collect($map);

checkTiled(
	count($groups) === 1 && count(current($groups)['membership']->cells) === 6
	, 'collection spans layers per tile definition'
);

$map->data['properties'] = [propTiled('phonics.tiles', 'placement')];
checkTiled(count(TileGroups::collect($map)) === 6, 'placement makes six independent groups');
$map->data['properties'] = [propTiled('phonics.tiles', 'region')];
$groups = TileGroups::collect($map);
checkTiled(count($groups) === 3, 'edge flood fill crosses chunks but separates layers and diagonal cells');
$large = array_values(array_filter($groups, fn($group) => count($group['membership']->cells) === 4))[0]['membership'];
checkTiled(count($large->polygons($map)) === 4, 'L shaped regions preserve exact cells');
checkTiled(array_sum(array_column($large->cells, 'flags')) === 8, 'preserve flip flags without uint32 casts');
$map->data['properties'][] = propTiled('phonics.connectivity', 'corners');
checkTiled(count(TileGroups::collect($map)) === 2, 'optional diagonal connectivity joins touching corners');
$map->tilesets[0]['definitions'][0]['properties']['phonics.tiles'] = 'disabled';
checkTiled(TileGroups::collect($map) === [], 'tile override can disable a map default');

$reads = [];
$runtime = new WorldRuntime(new AdapterContext(read: $reader));
$runtime->adapter('tiled', new Adapter);
$runtime->source(new SourceDefinition('game', 'tiled', [['url' => '/game/world.world']], ['lifecycle' => 'retain']));
$factories = new ComponentFactories;
$factories->register('motion', fn($values) => new CompositionSpeed((float)$values['speed']));
$runtime->service('components', $factories);
$source = $runtime->load('game');
checkTiled(!in_array('/game/map.json', $reads, true), 'opening world does not load maps');
checkTiled(count($runtime->registry->query(\Phonics\Ecs\Query::all())) === 0, 'inspection creates no entities');
$ids = array_map(fn($descriptor) => $descriptor->id, $runtime->catalog('game'));
checkTiled(count($ids) === 3 && $ids[1] !== $ids[2], 'repeated map placements have distinct identities');
$inspected = $source->inspect($ids[1]);
$inspected->x += 11;
checkTiled($source->inspect($ids[1])->x === 111.0, 'inspection preserves game-controlled map movement');
$worldLease = $runtime->activate('game', 'world');
$mapLease = $runtime->activate('game', $ids[1]);
$scope = $mapLease->context;

checkTiled(
	count(iterator_to_array($scope->query([CompositionSpeed::class]))) === 6
	, 'map, group, image, object layer, tile layer, and object all get components'
);

checkTiled(
	count(iterator_to_array($worldLease->context->query([CompositionSpeed::class], true))) === 7
	, 'world query explicitly includes active descendants'
);

$before = iterator_to_array($scope->query());
$runtime->deactivate('game', $ids[1]);
checkTiled(iterator_to_array($scope->query()) === [], 'retained inactive entities cannot be found with scoped query');
$newLease = $runtime->activate('game', $ids[1]);
$mapLease->release();

checkTiled(
	$scope->active() && iterator_to_array($scope->query()) === $before
	, 'retaining state preserves identity and stale leases cannot retire a new activation'
);

$worldLease->release();
checkTiled(!$scope->active(), 'parent deactivation suspends descendants');
$runtime->close();

checkTiled(
	count($runtime->registry->query(\Phonics\Ecs\Query::all())) === 0
	, 'closing releases every owned component target'
);

// Tile edits are atomic after a scheduler step; unaffected groups keep their identities.
$registry = new \Phonics\Ecs\Registry;
$scheduler = new \Phonics\Ecs\Scheduler($registry);

$tileScope = new \Phonics\World\ScopeContext(
	'tiles'
	, new \Phonics\World\ScopeDescriptor('map', 'map')
	, $registry
	, $scheduler
);

$map = clone $loader->map('/game/map.json');
$map->tilesets[0]['definitions'][0]['properties'] = [];
$map->data['properties'] = [propTiled('phonics.tiles', 'region')];
$manager = new \Phonics\Tiled\TileEntities($map, $tileScope, $catalog, $factories);
$original = $manager->groups();
$unchanged = array_filter($original, fn($g) => $g['membership']->cells[0]['layer'] === 5);
$oldIds = array_column($original, 'entity');
$observed = false;

$scheduler->add('edit', function() use ($map, $manager, &$observed, $oldIds): void {
	$map->allLayers[4]->set(0, 0, 0);
	$manager->update();
	$observed = array_column($manager->groups(), 'entity') === $oldIds;
});

$scheduler->update(.016);
checkTiled($observed, 'membership replacement waits until all systems finish');
checkTiled(array_intersect_key($manager->groups(), $unchanged) === $unchanged, 'unchanged regions keep entities');
checkTiled(count($manager->groups()) === 4, 'deleting a bridge splits connected regions');
$tileScope->close();
checkTiled($registry->query(\Phonics\Ecs\Query::all()) === [], 'tile entities remain scope owned after replacements');

// A projected group remains exact under map/layer movement chosen by a game system.
$map->projection = new \Phonics\Tiled\Projection('isometric', 32, 16, 0);
$cell = new TileCells('tile', 'placement', [['layer' => 4, 'x' => -1, 'y' => 0, 'flags' => 8]]);
$first = $cell->polygons($map);
$map->x += 10;
$map->allLayers[4]->offsetY += 20;
$moved = $cell->polygons($map);

checkTiled(
	$moved[0][0][0] === $first[0][0][0] + 10 && $moved[0][0][1] === $first[0][0][1] + 20
	, 'exact isometric cell geometry reflects explicit map and layer movement'
);

// File member defaults retain their own declaring resource through nested preset overrides.
$filesCatalog = new ClassCatalog([
	'bindings' => ['Image' => 'image']
	, 'classes' => [
		[
			'name' => 'Image'
			, 'members' => [
				['name' => 'file', 'type' => 'file', 'value' => 'default.png']
				, [
					'name' => 'opacity'
					, 'type' => 'float'
					, 'value' => 1
				]
			]
		]
		, [
			'name' => 'Splash'
			, 'source' => '/presets/intro.json'
			, 'members' => [
				[
					'name' => 'image'
					, 'type' => 'class'
					, 'propertyType' => 'Image'
					, 'value' => ['file' => 'title.png']
				]
			]
		]
	]
], '/classes/catalog.json'
);

$result = $filesCatalog->components([
	'class' => 'Splash'
	, 'properties' => [
		['name' => 'image', 'type' => 'class', 'propertytype' => 'Image', 'value' => ['opacity' => .5]]
	]
], '/maps/map.json'
);

checkTiled($result[0]['values'] === [
	'file' => '/presets/title.png'
	, 'opacity' => .5
], 'nested preset file keeps its origin under unrelated override'
);

echo "tiled-composition: $checks checks passed\n";
