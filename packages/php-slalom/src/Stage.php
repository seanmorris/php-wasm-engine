<?php
declare(strict_types=1);
namespace PhpSlalom;

/** Stage palette and obstacle generator state. */
final class Stage
{
	public int $time = 0;
	public int $counter = 0;
	public float $roadLeft = -17;
	public float $roadRight = 17;
	public float $roadVelocity = 0;
	public int $roadDirection = 1;

	/**
	 * @param array{int,int,int} $sky RGB sky color.
	 * @param array{int,int,int} $ground RGB ground color.
	 */
	public function __construct(
		public readonly int $threshold
		, public readonly array $sky
		, public readonly array $ground
		, public readonly int $interval = 1
		, public readonly bool $road = false
		, public readonly bool $broken = false
	){
	}

	/** Restore generator state for a fresh or checkpoint run. */
	public function reset(): void
	{
		$this->time = $this->counter = 0;
		$this->roadLeft = -17;
		$this->roadRight = 17;
		$this->roadVelocity = 0;
		$this->roadDirection = 1;
	}
}
