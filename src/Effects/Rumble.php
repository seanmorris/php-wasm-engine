<?php
declare(strict_types=1);
namespace PhpWasmEngine\Effects;

/** Device-neutral queued dual-motor haptics, including delayed multi-stage MB2 patterns. */
final class Rumble
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

	/** Queue MB2's Monitor and CanPop two-stage impact pattern. */
	public function pop(): void
	{
		$this->pulse(40, 0, 1);
		$this->pulse(110, .75, 1, 40);
	}

	/** Queue MB2's spring impact and low-frequency release tail. */
	public function spring(): void
	{
		$this->pulse(120, 1, 1);
		$this->pulse(500, 0, .25, 100);
	}

	/** Queue MB2's one-shot breakable-block impact. */
	public function breakBlock(): void { $this->pulse(140, 1, 1); }

	/** Queue MB2 RedEyeJet's successful-hit pattern. */
	public function bossHit(): void
	{
		$this->pulse(120, 1, 1);
		$this->pulse(100, 0, .5, 100);
	}

	/** Queue MB2's ring-loss damage impact and release tail. */
	public function playerDamage(): void
	{
		$this->pulse(350, 1, 1);
		$this->pulse(150, .5, 1, 350);
	}

	/** Queue MB2 BubbleSheild's water rebound impact. */
	public function bubbleRebound(): void { $this->pulse(200, 1, 1); }

	/**
	 * Queue MB2 Platformer's floor-impact pulse from gravity-relative speed in pixels per second.
	 *
	 * MB2 stores velocity in pixels per sixty-hertz frame, so its original clamped `abs(ySpeed *
	 * 10) / 40` expression becomes `abs(speed) / 240` here.
	 */
	public function landing(float $gravitySpeed): void
	{
		if(!is_finite($gravitySpeed)) throw new \InvalidArgumentException('Invalid landing speed');

		$this->pulse(80, 0, max(.25, min(1, abs($gravitySpeed) / 240)));
	}

	/**
	 * Translate an MB2 viewport vertical shake into its corresponding haptic impact.
	 *
	 * Only a growing large shake restarts its haptic pulse, matching Viewport's threshold guards.
	 * Small shakes retain MB2's repeated high-frequency feedback. Magnitudes are normalized because
	 * this runtime validates SDL's nonnegative duration and [0,1] motor ranges.
	 *
	 * @param float $vertical Current vertical camera displacement in world pixels.
	 * @param float $previousVertical Previous displacement, used by the large-shake restart guards.
	 * @return bool True when the shake generated a haptic pulse.
	 */
	public function cameraShake(float $vertical, float $previousVertical = 0): bool
	{
		if(!is_finite($vertical) || !is_finite($previousVertical))
		{
			throw new \InvalidArgumentException('Invalid camera shake');
		}

		$magnitude = abs($vertical);
		$previous = abs($previousVertical);

		if($magnitude > 40 && $magnitude > $previous)
		{
			$this->pulse((int)round(4 * $magnitude), 1, 1);

			return true;
		}

		if($magnitude > 20 && $magnitude > $previous)
		{
			$this->pulse((int)round(8 * $magnitude), min(1, $magnitude / 20), 1);

			return true;
		}

		if($magnitude > 1)
		{
			$this->pulse((int)round(16 * $magnitude), .1, 1);

			return true;
		}

		return false;
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

	/** Remove immediate and delayed pulses, such as after a reset or disabled preference. */
	public function reset(): void { $this->pulses = []; }

	/** Whether an undelivered pulse is ready to play in this simulation step. */
	public function hasImmediatePulse(): bool
	{
		foreach($this->pulses as $pulse)
		{
			if($pulse['remaining'] <= 1e-6) return true;
		}

		return false;
	}

	/** @return int Number of pulses still awaiting delivery. */
	public function pending(): int { return count($this->pulses); }
}
