<?php
declare(strict_types=1);
namespace Phonics\Effects;

/**
 * The age of a short-lived thing against its duration: an explosion, a trail piece, a popup, a beam.
 *
 * The age advances with the simulation and keeps the previous step's value, so a renderer can interpolate the
 * remaining fraction between fixed steps. Opacity fades from a chosen age to the end; expiry is reaching the
 * duration. Games extend this class with a marker of their own when they query effects by kind, since the registry
 * indexes parent classes. The arithmetic matches the hand-written forms it replaces: `1 - age / duration` for a
 * fade from the start, and the `<=` boundary at the fade start.
 */
class Lifetime
{
	/** Age in seconds, clamped to the duration unless the lifetime is unclamped. */
	public float $age = 0;
	/** The age at the start of the current step, for interpolation. */
	public float $previousAge = 0;

	/**
	 * Start a lifetime at age zero.
	 *
	 * @param float $duration Seconds until expiry; positive, and infinite for a thing that never expires on its own.
	 * @param float $fadeStart Age at which opacity begins to fall from one; zero fades over the whole life.
	 * @param bool $clamp Whether the age stops at the duration (true) or keeps counting past it (false).
	 * @return void Validates the duration and fade start.
	 */
	public function __construct(public float $duration, public float $fadeStart = 0.0, public bool $clamp = true)
	{
		if(is_nan($duration) || $duration <= 0)
		throw new \InvalidArgumentException('Lifetime duration must be positive');

		if(!is_finite($fadeStart) || $fadeStart < 0 || $fadeStart >= $duration)
		throw new \InvalidArgumentException('Lifetime fade start must lie within the duration');
	}

	/**
	 * Advance by one step, remembering where the step began.
	 *
	 * @param float $seconds Step length; a negative value counts as zero.
	 * @return void Moves the age forward, to the duration at most when clamped.
	 */
	public function advance(float $seconds): void
	{
		$this->previousAge = $this->age;
		$this->age = $this->clamp ? min($this->duration, $this->age + max(0, $seconds)) : $this->age + $seconds;
	}

	/**
	 * Whether the age has reached the duration.
	 *
	 * @return bool True once the lifetime is over.
	 */
	public function expired(): bool { return $this->age >= $this->duration; }

	/**
	 * The fraction of the life still to come, interpolated within the current step.
	 *
	 * @param float $alpha Position within the step, zero at its start and one at its end; clamped.
	 * @return float From one at birth down to zero at expiry.
	 */
	public function remaining(float $alpha = 1): float
	{
		$alpha = max(0.0, min(1.0, $alpha));
		$age = $this->previousAge + ($this->age - $this->previousAge) * $alpha;

		return max(0.0, 1.0 - $age / $this->duration);
	}

	/**
	 * Opacity for the current age: one until the fade start, then falling to zero at expiry.
	 *
	 * @return float Between one and zero; not clamped below zero for an unclamped lifetime past its duration.
	 */
	public function opacity(): float
	{
		return $this->age <= $this->fadeStart
			? 1
			: 1 - ($this->age - $this->fadeStart) / ($this->duration - $this->fadeStart);
	}

	/**
	 * Catch up to an age reported from elsewhere, such as a network host, without ever rewinding.
	 *
	 * @param float $age Age to catch up to; finite and non-negative.
	 * @return void Sets both the age and the previous age, so the step shows no motion.
	 */
	public function synchronize(float $age): void
	{
		if($age < 0 || !is_finite($age))
		throw new \InvalidArgumentException('Lifetime age must be finite and non-negative');

		$this->age = min($this->duration, max($this->age, $age));
		$this->previousAge = $this->age;
	}
}
