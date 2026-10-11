<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
use PhpWasmEngine\Asset\Assets;

final class Loader
{
	private array $maps = [];
	public Assets $assets;
	public function __construct(?Assets $assets = null) { $this->assets = $assets ?? new Assets; }
	public function map(string $path): TileMap
	{
		if(isset($this->maps[$path]))
		{ return $this->maps[$path]; }
		$data = $this->assets->json($path);
		$map = new TileMap($path,$data);
		foreach($data['tilesets'] ?? [] as $entry)
		{
			$source = isset($entry['source'])?Assets::relative($path,$entry['source']):$path;
			$set = isset($entry['source'])?$this->assets->json($source):$entry;
			$set['firstgid'] = (int)$entry['firstgid'];
			$set['definitions'] = $this->definitions($set,$source);
			$map->tilesets[] = $set;
		}
		usort($map->tilesets,fn($a,$b) => $a['firstgid'] <=> $b['firstgid']);
		$this->layers($map,$data['layers'] ?? [],null);
		return $this->maps[$path] = $map;
	}
	public function forget(string $path): void
	{ unset($this->maps[$path]);
	$this->assets->forget($path); }
	public function world(string $path): World { return new World($this,$path,$this->assets->json($path)); }
	public function tileset(string $path): array
	{ $set = $this->assets->json($path);
	$set['firstgid'] = 1;
	return $this->definitions($set,$path); }
	private function definitions(array $set,string $path): array
	{
		$definitions = [];
		$specific = [];
		foreach($set['tiles'] ?? [] as $tile)
		{ $specific[$tile['id']] = $tile; }
		$ids = isset($set['image'])?range(0,max(0,($set['tilecount'] ?? 0) - 1)):array_keys($specific);
		foreach($ids as $id)
		{
			$tile = $specific[$id] ?? [];
			$width = (int)($tile['imagewidth'] ?? $set['tilewidth']);
			$height = (int)($tile['imageheight'] ?? $set['tileheight']);
			$columns = max(1,(int)($set['columns'] ?? 1));
			$margin = $set['margin'] ?? 0;
			$spacing = $set['spacing'] ?? 0;
			$rect = isset($tile['image'])?[0,0,$width,$height]:[$margin + ($id % $columns) * ($width + $spacing),$margin + intdiv($id,$columns) * ($height + $spacing),$width,$height];
			foreach($tile['animation'] ?? [] as $frame)
			{ if(($frame['duration'] ?? 0) <= 0)
			{ throw new \UnexpectedValueException('Animation durations must be positive'); } }
			$definitions[$id] = $tile + ['id' => $id,'firstgid' => $set['firstgid'],'rect' => $rect,'offset' => [$set['tileoffset']['x'] ?? 0,$set['tileoffset']['y'] ?? 0]];
			$definitions[$id]['image'] = Assets::relative($path,$tile['image'] ?? $set['image'] ?? throw new \UnexpectedValueException('Tile has no image'));
			$definitions[$id]['properties'] = self::properties($tile['properties'] ?? []);
		}
		return $definitions;
	}
	private function layers(TileMap $map,array $layers,?Layer $parent): void
	{
		foreach($layers as $data)
		{
			if(($data['mode'] ?? 'normal') !== 'normal')
			{ throw new \UnexpectedValueException('Only normal layer blending is supported'); }
			$layer = new Layer((int)($data['id'] ?? 0),$data['name'] ?? '',$data['type']);
			$layer->visible = ($parent?->visible ?? true) && ($data['visible'] ?? true);
			$layer->offsetX = ($parent?->offsetX ?? 0) + ($data['offsetx'] ?? 0);
			$layer->offsetY = ($parent?->offsetY ?? 0) + ($data['offsety'] ?? 0);
			$layer->opacity = ($parent?->opacity ?? 1) * ($data['opacity'] ?? 1);
			$layer->parallaxX = ($parent?->parallaxX ?? 1) * ($data['parallaxx'] ?? 1);
			$layer->parallaxY = ($parent?->parallaxY ?? 1) * ($data['parallaxy'] ?? 1);
			$layer->tint = self::color($data['tintcolor'] ?? '#ffffff');
			foreach($layer->tint as $i => &$value)
			{ $value *= ($parent?->tint[$i] ?? 1); } unset($value);
			$layer->properties = self::properties($data['properties'] ?? []);
			if($data['type'] === 'group')
			{ $this->layers($map,$data['layers'] ?? [],$layer);
			continue; }
			if($data['type'] === 'tilelayer')
			{
				$chunks = $data['chunks'] ?? [['x' => $data['x'] ?? 0,'y' => $data['y'] ?? 0,'width' => $data['width'],'height' => $data['height'],'data' => $data['data'] ?? []]];
				foreach($chunks as $chunk)
				{
					$w = (int)$chunk['width'];
					$h = (int)$chunk['height'];
					if(!$w || !$h)
					{ continue; }
					$bytes = self::decode($chunk['data'],$w * $h,$data['encoding'] ?? 'csv',$data['compression'] ?? '');
					// Bounded pages also keep a large finite map cullable.
					for($y = 0;$y < $h;$y += 32)
					{ for($x = 0;$x < $w;$x += 32)
					{
						$cw = min(32,$w - $x);
						$ch = min(32,$h - $y);
						$slice = '';
						for($row = 0;$row < $ch;$row++)
						{ $slice .= substr($bytes,(($y + $row) * $w + $x) * 4,$cw * 4); }
						$layer->chunks[] = new Chunk($chunk['x'] + $x,$chunk['y'] + $y,$cw,$ch,$slice);
					} }
				}
			} elseif($data['type'] === 'objectgroup')
			{ $layer->objects = $data['objects'] ?? []; }
			elseif($data['type'] === 'imagelayer')
			{ $layer->image = Assets::relative($map->path,$data['image']); }
			else
			{ throw new \UnexpectedValueException('Unsupported layer type '.$data['type']); }
			$map->layers[] = $layer;
		}
	}
	public static function decode($data,int $count,string $encoding = 'csv',string $compression = ''): string
	{
		if($count < 0 || $count > 16777216)
		{ throw new \UnexpectedValueException('Layer exceeds 16M cell limit'); }
		if(is_array($data))
		{ if(count($data) !== $count)
		{ throw new \UnexpectedValueException('Layer length mismatch'); } $bytes = '';
		foreach($data as $gid)
		{ $bytes .= Gid::from($gid)->bytes(); } return $bytes; }
		if($encoding !== 'base64')
		{ throw new \UnexpectedValueException('Expected JSON array or base64 layer'); }
		$bytes = base64_decode(preg_replace('/\s+/','',$data),true);
		if($bytes === false)
		{ throw new \UnexpectedValueException('Invalid base64 layer'); }
		if($compression === 'zlib')
		{ $bytes = function_exists('gzuncompress')?@gzuncompress($bytes,$count * 4 + 1):false; }
		elseif($compression === 'gzip')
		{ $bytes = function_exists('gzdecode')?@gzdecode($bytes,$count * 4 + 1):false; }
		elseif($compression !== '')
		{ throw new \UnexpectedValueException("Unsupported compression: $compression (export zlib/gzip or uncompressed)"); }
		if(!is_string($bytes) || strlen($bytes) !== $count * 4)
		{ throw new \UnexpectedValueException('Invalid compressed data or layer length'); }
		// Clear the hexagonal rotation bit even on orthogonal/isometric exports.
		for($i = 3;$i < strlen($bytes);$i += 4)
		{ $bytes[$i] = chr(ord($bytes[$i]) & 239); }
		return $bytes;
	}
	public static function properties(array $properties): array
	{ $result = [];
	foreach($properties as $p)
	{ $result[$p['name']] = $p['value']; } return $result; }
	public static function color(string $hex): array
	{ $hex = ltrim($hex,'#');
	if(strlen($hex) === 6)
	{ $hex = 'ff'.$hex; } if(!preg_match('/^[0-9a-f]{8}$/i',$hex))
	{ throw new \InvalidArgumentException('Invalid Tiled color'); } $v = array_map(fn($v) => hexdec($v) / 255,str_split($hex,2));
	return [$v[1],$v[2],$v[3],$v[0]]; }
}
