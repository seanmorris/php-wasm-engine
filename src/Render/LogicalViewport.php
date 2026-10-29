<?php
declare(strict_types=1);
namespace Phonics\Render;

/** Aspect-preserving placement and pointer conversion for a fixed logical canvas. */
final class LogicalViewport
{

	/**
	 * Store a validated fit between logical and output coordinates.
	 *
	 * @param float $logicalWidth Width of the logical canvas.
	 * @param float $logicalHeight Height of the logical canvas.
	 * @param float $x Horizontal output offset of the fitted canvas.
	 * @param float $y Vertical output offset of the fitted canvas.
	 * @param float $width Width of the fitted canvas in output coordinates.
	 * @param float $height Height of the fitted canvas in output coordinates.
	 * @param float $scale Uniform scale from logical to output coordinates.
	 * @return void The viewport is available through its promoted properties.
	 */
	private function __construct(
		public float $logicalWidth
		, public float $logicalHeight
		, public float $x
		, public float $y
		, public float $width
		, public float $height
		, public float $scale
	){
	}

	/**
	 * Fit a logical canvas inside an output surface without cropping or distortion.
	 *
	 * @param float $logicalWidth Width of the logical canvas.
	 * @param float $logicalHeight Height of the logical canvas.
	 * @param float $outputWidth Width of the available output surface.
	 * @param float $outputHeight Height of the available output surface.
	 * @return self A centered, aspect-preserving viewport.
	 */
	public static function contain(
		float $logicalWidth
		, float $logicalHeight
		, float $outputWidth
		, float $outputHeight
	): self {
		foreach([$logicalWidth, $logicalHeight, $outputWidth, $outputHeight] as $dimension)
		{
			if(!is_finite($dimension) || $dimension <= 0)
			{
				throw new \InvalidArgumentException('Viewport dimensions must be finite and positive');
			}
		}

		$scale = min($outputWidth / $logicalWidth, $outputHeight / $logicalHeight);
		$width = $logicalWidth * $scale;
		$height = $logicalHeight * $scale;

		return new self(
			$logicalWidth
			, $logicalHeight
			, ($outputWidth - $width) / 2
			, ($outputHeight - $height) / 2
			, $width
			, $height
			, $scale
		);
	}

	/**
	 * Report whether an output-space point falls inside the fitted logical canvas.
	 *
	 * @param float $x Horizontal coordinate in output space.
	 * @param float $y Vertical coordinate in output space.
	 * @return bool True when the point lies on or inside the viewport.
	 */
	public function contains(float $x, float $y): bool
	{
		return is_finite($x) && is_finite($y)
			&& $x >= $this->x && $x <= $this->x + $this->width
			&& $y >= $this->y && $y <= $this->y + $this->height;
	}

	/**
	 * Convert an output-space point into logical coordinates.
	 *
	 * @param float $x Horizontal coordinate in output space.
	 * @param float $y Vertical coordinate in output space.
	 * @param bool $clamp Whether to constrain the result to the logical canvas.
	 * @return array{0:float,1:float} Horizontal and vertical logical coordinates.
	 */
	public function toLogical(float $x, float $y, bool $clamp = true): array
	{
		if(!is_finite($x) || !is_finite($y))
		{
			throw new \InvalidArgumentException('Viewport coordinates must be finite');
		}

		$x = ($x - $this->x) / $this->scale;
		$y = ($y - $this->y) / $this->scale;

		if($clamp)
		{
			$x = max(0, min($this->logicalWidth, $x));
			$y = max(0, min($this->logicalHeight, $y));
		}

		return [$x, $y];
	}

	/**
	 * Return the output-space rectangle in renderer order.
	 *
	 * @return array{0:float,1:float,2:float,3:float} X, Y, width, and height.
	 */
	public function rectangle(): array
	{
		return [$this->x, $this->y, $this->width, $this->height];
	}
}
