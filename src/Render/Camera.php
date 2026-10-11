<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
final class Camera
{
	public function __construct(public float $x = 0,public float $y = 0,public int $width = 960,public int $height = 540,public float $zoom = 1) {}
	public function follow(float $x,float $y,float $dt,float $stiffness = 8): void
	{
		$blend = 1 - exp(-$stiffness * $dt);
		$this->x += ($x - $this->width / $this->zoom / 2 - $this->x) * $blend;
		$this->y += ($y - $this->height / $this->zoom / 2 - $this->y) * $blend;
	}
}
