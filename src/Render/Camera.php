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
	 * Change magnification while keeping one viewport-relative world point fixed on screen.
	 *
	 * Camera coordinates describe the world-space top-left corner, so assigning {@see $zoom}
	 * directly makes the view expand from that corner. Center anchoring is the useful default for
	 * player-following and manually panned views alike; callers can supply another normalized
	 * viewport position for cursor- or target-anchored zoom.
	 *
	 * @param float $zoom Positive world-to-screen scale factor.
	 * @param float $anchorX Horizontal fixed point in the viewport from zero through one.
	 * @param float $anchorY Vertical fixed point in the viewport from zero through one.
	 * @return void Repositions the camera so the selected world point keeps its output position.
	 */
	public function setZoom(float $zoom, float $anchorX = .5, float $anchorY = .5): void
	{
		if(!is_finite($this->zoom) || $this->zoom <= 0 || !is_finite($zoom) || $zoom <= 0
			|| !is_finite($anchorX) || !is_finite($anchorY)
			|| $anchorX < 0 || $anchorX > 1 || $anchorY < 0 || $anchorY > 1
		){
			throw new \InvalidArgumentException('Camera zoom and anchor must be finite and in range');
		}

		$fixedX = $this->x + $this->width / $this->zoom * $anchorX;
		$fixedY = $this->y + $this->height / $this->zoom * $anchorY;
		$this->zoom = $zoom;
		$this->x = $fixedX - $this->width / $zoom * $anchorX;
		$this->y = $fixedY - $this->height / $zoom * $anchorY;
	}

	/**
	 * Smoothly center the camera on a world point using exponential damping.
	 *
	 * @param float $x Target world X coordinate in pixels.
	 * @param float $y Target world Y coordinate in pixels.
	 * @param float $dt Elapsed time in seconds.
	 * @param float $stiffness Nonnegative response rate per second; larger values follow more
	 * tightly.
	 * @param float $anchorX Horizontal target position within the visible world from zero through
	 * one.
	 * @param float $anchorY Vertical target position within the visible world from zero through one.
	 * @return void Updates the camera's top-left position without changing viewport size or zoom.
	 */
	public function follow(
		float $x
		, float $y
		, float $dt
		, float $stiffness = 8
		, float $anchorX = .5
		, float $anchorY = .5
	): void {
		$targetX = $x - $this->width / $this->zoom * $anchorX;
		$targetY = $y - $this->height / $this->zoom * $anchorY;

		$this->panTo($targetX, $targetY, $dt, $stiffness);
	}

	/**
	 * Smoothly move the camera toward a top-left world position using exponential damping.
	 *
	 * @param float $x Target top-left world X coordinate in pixels.
	 * @param float $y Target top-left world Y coordinate in pixels.
	 * @param float $dt Elapsed time in seconds.
	 * @param float $stiffness Nonnegative response rate per second; larger values pan more tightly.
	 * @return void Updates the camera pose without changing viewport size or zoom.
	 */
	public function panTo(float $x, float $y, float $dt, float $stiffness = 8): void
	{
		$blend = 1 - exp(-$stiffness * $dt);
		$this->x += ($x - $this->x) * $blend;
		$this->y += ($y - $this->y) * $blend;
	}
}
