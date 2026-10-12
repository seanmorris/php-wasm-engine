<?php
declare(strict_types=1);
namespace PhpWasmEngine\Spatial;

/** Static AABB tree. Huge or negative coordinates do not allocate empty grid cells. */
final class BoundsIndex
{
	private array $root;

	/**
	 * Build a static bounding-volume tree without allocating cells in empty space.
	 *
	 * @param array<array-key,array<string,mixed>> $entries Keyed records containing x, y, width,
	 * and height in a common coordinate space.
	 * @return void Stores a searchable tree retaining entry keys.
	 */
	public function __construct(array $entries) { $this->root = $this->build($entries); }

	/**
	 * Recursively split entries along the longest bounds axis into leaves of at most eight.
	 *
	 * @param array<array-key,array<string,mixed>> $entries Bounds records whose original keys are
	 * preserved in leaf nodes.
	 * @return array Empty tree, or a node containing bounds, leaf entries, and optional child
	 * nodes.
	 */
	private function build(array $entries): array
	{
		if(!$entries)
		{
			return [];
		}
		$xs = array_column($entries, 'x');
		$ys = array_column($entries, 'y');
		$right = array_map(
			/**
			 * Calculate an entry's right bound.
			 *
			 * @param array<string,mixed> $e Bounds record containing x and width.
			 * @return float|int Right coordinate in the index's coordinate space.
			 */
			fn($e) => $e['x'] + $e['width'], $entries);
		$bottom = array_map(
			/**
			 * Calculate an entry's bottom bound.
			 *
			 * @param array<string,mixed> $e Bounds record containing y and height.
			 * @return float|int Bottom coordinate in the index's coordinate space.
			 */
			fn($e) => $e['y'] + $e['height'], $entries);
		$bounds = [min($xs),min($ys),max($right),max($bottom)];
		if(count($entries) <= 8)
		{
			return [$bounds,$entries];
		}
		$axis = $bounds[2] - $bounds[0] >= $bounds[3] - $bounds[1]?'x':'y';
		uasort($entries,
			/**
			 * Sort entries along the selected subdivision axis while preserving keys.
			 *
			 * @param array<string,mixed> $a First entry with x/y coordinates.
			 * @param array<string,mixed> $b Second entry with x/y coordinates.
			 * @return int Negative, zero, or positive comparison of the chosen coordinate.
			 */
			fn($a, $b) => $a[$axis] <=> $b[$axis]);
		$halves = array_chunk($entries, (int)ceil(count($entries) / 2), true);
		return [$bounds,null,$this->build($halves[0]),$this->build($halves[1])];
	}

	/**
	 * Find entries whose bounds overlap an inclusive query rectangle.
	 *
	 * @param float $left Left query coordinate.
	 * @param float $top Top query coordinate.
	 * @param float $right Right query coordinate.
	 * @param float $bottom Bottom query coordinate.
	 * @return array<array-key,array<string,mixed>> Matching records sorted by original entry key.
	 */
	public function query(float $left, float $top, float $right, float $bottom): array
	{
		$result = [];
		$this->search($this->root, [$left,$top,$right,$bottom], $result);
		ksort($result);
		return $result;
	}

	/**
	 * Test inclusive intersection between two axis-aligned rectangles.
	 *
	 * @param array{0:float|int,1:float|int,2:float|int,3:float|int} $a First rectangle as left,
	 * top, right, bottom.
	 * @param array{0:float|int,1:float|int,2:float|int,3:float|int} $b Second rectangle in the same
	 * coordinate space.
	 * @return bool True when the rectangles overlap or touch at an edge.
	 */
	private function overlaps(array $a, array $b): bool
	{
		return $a[0] <= $b[2] && $a[2] >= $b[0] && $a[1] <= $b[3] && $a[3] >= $b[1];
	}

	/**
	 * Traverse intersecting tree nodes and accumulate matching leaf entries.
	 *
	 * @param array $node Tree node containing bounds and leaf entries or child nodes.
	 * @param array{0:float|int,1:float|int,2:float|int,3:float|int} $query Query rectangle as left,
	 * top, right, bottom.
	 * @param array<array-key,array<string,mixed>> $result Matching entries, updated by reference.
	 * @return void Adds matches to the result array by original entry key.
	 */
	private function search(array $node, array $query, array &$result): void
	{
		if(!$node || !$this->overlaps($node[0], $query))
		{
			return;
		}
		if($node[1] !== null)
		{
			foreach($node[1] as $id => $entry)
			{
				if($this->overlaps([$entry['x'],$entry['y'],$entry['x'] + $entry['width']
				, $entry['y'] + $entry[
					'height']], $query)){
					$result[$id] = $entry;
				}
			}
			return;
		}
		$this->search($node[2], $query, $result);
		$this->search($node[3], $query, $result);
	}
}
