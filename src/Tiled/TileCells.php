<?php
declare(strict_types=1);
namespace Phonics\Tiled;

/** Exact tile membership, preserving holes, layer identity, and each placement's flip flags. */
final class TileCells
{

	/**
	 * Store canonical tile identity and stable, sorted map-local cell records.
	 *
	 * @param string $tile Canonical tileset source and local tile identity.
	 * @param string $mode Tile entity mode: disabled, collection, placement, or region.
	 * @param array $cells Sorted exact cells with layer, coordinates, and transform flags.
	 * @return void Initializes the owned references and validates configuration.
	 */
	public function __construct(public string $tile, public string $mode, public array $cells) {}

	/**
	 * Project every cell as its own polygon; no bounding-box replacement or implicit collider.
	 *
	 * @param TileMap $map Map instance whose authored content is being inspected.
	 * @return array One exact world-space polygon per cell, reflecting current offsets.
	 */
	public function polygons(TileMap $map): array
	{
		$result = [];

		foreach($this->cells as $cell)
		{
			$layer = $map->allLayers[$cell['layer']] ?? null;
			$polygon = [];

			foreach([[0,0], [1,0], [1,1], [0,1]] as [$dx, $dy])
			{
				[$x, $y] = $map->projection->project($cell['x'] + $dx, $cell['y'] + $dy);
				$polygon[] = [$x + $map->x + ($layer?->offsetX ?? 0), $y + $map->y + ($layer?->offsetY ?? 0)];
			}

			$result[] = $polygon;
		}

		return $result;
	}
}
