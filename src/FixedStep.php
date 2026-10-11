<?php
declare(strict_types=1);
namespace PhpWasmEngine;
final class FixedStep
{
	private float $accumulator = 0;
	public float $droppedTime = 0;
	public function __construct(public float $step = 1 / 60,public int $maxSteps = 8)
	{ if($step <= 0 || $maxSteps < 1)
	{ throw new \InvalidArgumentException('Invalid fixed-step settings'); } }
	/** Returns interpolation fraction. Long stalls discard time instead of spiraling. */
	public function advance(float $elapsed,callable $update): float
	{
		if(!is_finite($elapsed) || $elapsed < 0)
		{ throw new \InvalidArgumentException('Invalid elapsed time'); }
		$cap = $this->step * $this->maxSteps;
		$this->droppedTime += max(0,$elapsed - $cap);
		$this->accumulator += min($elapsed,$cap);
		$steps = 0;
		while($this->accumulator + 1e-10 >= $this->step && $steps++ < $this->maxSteps)
		{ $update($this->step);
		$this->accumulator -= $this->step; }
		return max(0,$this->accumulator / $this->step);
	}
}
