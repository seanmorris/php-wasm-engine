<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
final class Layer
{
	public array $chunks = [];
	public array $objects = [];
	public array $properties = [];
	public bool $visible = true;
	public float $offsetX = 0, $offsetY = 0, $opacity = 1, $parallaxX = 1, $parallaxY = 1;
	public array $tint = [1,1,1,1];
	public ?string $image = null;
	public function __construct(public int $id, public string $name, public string $type) {}
	public function gidAt(int $x,int $y): Gid
	{ foreach($this->chunks as $chunk)
	{ if($chunk->contains($x,$y))
	{ return $chunk->gidAt($x,$y); } } return new Gid(0); }
	public function set(int $x,int $y,$gid): void
	{
		$gid = $gid instanceof Gid?$gid:Gid::from($gid);
		foreach($this->chunks as $chunk)
		{ if($chunk->contains($x,$y))
		{ $chunk->set($x,$y,$gid);
		return; } }
		$cx = (int)floor($x / 32) * 32;
		$cy = (int)floor($y / 32) * 32;
		$chunk = new Chunk($cx,$cy,32,32,str_repeat("\0",4096));
		$chunk->set($x,$y,$gid);
		$this->chunks[] = $chunk;
	}
}
