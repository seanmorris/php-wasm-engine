<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;

final class Camera
{

	/**
	 * Describe the top-left world position, pixel viewport, and magnification of a view.
	 *
	 * @param float $x Left world coordinate in pixels.
	 * @param float $y Top world coordinate in pixels.
	 * @param int $width Viewport width in output pixels.
	 * @param int $height Viewport height in output pixels.
	 * @param float $zoom Positive world-to-screen scale factor.
	 * @return void Stores the camera pose and dimensions.
	 */
	public function __construct(
		public float $x = 0
		, public float $y = 0
		, public int $width = 960
		, public int $height = 540
		, public float $zoom = 1
	){
	}

	/**
	 * Smoothly center the camera on a world point using exponential damping.
	 *
	 * @param float $x Target world X coordinate in pixels.
	 * @param float $y Target world Y coordinate in pixels.
	 * @param float $dt Elapsed time in seconds.
	 * @param float $stiffness Nonnegative response rate per second; larger values follow more
	 * tightly.
	 * @return void Updates the camera's top-left position without changing viewport size or zoom.
	 */
	public function follow(float $x, float $y, float $dt, float $stiffness = 8): void
	{
		$blend = 1 - exp(-$stiffness * $dt);
		$this->x += ($x - $this->width / $this->zoom / 2 - $this->x) * $blend;
		$this->y += ($y - $this->height / $this->zoom / 2 - $this->y) * $blend;
	}
}
