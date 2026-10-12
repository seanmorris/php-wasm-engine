<?php
declare(strict_types=1);
namespace PhpWasmEngine\Spatial;

/** Static AABB tree. Huge or negative coordinates do not allocate empty grid cells. */
final class BoundsIndex
{
	private array $root;
	public function __construct(array $entries) { $this->root = $this->build($entries); }
	private function build(array $entries): array
	{
		if(!$entries)
		{ return []; }
		$xs = array_column($entries,'x');
		$ys = array_column($entries,'y');
		$right = array_map(fn($e) => $e['x'] + $e['width'],$entries);
		$bottom = array_map(fn($e) => $e['y'] + $e['height'],$entries);
		$bounds = [min($xs),min($ys),max($right),max($bottom)];
		if(count($entries) <= 8)
		{ return [$bounds,$entries]; }
		$axis = $bounds[2] - $bounds[0] >= $bounds[3] - $bounds[1]?'x':'y';
		uasort($entries,fn($a,$b) => $a[$axis] <=> $b[$axis]);
		$halves = array_chunk($entries,(int)ceil(count($entries) / 2),true);
		return [$bounds,null,$this->build($halves[0]),$this->build($halves[1])];
	}
	public function query(float $left,float $top,float $right,float $bottom): array
	{
		$result = [];
		$this->search($this->root,[$left,$top,$right,$bottom],$result);
		ksort($result);
		return $result;
	}
	private function overlaps(array $a,array $b): bool
	{ return $a[0] <= $b[2] && $a[2] >= $b[0] && $a[1] <= $b[3] && $a[3] >= $b[1]; }
	private function search(array $node,array $query,array &$result): void
	{
		if(!$node || !$this->overlaps($node[0],$query))
		{ return; }
		if($node[1] !== null)
		{
			foreach($node[1] as $id => $entry)
			{ if($this->overlaps([$entry['x'],$entry['y'],$entry['x'] + $entry['width'],$entry['y'] + $entry['height']],$query))
			{ $result[$id] = $entry; } }
			return;
		}
		$this->search($node[2],$query,$result);
		$this->search($node[3],$query,$result);
	}
}
