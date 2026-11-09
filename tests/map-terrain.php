<?php
use Phonics\Physics\MapTerrain;
use Phonics\Physics\Terrain;
use Phonics\Tiled\Layer;

// MapTerrain::compile() lets a game hook the surface metadata, skip objects it handles elsewhere, and route objects
// into its own Terrain, while the walk over tiles and objects stays the one build() does.

/** A terrain that records what the compiler adds, in order, instead of indexing it. */
final class RecordingTerrain extends Terrain
{
	/** @var list<array{string,list<array{0:float,1:float}>,bool,int,array|bool}> */
	public array $calls = [];

	public function add(
		array $points
		, bool $oneWay = false
		, int $collisionLayer = 0
		, array|bool $surfaceTag = false
	): void {
		$this->calls[] = ['add', $points, $oneWay, $collisionLayer, $surfaceTag];
	}

	public function addPathShape(
		array $points
		, bool $oneWay = true
		, int $collisionLayer = 0
		, array|bool $surfaceTag = false
	): int {
		$this->calls[] = ['path', $points, $oneWay, $collisionLayer, $surfaceTag];

		return count($this->calls);
	}
}

function mapTerrainFixtureMap()
{
	$solid = [['name' => 'solid', 'value' => true]];
	$objects = [
		'type' => 'objectgroup'
		, 'id' => 2
		, 'name' => 'Collision 3'
		, 'offsetx' => 5
		, 'offsety' => 7
		, 'properties' => [['name' => 'surface', 'value' => 'stone']]
		, 'objects' => [
			['id' => 1, 'x' => 10, 'y' => 20, 'width' => 8, 'height' => 4, 'properties' => $solid]
			, ['id' => 2, 'x' => 0, 'y' => 0, 'class' => 'loader', 'properties' => $solid]
			, [
				'id' => 3
				, 'x' => 1
				, 'y' => 2
				, 'polyline' => [['x' => 0, 'y' => 0], ['x' => 6, 'y' => 0]]
				, 'properties' => $solid
			]
			, ['id' => 4, 'x' => 30, 'y' => 40, 'width' => 2, 'height' => 2, 'class' => 'rail']
		]
	];

	return fixture(['a.tmj' => mapData([layer(), $objects]), 'tiles.tsj' => tiles()])->map('a.tmj');
}

test('MapTerrain::compile treats null hooks as no hooks and reaches every shape kind', function() {
	$map = mapTerrainFixtureMap();
	$built = new RecordingTerrain;
	$compiled = new RecordingTerrain;
	MapTerrain::compile($built, [], $map);
	// build() returns a fresh Terrain; compile into a recorder with no hooks and compare against a second recorder
	// driven through the same path, which is what build() does internally.
	MapTerrain::compile($compiled, ['surface' => null, 'skip' => null, 'object' => null], $map);
	check($built->calls !== [] && $built->calls === $compiled->calls, 'null hooks change nothing');
	$kinds = array_map(fn(array $c): string => $c[0], $built->calls);

	check(
		in_array('path', $kinds, true) && count($kinds) >= 4
		, 'tiles, rectangles and the polyline all reach the terrain'
	);

	$last = end($built->calls);

	check(
		$last[3] === 3 && $last[4] === ['stone']
		, 'objects take the layer\'s collision plane and surface metadata by default'
	);
});

test('MapTerrain::compile hooks replace the surface lookup, skip objects, and route objects', function() {
	$map = mapTerrainFixtureMap();
	$plain = new RecordingTerrain;
	MapTerrain::compile($plain, [], $map);
	$seen = [];
	$hooked = new RecordingTerrain;
	$routed = [];

	MapTerrain::compile($hooked, [
		'surface' => function(array $props, array $tileProps, array $layerProps) use (&$seen): bool {
			$seen[] = [$props, $tileProps, $layerProps];

			return (bool)($props['grind'] ?? $tileProps['grind'] ?? $layerProps['grind'] ?? false);
		}
		, 'skip' => fn(array $object, Layer $layer): bool => ($object['class'] ?? '') === 'loader'
		, 'object' => function(
			Terrain $terrain
			, array $object
			, Layer $layer
			, int $collisionLayer
			, callable $project
		) use (&$routed): bool {
			if(($object['class'] ?? '') !== 'rail') return false;

			$routed[] = [$collisionLayer, $project(MapTerrain::points($object))];

			return true;
		}
	], $map
	);

	// The skipped loader object sits at the layer origin with no size, so its default points are four copies of
	// the layer offset; everything else must appear unchanged and in order.
	$skippedPoints = array_fill(0, 4, [5.0, 7.0]);

	$shape = fn(array $c): array => [$c[0], $c[1], $c[2], $c[3]];
	$expected = array_values(array_filter($plain->calls, fn(array $c): bool => $c[1] !== $skippedPoints));
	check(count($hooked->calls) === count($plain->calls) - 1, 'the skipped object is the only entry missing');

	check(
		array_map($shape, $hooked->calls) === array_map($shape, $expected)
		, 'every other shape keeps its points, one-way flag and plane, in order'
	);

	check(
		array_unique(array_map(fn(array $c) => $c[4], $hooked->calls)) === [false]
		, 'the surface hook\'s value is what the terrain receives'
	);

	check(
		$seen !== [] && $seen[0][2] === $map->layers[0]->properties
		, 'the surface hook sees shape, tile and layer properties'
	);

	$routedPoints = [[35.0, 47.0], [37.0, 47.0], [37.0, 49.0], [35.0, 49.0]];
	check($routed === [[3, $routedPoints]], 'a routed object is consumed and its points carry the layer offset');

	check(
		!in_array($routedPoints, array_map(fn(array $c) => $c[1], $hooked->calls), true)
		, 'the default branch did not also add the routed object'
	);
});
