<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
use PhpWasmEngine\Tiled\
{Loader,TileMap};
final class SpriteSheet
{
	public TileMap $map;
	public array $animations = [];
	public function __construct(Loader $loader,string $path)
	{
		$tiles = $loader->tileset($path);
		$this->map = new TileMap($path,['tilewidth' => 1,'tileheight' => 1]);
		$this->map->tilesets = [['firstgid' => 1,'definitions' => $tiles]];
		foreach($tiles as $id => $tile)
		{ $this->animations[$tile['class'] ?? $tile['type'] ?? $tile['properties']['name'] ?? (string)$id] = $id + 1; }
	}
	public function sprite(string $animation,float $x = 0,float $y = 0): Sprite { return new Sprite($this->animations[$animation] ?? throw new \OutOfBoundsException("Unknown animation $animation"),$x,$y); }
	/** Start at renderer time; repeated requests for the current animation keep its phase. */
	public function play(Sprite $sprite,string $animation,float $milliseconds,bool $restart = false): bool
	{
		if(!is_finite($milliseconds))
		{ throw new \InvalidArgumentException('Animation time must be finite'); }
		$gid = $this->animations[$animation] ?? throw new \OutOfBoundsException("Unknown animation $animation");
		if($sprite->gid === $gid && !$restart)
		{ return false; }
		$sprite->gid = $gid;
		$sprite->time = -$milliseconds;
		return true;
	}
}
