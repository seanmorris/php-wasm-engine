<?php
declare(strict_types=1);
namespace Phonics\Tiled;

/** Optional deterministic grouping of tile placements, independent of ECS and rendering. */
final class TileGroups
{

	/**
	 * Group opted-in tiles by definition, placement, or connected component.
	 *
	 * @param TileMap $map Map instance whose authored content is being inspected.
	 * @return array Deterministic groups keyed by their complete membership signature.
	 */
	public static function collect(TileMap $map): array
	{
		$properties = Loader::properties($map->data['properties'] ?? []);
		$default = $properties['phonics.tiles'] ?? 'disabled';
		$defaultConnectivity = $properties['phonics.connectivity'] ?? 'edges';
		self::validate($default, $defaultConnectivity);
		$definitions = [];

		foreach($map->tilesets as $set)
		foreach($set['definitions'] as $tile)
		{
			$mode = $tile['properties']['phonics.tiles'] ?? $default;
			$connectivity = $tile['properties']['phonics.connectivity'] ?? $defaultConnectivity;
			self::validate($mode, $connectivity);

			if($mode !== 'disabled')
			$definitions[$tile['firstgid'] + $tile['id']] = compact('tile', 'mode', 'connectivity');
		}

		if(!$definitions) return [];

		$buckets = [];

		foreach($map->layers as $layer)
		foreach($layer->chunks as $chunk)
		for($y = $chunk->y; $y < $chunk->y + $chunk->height; $y++)
		for($x = $chunk->x; $x < $chunk->x + $chunk->width; $x++)
		{
			$gid = $chunk->gidAt($x, $y);

			if(!isset($definitions[$gid->id])) continue;

			$definition = $definitions[$gid->id];
			$key = $definition['tile']['identity'];

			if($definition['mode'] !== 'collection') $key .= '/layer:' . $layer->key;

			if($definition['mode'] === 'placement') $key .= "/$x,$y";

			$buckets[$key] ??= $definition + ['cells' => []];
			$buckets[$key]['cells'][$layer->key . ":$x,$y"] = [
				'layer' => $layer->key
				, 'x' => $x
				, 'y' => $y
				, 'flags' => $gid->flags
			];
		}

		$result = [];

		foreach($buckets as $bucket)
		{
			$groups = $bucket['mode'] === 'region'
				? self::regions($bucket['cells'], $bucket['connectivity'])
				: [$bucket['cells']];

			foreach($groups as $cells)
			{
				usort($cells, /** Sort cells by layer, row, and column.
 * @param array $a First cell record.
 * @param array $b Second cell record.
 * @return int Stable cell ordering.
 */
					fn($a, $b) => [$a['layer'], $a['y'], $a['x']] <=> [$b['layer'], $b['y'], $b['x']]
				);

				$membership = new TileCells($bucket['tile']['identity'], $bucket['mode'], $cells);
				$key = hash('sha256', json_encode([$membership->tile, $membership->mode, $cells], JSON_THROW_ON_ERROR));
				$result[$key] = ['membership' => $membership, 'tile' => $bucket['tile']];
			}
		}

		ksort($result);

		return $result;
	}

	/**
	 * Reject mistyped spawning modes instead of silently spawning the wrong entities.
	 *
	 * @param string $mode Tile entity mode: disabled, collection, placement, or region.
	 * @param string $connectivity Neighbor rule: edges or corners.
	 * @return void Rejects invalid dependencies or configuration.
	 */
	private static function validate(string $mode, string $connectivity): void
	{
		if(!in_array($mode, ['disabled', 'collection', 'placement', 'region'], true))
		throw new \InvalidArgumentException("Unknown tile entity mode $mode");

		if(!in_array($connectivity, ['edges', 'corners'], true))
		throw new \InvalidArgumentException("Unknown tile connectivity $connectivity");
	}

	/**
	 * Flood fill exact cells, across chunk boundaries but never across layers or tile definitions.
	 *
	 * @param array $remaining Exact unvisited cells keyed by layer and coordinates.
	 * @param string $connectivity Neighbor rule: edges or corners.
	 * @return array Connected groups preserving exact cells and holes.
	 */
	private static function regions(array $remaining, string $connectivity): array
	{
		$result = [];
		$neighbors = [[-1,0], [1,0], [0,-1], [0,1]];

		if($connectivity === 'corners') array_push($neighbors, [-1,-1], [-1,1], [1,-1], [1,1]);

		while($remaining)
		{
			$key = array_key_first($remaining);
			$queue = [$remaining[$key]];
			unset($remaining[$key]);

			for($index = 0; $index < count($queue); $index++)
			{
				$cell = $queue[$index];

				foreach($neighbors as [$dx, $dy])
				{
					$key = $cell['layer'] . ':' . ($cell['x'] + $dx) . ',' . ($cell['y'] + $dy);

					if(!isset($remaining[$key])) continue;

					$queue[] = $remaining[$key];
					unset($remaining[$key]);
				}
			}

			$result[] = $queue;
		}

		return $result;
	}
}
