<?php
declare(strict_types=1);
namespace Phonics\Render;

/** Measure camera travel for a frame-rate-independent directional motion-blur response. */
final class MotionBlur
{
	private ?float $x = null, $y = null, $zoom = null;

	/**
	 * Measure camera movement in output pixels at a normalized sixty-frame-per-second rate.
	 *
	 * @param Camera $camera Current camera after following, panning, and world-bound clamping.
	 * @param float $seconds Positive real frame duration, or zero after pause and recovery.
	 * @param bool $enabled Whether motion blur is enabled for the current frame.
	 * @return array{active:bool,sigma:float,x:float,y:float,dx:float,dy:float} Blur state,
	 * unit screen-space direction, and normalized screen displacement.
	 */
	public function sample(Camera $camera, float $seconds, bool $enabled = true): array
	{
		if(!is_finite($seconds) || $seconds < 0)
		{
			throw new \InvalidArgumentException('Motion blur elapsed time must be finite and nonnegative');
		}

		foreach([$camera->x, $camera->y, $camera->zoom] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Motion blur camera values must be finite');
			}
		}

		if($camera->zoom <= 0)
		{
			throw new \InvalidArgumentException('Motion blur camera zoom must be positive');
		}

		if($this->x === null || $seconds == 0 || !$enabled || $this->zoom !== $camera->zoom)
		{
			$this->sync($camera);

			return self::none();
		}

		$frameScale = $seconds * 60;
		$dx = ($camera->x - $this->x) * $camera->zoom / $frameScale;
		$dy = ($camera->y - $this->y) * $camera->zoom / $frameScale;
		$this->sync($camera);
		$xBlur = min(($dx / 6) ** 2, 32);
		$yBlur = min(($dy / 6) ** 2, 32);
		$sigma = max(0, .25 * hypot($xBlur, $yBlur) - 1);
		$distance = hypot($dx, $dy);

		return [
			'active' => $sigma > .5 && $distance > 0
			, 'sigma' => $sigma
			, 'x' => $distance > 0 ? $dx / $distance : 0
			, 'y' => $distance > 0 ? $dy / $distance : 0
			, 'dx' => $dx
			, 'dy' => $dy
		];
	}

	/**
	 * Discard camera history after a scene change, teleport, toggle, or graphics recovery.
	 *
	 * @return void Makes the next sample establish a baseline without producing blur.
	 */
	public function reset(): void
	{
		$this->x = $this->y = $this->zoom = null;
	}

	/**
	 * Store one camera pose as the origin for the next movement sample.
	 *
	 * @param Camera $camera Camera whose world position and zoom become the new baseline.
	 * @return void Replaces all prior sample history.
	 */
	private function sync(Camera $camera): void
	{
		$this->x = $camera->x;
		$this->y = $camera->y;
		$this->zoom = $camera->zoom;
	}

	/**
	 * Build the stable inactive result used while establishing or resetting history.
	 *
	 * @return array{active:bool,sigma:float,x:float,y:float,dx:float,dy:float} Zero blur sample.
	 */
	private static function none(): array
	{
		return ['active' => false, 'sigma' => 0, 'x' => 0, 'y' => 0, 'dx' => 0, 'dy' => 0];
	}
}
