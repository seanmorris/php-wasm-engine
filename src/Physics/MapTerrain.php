<?php
declare(strict_types=1);
namespace Phonics\Physics;
use Phonics\Tiled\TileMap;
use Phonics\Tiled\Loader;

/**
 * Compile loaded Tiled collision objects and tiles into game-neutral Terrain geometry.
 *
 * Compilation applies map and layer transforms, preserves object ownership metadata, and produces reusable
 * collision handles for later removal. A game that reads its own surface metadata, leaves some authored objects to
 * other systems, or routes objects into a `Terrain` subclass of its own passes hooks to `compile()`; the walk over
 * layers, chunks, tiles and objects is the same either way, so shape handles and bucket order do not depend on them.
 */
final class MapTerrain
{

	/**
	 * Convert Tiled collision shapes and surface metadata into shared world-space terrain.
	 *
	 * @param TileMap ...$maps Loaded maps whose world placement and inherited layer offsets are
	 * applied.
	 * @return Terrain Spatially indexed polygons, selected collision planes, and contact metadata.
	 */
	public static function build(TileMap ...$maps): Terrain
	{
		return self::compile(new Terrain, [], ...$maps);
	}

	/**
	 * Compile maps into a given terrain, with hooks at the three places games differ.
	 *
	 * `surface(array $shapeProps, array $tileProps, array $layerProps): array|bool` replaces the default surface
	 * metadata lookup for a tile shape or an object. `skip(array $object, Layer $layer): bool` leaves an object to
	 * another system. `object(Terrain $terrain, array $object, Layer $layer, int $collisionLayer, callable $project):
	 * bool` takes an object over entirely when it returns true; `$project(list $points): list` applies the map's
	 * isometric projection and layer offset to points from `points()` exactly as the default does.
	 *
	 * @param Terrain $terrain Terrain, or a game's subclass, to add the geometry to.
	 * @param array<string,callable|null> $hooks Optional `surface`, `skip` and `object` hooks described above.
	 * @param TileMap ...$maps Loaded maps whose world placement and inherited layer offsets are applied.
	 * @return Terrain The same terrain, filled.
	 */
	public static function compile(Terrain $terrain, array $hooks, TileMap ...$maps): Terrain
	{
		$surfaceHook = $hooks['surface'] ?? null;
		$skipHook = $hooks['skip'] ?? null;
		$objectHook = $hooks['object'] ?? null;

		foreach($maps as $map)
		{
			foreach($map->layers as $layer)
			{
				if(!($layer->properties['collision'] ?? true))
				{
					continue;
				}

				$collisionLayer = (int)($layer->properties['collisionLayer'] ?? (preg_match(
					'/^Collision\s*(\d+)$/i'
					, $layer->name
					, $match
				)
					? $match[1]
					: 0
				));

				$ox = $map->x + $layer->offsetX;
				$oy = $map->y + $layer->offsetY;
				$surface = (array)($layer->properties['surface'] ?? []);

				foreach($layer->chunks as $chunk)
				{
					for($y = $chunk->y; $y < $chunk->y + $chunk->height; $y++)
					{
						for($x = $chunk->x; $x < $chunk->x + $chunk->width; $x++)
						{
							$gid = $chunk->gidAt($x, $y);
							$tile = $map->tile($gid->id);

							if(!$tile)
							{
								continue;
							}

							[$px,$py] = $map->projection->project($x, $y);
							$w = $tile['rect'][2];
							$h = $tile['rect'][3];

							if($map->projection->orientation === 'isometric')
							{
								$px -= $w / 2;
							}

							$py += $map->projection->tileHeight - $h;
							$px += $tile['offset'][0];
							$py += $tile['offset'][1];
							$shapes = $tile['objectgroup']['objects'] ?? [];

							if(!$shapes && ($tile['properties']['solid'] ?? $layer->properties[
								'solid'
							]
								?? false
							)){
								$shapes = [['x' => 0,'y' => 0,'width' => $w,'height' => $h]];
							}

							foreach($shapes as $shape)
							{
								$points = self::points($shape);
								$transformed = [];

								foreach($points as [$u,$v])
								{
									[$u,$v] = $gid->uv($u / $w, $v / $h);
									$transformed[] = [$ox + $px + $u * $w,$oy + $py + $v * $h];
								}

								$props = Loader::properties($shape['properties'] ?? []);
								$metadata = $surfaceHook
									? $surfaceHook($props, $tile['properties'] ?? [], $layer->properties)
									: (array)($props['surface'] ?? $tile['properties']['surface'] ?? $surface);

								$terrain->add(
									$transformed
									, (bool)($props['oneWay'] ?? $tile['properties']['oneWay']
										?? $layer->properties['oneWay'] ?? false
									)
									, $collisionLayer
									, $metadata
								);
							}
						}
					}
				}

				/**
				 * Place object points on the map: project them when the map is isometric, then apply the layer offset.
				 *
				 * @param list<array{0:float,1:float}> $points Points from points(), in the object layer's space.
				 * @return list<array{0:float,1:float}> World-space points in pixels.
				 */
				$project = static function(array $points) use ($map, $ox, $oy): array {
					if($map->projection->orientation === 'isometric')
					{
						$points = array_map(
							/**
							 * Project a Tiled isometric object point into map pixel coordinates.
							 *
							 * @param array{0:float,1:float} $p Object-space X and Y measured in
							 * Tiled pixel units.
							 * @return array{0:float,1:float} Projected map-local X and Y.
							 */
							fn($p) => $map->projection->project(
								$p[0] / $map->projection->tileHeight
								, $p[1] / $map->projection->tileHeight
							)
							, $points
						);
					}

					return array_map(
						/**
						 * Translate a shape vertex by its map placement and inherited layer offset.
						 *
						 * @param array{0:float,1:float} $p Vertex before world translation.
						 * @return array{0:float,1:float} World-space X and Y in pixels.
						 */
						fn($p) => [$p[0] + $ox,$p[1] + $oy]
						, $points
					);
				};

				foreach($layer->objects as $object)
				{
					if($skipHook && $skipHook($object, $layer)) continue;

					if($objectHook && $objectHook($terrain, $object, $layer, $collisionLayer, $project)) continue;

					$props = Loader::properties($object['properties'] ?? []);

					if(!($props['solid'] ?? $layer->properties['solid'] ?? false))
					{
						continue;
					}

					$points = $project(self::points($object));
					$plane = (int)($props['collisionLayer'] ?? $collisionLayer);
					$metadata = $surfaceHook
						? $surfaceHook($props, [], $layer->properties)
						: (array)($props['surface'] ?? $surface);

					$oneWay = (bool)($props['oneWay'] ?? $layer->properties['oneWay'] ?? false);

					if(isset($object['polyline']))
					{
						$terrain->addPathShape($points, $oneWay, $plane, $metadata);
					}
					else
					{
						$terrain->add($points, $oneWay, $plane, $metadata);
					}
				}
			}
		}

		return $terrain;
	}

	/**
	 * Expand a Tiled rectangle, ellipse, polygon, or polyline and apply object rotation.
	 *
	 * @param array<string,mixed> $object Tiled object definition; unresolved templates are
	 * rejected.
	 * @return list<array{0:float|int,1:float|int}> Points in the object layer's coordinate space,
	 * before map placement.
	 */
	public static function points(array $object): array
	{
		if(isset($object['template']))
		{
			throw new \UnexpectedValueException('Resolve object templates before export');
		}

		$w = $object['width'] ?? 0;
		$h = $object['height'] ?? 0;
		$points = $object['polygon'] ?? $object['polyline'] ?? [
			['x' => 0,'y' => 0]
			, [
				'x' => $w
				, 'y' => 0
			]
			, ['x' => $w,'y' => $h],['x' => 0,'y' => $h]
		];

		if(!empty($object['ellipse']))
		{
			$points = [];

			for($i = 0; $i < 24; $i++)
			{
				$points[] = [
					'x' => $w / 2 + cos($i * M_PI / 12) * $w / 2
					, 'y' => $h / 2 + sin($i * M_PI / 12) * $h / 2
				];
			}
		}

		$angle = deg2rad($object['rotation'] ?? 0);
		$c = cos($angle);
		$s = sin($angle);

		return array_map(
			/**
			 * Rotate a local object vertex and translate it to the object position.
			 *
			 * @param array{x:float|int,y:float|int} $p Local unrotated shape vertex.
			 * @return array{0:float,1:float} Vertex in the containing layer's coordinate space.
			 */
			fn($p) => [
				($object['x'] ?? 0) + $p['x'] * $c - $p['y'] * $s
				, ($object['y'] ?? 0) + $p[
				'x'
				] * $s + $p['y'] * $c
			]
			, $points
		);
	}
}
