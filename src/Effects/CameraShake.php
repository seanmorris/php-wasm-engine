<?php
declare(strict_types=1);
namespace PhpWasmEngine\Effects;

use PhpWasmEngine\Render\Camera;

/** Frame-rate-independent alternating camera displacement with exponential decay. */
final class CameraShake
{
	public float $x = 0, $y = 0;
	private float $sourceX = 0, $sourceY = 0, $age = 0;

	/**
	 * Replace the current impulse and restart its sixty-hertz reference waveform.
	 *
	 * @param float $x Horizontal displacement in world pixels.
	 * @param float $y Vertical displacement in world pixels.
	 * @return void Starts a new alternating impulse, or clears the effect for two zero values.
	 */
	public function impulse(float $x = 0, float $y = 0): void
	{
		if(!is_finite($x) || !is_finite($y))
		{
			throw new \InvalidArgumentException('Invalid camera shake impulse');
		}

		$this->x = $this->sourceX = $x;
		$this->y = $this->sourceY = $y;
		$this->age = 0;
	}

	/**
	 * Advance the reference sign reversal and five-percent-per-frame decay.
	 *
	 * @param float $dt Nonnegative elapsed simulation seconds.
	 * @return void Updates public offsets and retires subpixel impulses below one tenth pixel.
	 */
	public function update(float $dt): void
	{
		if(!is_finite($dt) || $dt < 0) throw new \InvalidArgumentException('Invalid camera shake delta');

		if($this->sourceX == 0 && $this->sourceY == 0) return;

		$this->age += $dt;
		$frames = $this->age * 60;
		$envelope = pow(.95, $frames);
		$wave = $envelope * cos(M_PI * $frames);
		$this->x = $this->sourceX * $wave;
		$this->y = $this->sourceY * $wave;

		if(max(abs($this->sourceX), abs($this->sourceY)) * $envelope < .1) $this->impulse();
	}

	/**
	 * Apply the current displacement without feeding it back into camera follow state.
	 *
	 * @param Camera $camera Stable gameplay camera to copy.
	 * @return Camera Original camera while inactive, otherwise a displaced render-only clone.
	 */
	public function view(Camera $camera): Camera
	{
		if($this->x == 0 && $this->y == 0) return $camera;

		$view = clone $camera;
		$view->x += $this->x;
		$view->y += $this->y;

		return $view;
	}
}
