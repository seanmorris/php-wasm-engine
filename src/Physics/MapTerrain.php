<?php
declare(strict_types=1);
namespace PhpWasmEngine\Physics;
use PhpWasmEngine\Tiled\
{TileMap,Loader};

final class MapTerrain
{
	/** Collidable tile metadata and object layers become world-space polygons. */
	public static function build(TileMap ...$maps): Terrain
	{
		$terrain = new Terrain;
		foreach($maps as $map)
		{ foreach($map->layers as $layer)
		{
			$ox = $map->x + $layer->offsetX;
			$oy = $map->y + $layer->offsetY;
			foreach($layer->chunks as $chunk)
			{ for($y = $chunk->y;$y < $chunk->y + $chunk->height;$y++)
			{ for($x = $chunk->x;$x < $chunk->x + $chunk->width;$x++)
			{
				$gid = $chunk->gidAt($x,$y);
				$tile = $map->tile($gid->id);
				if(!$tile)
				{ continue; }
				[$px,$py] = $map->projection->project($x,$y);
				$w = $tile['rect'][2];
				$h = $tile['rect'][3];
				if($map->projection->orientation === 'isometric')
				{ $px -= $w / 2; }
				$py += $map->projection->tileHeight - $h;
				$px += $tile['offset'][0];
				$py += $tile['offset'][1];
				$shapes = $tile['objectgroup']['objects'] ?? [];
				if(!$shapes && ($tile['properties']['solid'] ?? $layer->properties['solid'] ?? false))
				{ $shapes = [['x' => 0,'y' => 0,'width' => $w,'height' => $h]]; }
				foreach($shapes as $shape)
				{
					$points = self::points($shape);
					$transformed = [];
					foreach($points as [$u,$v])
					{ [$u,$v] = $gid->uv($u / $w,$v / $h);
					$transformed[] = [$ox + $px + $u * $w,$oy + $py + $v * $h]; }
					$props = Loader::properties($shape['properties'] ?? []);
					$terrain->add($transformed,(bool)($props['oneWay'] ?? $tile['properties']['oneWay'] ?? $layer->properties['oneWay'] ?? false));
				}
			} } }
			foreach($layer->objects as $object)
			{
				$props = Loader::properties($object['properties'] ?? []);
				if(!($props['solid'] ?? $layer->properties['solid'] ?? false))
				{ continue; }
				$points = self::points($object);
				if($map->projection->orientation === 'isometric')
				{ $points = array_map(fn($p) => $map->projection->project($p[0] / $map->projection->tileHeight,$p[1] / $map->projection->tileHeight),$points); }
				$terrain->add(array_map(fn($p) => [$p[0] + $ox,$p[1] + $oy],$points),(bool)($props['oneWay'] ?? false));
			}
		} }
		return $terrain;
	}
	public static function points(array $object): array
	{
		if(isset($object['template']))
		{ throw new \UnexpectedValueException('Resolve object templates before export'); }
		$w = $object['width'] ?? 0;
		$h = $object['height'] ?? 0;
		$points = $object['polygon'] ?? $object['polyline'] ?? [['x' => 0,'y' => 0],['x' => $w,'y' => 0],['x' => $w,'y' => $h],['x' => 0,'y' => $h]];
		if(!empty($object['ellipse']))
		{ $points = [];
		for($i = 0;$i < 24;$i++)
		{ $points[] = ['x' => $w / 2 + cos($i * M_PI / 12) * $w / 2,'y' => $h / 2 + sin($i * M_PI / 12) * $h / 2]; } }
		$angle = deg2rad($object['rotation'] ?? 0);
		$c = cos($angle);
		$s = sin($angle);
		return array_map(fn($p) => [($object['x'] ?? 0) + $p['x'] * $c - $p['y'] * $s,($object['y'] ?? 0) + $p['x'] * $s + $p['y'] * $c],$points);
	}
}
