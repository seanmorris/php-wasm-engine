<?php
declare(strict_types=1);
namespace Phonics\Effects;

/** Device-neutral queued dual-motor haptics. */
class Rumble
{
	/** @var list<array{remaining:float,duration:int,strong:float,weak:float}> */
	private array $pulses = [];

	/**
	 * Queue one pulse relative to the current simulation time.
	 *
	 * @param int $duration Nonnegative pulse duration in milliseconds.
	 * @param float $strong Low-frequency motor magnitude in [0,1].
	 * @param float $weak High-frequency motor magnitude in [0,1].
	 * @param int $delay Nonnegative delay before playback in milliseconds.
	 * @return void Appends the pulse without accessing a physical input device.
	 */
	public function pulse(int $duration, float $strong, float $weak, int $delay = 0): void
	{
		if($duration < 0 || $delay < 0 || !is_finite($strong) || $strong < 0 || $strong > 1
			|| !is_finite($weak) || $weak < 0 || $weak > 1
		){
			throw new \InvalidArgumentException('Invalid rumble pulse');
		}

		$this->pulses[] = compact('duration', 'strong', 'weak') + ['remaining' => (float)$delay];
	}

	/**
	 * Advance delayed pulses and remove every effect whose start time has arrived.
	 *
	 * @param float $dt Nonnegative simulation delta in seconds.
	 * @return list<array{duration:int,strong:float,weak:float}> Pulses ready for an input adapter.
	 */
	public function update(float $dt): array
	{
		if(!is_finite($dt) || $dt < 0)
		{
			throw new \InvalidArgumentException('Invalid rumble delta');
		}

		$ready = [];
		$pending = [];
		$milliseconds = $dt * 1000;

		foreach($this->pulses as $pulse)
		{
			$pulse['remaining'] -= $milliseconds;

			if($pulse['remaining'] <= 1e-6)
			{
				unset($pulse['remaining']);
				$ready[] = $pulse;
			}
			else
			{
				$pending[] = $pulse;
			}
		}

		$this->pulses = $pending;

		return $ready;
	}

	/**
	 * Remove immediate and delayed pulses, such as after a reset or disabled preference.
	 *
	 * @return void Removes every queued or active pulse.
	 */
	public function reset(): void { $this->pulses = []; }

	/**
	 * Whether an undelivered pulse is ready to play in this simulation step.
	 *
	 * @return bool True when a pulse starts without a pending delay.
	 */
	public function hasImmediatePulse(): bool
	{
		foreach($this->pulses as $pulse)
		{
			if($pulse['remaining'] <= 1e-6) return true;
		}

		return false;
	}

	/**
	 * Count pulses that have not yet been delivered.
	 *
	 * @return int Number of pulses still awaiting delivery.
	 */
	public function pending(): int { return count($this->pulses); }
}
