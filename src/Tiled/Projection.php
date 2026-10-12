<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
final class Projection
{
	/**
	 * Configure orthogonal or isometric tile-to-pixel projection.
	 *
	 * @param string $orientation Supported map orientation: orthogonal or isometric.
	 * @param int $tileWidth Tile grid width in pixels.
	 * @param int $tileHeight Tile grid height in pixels.
	 * @param int $mapHeight Finite map height in tiles, used for the isometric horizontal origin; zero for infinite maps.
	 * @return void Validates the supported orientation and positive tile dimensions.
	 */
	public function __construct(public string $orientation, public int $tileWidth, public int $tileHeight, public int $mapHeight = 0)
	{
		if(!in_array($orientation,['orthogonal','isometric'],true))
		{ throw new \InvalidArgumentException("Unsupported orientation: $orientation"); }
		if($tileWidth <= 0 || $tileHeight <= 0)
		{ throw new \InvalidArgumentException('Tile dimensions must be positive'); }
	}
	/**
	 * Convert fractional tile coordinates to map-local pixel coordinates.
	 *
	 * @param float $x Tile column, including fractional or negative positions.
	 * @param float $y Tile row, including fractional or negative positions.
	 * @return array{0:float,1:float} Projected X and Y in pixels, before world placement.
	 */
	public function project(float $x,float $y): array
	{
		return $this->orientation === 'isometric' ? [($x - $y + $this->mapHeight) * $this->tileWidth / 2,($x + $y) * $this->tileHeight / 2] : [$x * $this->tileWidth,$y * $this->tileHeight];
	}
	/**
	 * Invert the map projection without rounding to an individual tile.
	 *
	 * @param float $x Map-local horizontal pixel coordinate.
	 * @param float $y Map-local vertical pixel coordinate.
	 * @return array{0:float,1:float} Fractional tile column and row.
	 */
	public function unproject(float $x,float $y): array
	{
		if($this->orientation === 'orthogonal')
		{ return [$x / $this->tileWidth,$y / $this->tileHeight]; }
		$x = $x / ($this->tileWidth / 2) - $this->mapHeight;
		$y /= $this->tileHeight / 2;
		return [($x + $y) / 2,($y - $x) / 2];
	}
}
