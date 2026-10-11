<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
final class TileMap
{
	public array $layers = [], $tilesets = [];
	public float $x = 0, $y = 0;
	public Projection $projection;
	public function __construct(public string $path,public array $data)
	{
		$this->projection = new Projection($data['orientation'] ?? 'orthogonal',(int)$data['tilewidth'],(int)$data['tileheight'],!empty($data['infinite'])?0:(int)($data['height'] ?? 0));
	}
	public function tile(int $gid): ?array
	{
		foreach(array_reverse($this->tilesets) as $set)
		{ if($gid >= $set['firstgid'])
		{ return $set['definitions'][$gid - $set['firstgid']] ?? null; } }
		return null;
	}
	public function animatedGid(int $gid,float $milliseconds): int
	{
		$tile = $this->tile($gid);
		$animation = $tile['animation'] ?? [];
		if(!$animation)
		{ return $gid; }
		$total = array_sum(array_column($animation,'duration'));
		$time = fmod(max(0,$milliseconds),$total);
		foreach($animation as $frame)
		{ if($time < $frame['duration'])
		{ return $tile['firstgid'] + $frame['tileid']; } $time -= $frame['duration']; }
		return $gid;
	}
	public function layer(string $name): Layer
	{ foreach($this->layers as $layer)
	{ if($layer->name === $name)
	{ return $layer; } } throw new \OutOfBoundsException("No layer $name"); }
}
