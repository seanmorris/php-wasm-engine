<?php
declare(strict_types=1);
namespace PhpWasmEngine;

final class FixedStep
{
	private float $accumulator = 0;
	public float $droppedTime = 0;

	/**
	 * Configure a fixed simulation interval and bounded catch-up work.
	 *
	 * @param float $step Positive simulation interval in seconds.
	 * @param int $maxSteps Maximum updates permitted during one advance call; at least one.
	 * @return void Creates an empty time accumulator.
	 */
	public function __construct(public float $step = 1 / 60, public int $maxSteps = 8)
	{
		if($step <= 0 || $maxSteps < 1)
		{
			throw new \InvalidArgumentException('Invalid fixed-step settings');
		}
	}

	/**
	 * Accumulate elapsed time and invoke fixed updates, discarding excess stall time.
	 *
	 * @param float $elapsed Finite, nonnegative elapsed time in seconds.
	 * @param callable(float):void $update Receives the fixed interval in seconds for each
	 * simulation update.
	 * @return float Remaining interpolation fraction between fixed updates, normally in [0,1).
	 */
	public function advance(float $elapsed, callable $update): float
	{
		if(!is_finite($elapsed) || $elapsed < 0)
		{
			throw new \InvalidArgumentException('Invalid elapsed time');
		}

		$cap = $this->step * $this->maxSteps;
		$this->droppedTime += max(0, $elapsed - $cap);
		$this->accumulator += min($elapsed, $cap);
		$steps = 0;

		while($this->accumulator + 1e-10 >= $this->step && $steps++ < $this->maxSteps)
		{
			$update($this->step);
			$this->accumulator -= $this->step;
		}
		return max(0, $this->accumulator / $this->step);
	}
}
