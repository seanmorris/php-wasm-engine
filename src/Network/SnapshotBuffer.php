<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Bounded tick-ordered samples for game-owned interpolation and extrapolation. */
final class SnapshotBuffer
{
	/** @var array<int,WorldSnapshot> */
	private array $samples = [];

	/**
	 * Create and validate a snapshot buffer.
	 *
	 * @param int $capacity Maximum number of entries retained by the bounded collection.
	 * @param int $delayTicks Number of host ticks kept behind for interpolation.
	 * @return void Rejects a capacity outside 2 through 256 or a delay outside 0 through capacity minus one.
	 */
	public function __construct(
		/** Maximum retained entry count. */
		public int $capacity = 32
		, /** Interpolation delay in host ticks. */
		public int $delayTicks = 2
	){
		if($capacity < 2 || $capacity > 256 || $delayTicks < 0 || $delayTicks >= $capacity)
		throw new \OutOfRangeException('Invalid snapshot buffer settings');
	}

	/**
	 * Add a command or snapshot to the bounded history.
	 *
	 * @param WorldSnapshot $snapshot Validated authoritative world snapshot.
	 * @return void No value is returned.
	 */
	public function push(WorldSnapshot $snapshot): void
	{
		$this->samples[$snapshot->tick->value()] = $snapshot;
		ksort($this->samples, SORT_NUMERIC);

		while(count($this->samples) > $this->capacity) unset($this->samples[array_key_first($this->samples)]);
	}

	/**
	 * Select delayed interpolation bounds around a host simulation tick.
	 *
	 * @param SimulationTick $hostTick Current host tick; the buffer samples delayTicks behind it.
	 * @param float $fraction Fractional progress through the current host tick.
	 * @return array{from:WorldSnapshot,to:WorldSnapshot,alpha:float}|null Bounding snapshots and clamped blend;
	 * null when no samples exist.
	 */
	public function sample(SimulationTick $hostTick, float $fraction = 0): ?array
	{
		if(!$this->samples) return null;

		$target = $hostTick->value() - $this->delayTicks + max(0, min(1, $fraction));
		$from = $to = null;

		foreach($this->samples as $tick => $snapshot)
		{
			if($tick <= $target) $from = $snapshot;

			if($tick >= $target)
			{
				$to = $snapshot;
				break;
			}
		}

		$from ??= reset($this->samples);
		$to ??= end($this->samples);
		$span = $to->tick->value() - $from->tick->value();
		$alpha = $span > 0 ? ($target - $from->tick->value()) / $span : 0.0;

		return ['from' => $from,'to' => $to,'alpha' => max(0, min(1, $alpha))];
	}

	/**
	 * Discard all retained snapshot buffer state.
	 *
	 * @return void No value is returned.
	 */
	public function clear(): void { $this->samples = []; }
}
