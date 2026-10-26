<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Map\MapData;
use DoomPhonics\Map\SpatialIndex;

/** Live map instance: immutable parsed geometry plus independently mutable sector state. */
final class Level
{
	/** @var list<SectorState> */
	public array $sectors = [];
	/** @var array<int,list<int>> */
	public array $tags = [];
	/** @var list<array{upper:string,lower:string,middle:string,xOffset:int,yOffset:int,sector:int}> */
	public array $sideTextures = [];
	/** @var array<int,float> Live texture-column offsets for automatic scrolling lines. */
	public array $wallScroll = [];
	public int $appearanceRevision = 0;
	public SpatialIndex $spatial;
	/** @var array<int,LinePortal|null> */
	private array $portals = [];
	/** @var array<int,int> Unimplemented Hexen specials and their line counts. */
	public array $unsupportedSpecials = [];

	public function __construct(public MapData $map)
	{
		foreach($map->sectors as $sector)
		{
			$this->sectors[] = new SectorState($sector['index'], $sector);

			if($sector['tag']) $this->tags[$sector['tag']][] = $sector['index'];
		}

		foreach($map->sidedefs as $side)
		$this->sideTextures[] = [
			'upper' => strtoupper((string)$side['upper'])
			, 'lower' => strtoupper((string)$side['lower'])
			, 'middle' => strtoupper((string)$side['middle'])
			, 'xOffset' => (int)$side['xOffset']
			, 'yOffset' => (int)$side['yOffset']
			, 'sector' => (int)$side['sector']
		];
		$this->spatial = new SpatialIndex($map);
		if($map->format === 'HEXEN')
		{
			HexenMapSetup::apply($this);
			foreach($map->linedefs as $line)
			if(!in_array($line['action'], [0,12,70,100,101,181,206,210,211], true))
			$this->unsupportedSpecials[$line['action']] = ($this->unsupportedSpecials[$line['action']] ?? 0) + 1;
		}
	}

	public function update(float $dt): void { foreach($this->sectors as $sector) $sector->update($dt); }

	/** Snapshot mutable planes once before the fixed-step geometry systems advance them. */
	public function beginStep(): void { foreach($this->sectors as $sector) $sector->beginStep(); }

	public function setSideTexture(int $side, string $slot, string $texture): void
	{
		if(!isset($this->sideTextures[$side]) || !in_array($slot, ['upper','lower','middle'], true)) return;

		$texture = strtoupper($texture);

		if($this->sideTextures[$side][$slot] === $texture) return;

		$this->sideTextures[$side][$slot] = $texture;
		$this->appearanceRevision++;
	}

	public function setWallScroll(int $line, float $offset): void
	{
		$this->wallScroll[$line] = $offset;
	}

	/** @return array{front:?int,back:?int,frontSide:?int,backSide:?int} */
	public function sides(int $lineId, ?int $fromSector = null): array
	{
		$line = $this->map->linedefs[$lineId];
		$rightSector = $line['right'] >= 0 ? $this->map->sidedefs[$line['right']]['sector'] : null;
		$leftSector = $line['left'] >= 0 ? $this->map->sidedefs[$line['left']]['sector'] : null;

		if($fromSector !== null && $leftSector === $fromSector)
		return [
			'front' => $leftSector
			, 'back' => $rightSector
			, 'frontSide' => $line['left']
			, 'backSide' => $line['right']
		];

		return [
			'front' => $rightSector
			, 'back' => $leftSector
			, 'frontSide' => $line['right'] >= 0 ? $line['right'] : null
			, 'backSide' => $line['left'] >= 0 ? $line['left'] : null
		];
	}

	/** Return the live geometric opening for a two-sided linedef. */
	public function portal(int $lineId): ?LinePortal
	{
		if(array_key_exists($lineId, $this->portals)) return $this->portals[$lineId];

		$line = $this->map->linedefs[$lineId];

		if($line['right'] < 0 || $line['left'] < 0)
		return $this->portals[$lineId] = null;

		$right = (int)$this->map->sidedefs[$line['right']]['sector'];
		$left = (int)$this->map->sidedefs[$line['left']]['sector'];

		return $this->portals[$lineId] = new LinePortal($right, $left, $this->sectors[$right], $this->sectors[$left]);
	}

	/** Return the sole playable sector beside a one-sided terminal linedef. */
	public function terminalSector(int $lineId): ?SectorState
	{
		$line = $this->map->linedefs[$lineId];
		$right = $line['right'] >= 0 ? $this->map->sidedefs[$line['right']]['sector'] : null;
		$left = $line['left'] >= 0 ? $this->map->sidedefs[$line['left']]['sector'] : null;

		if(($right === null) === ($left === null)) return null;

		return $this->sectors[$right ?? $left];
	}

	public function sectorAt(float $x, float $y): SectorState
	{
		return $this->sectors[$this->map->sectorAt((int)round($x), (int)round($y))] ?? $this->sectors[0];
	}

	/** @return list<SectorState> */
	public function neighbors(int $sector): array
	{
		return array_map(fn(int $id): SectorState => $this->sectors[$id], $this->map->neighbors[$sector] ?? []);
	}

	public function lowestNeighborCeiling(int $sector): float
	{
		$values = array_map(static fn(SectorState $s): float => $s->ceiling, $this->neighbors($sector));

		return $values ? min($values) : $this->sectors[$sector]->ceiling;
	}

	public function lowestNeighborFloor(int $sector): float
	{
		$values = array_map(static fn(SectorState $s): float => $s->floor, $this->neighbors($sector));

		return $values ? min($values) : $this->sectors[$sector]->floor;
	}

	public function highestNeighborFloor(int $sector): float
	{
		$values = array_map(static fn(SectorState $s): float => $s->floor, $this->neighbors($sector));

		return $values ? max($values) : $this->sectors[$sector]->floor;
	}

	public function nextHigherFloor(int $sector): float
	{
		$current = $this->sectors[$sector]->floor;

		$values = array_values(
			array_filter(
				array_map(static fn(SectorState $s): float => $s->floor, $this->neighbors($sector))
				, static fn(float $floor): bool => $floor > $current
			)
		);

		return $values ? min($values) : $current;
	}

	/** @return array{float,float} Approximate sound origin inside a sector. */
	public function sectorCenter(int $sector): array
	{
		$x = $y = $count = 0;

		foreach($this->map->linedefs as $line)
		{
			$right = $line['right'] >= 0 ? $this->map->sidedefs[$line['right']]['sector'] : null;
			$left = $line['left'] >= 0 ? $this->map->sidedefs[$line['left']]['sector'] : null;

			if($right !== $sector && $left !== $sector) continue;

			foreach([$line['from'],$line['to']] as $vertex)
			{
				$x += $this->map->vertices[$vertex]['x'];
				$y += $this->map->vertices[$vertex]['y'];
				$count++;
			}
		}

		return $count ? [$x / $count, $y / $count] : [0.0,0.0];
	}
}
