<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
final class Projection
{
	public function __construct(public string $orientation, public int $tileWidth, public int $tileHeight, public int $mapHeight = 0)
	{
		if(!in_array($orientation,['orthogonal','isometric'],true))
		{ throw new \InvalidArgumentException("Unsupported orientation: $orientation"); }
		if($tileWidth <= 0 || $tileHeight <= 0)
		{ throw new \InvalidArgumentException('Tile dimensions must be positive'); }
	}
	public function project(float $x,float $y): array
	{
		return $this->orientation === 'isometric' ? [($x - $y + $this->mapHeight) * $this->tileWidth / 2,($x + $y) * $this->tileHeight / 2] : [$x * $this->tileWidth,$y * $this->tileHeight];
	}
	public function unproject(float $x,float $y): array
	{
		if($this->orientation === 'orthogonal')
		{ return [$x / $this->tileWidth,$y / $this->tileHeight]; }
		$x = $x / ($this->tileWidth / 2) - $this->mapHeight;
		$y /= $this->tileHeight / 2;
		return [($x + $y) / 2,($y - $x) / 2];
	}
}
