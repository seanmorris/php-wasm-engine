<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Fixed-step simulation position encoded as an unsigned protocol counter. */
final class SimulationTick extends UnsignedValue
{

	/**
	 * Advance to the next wrapping simulation tick.
	 *
	 * @return self The following simulation tick with unsigned wrapping.
	 */
	public function next(): self
	{
		return new self($this->value() === self::representableMaximum() ? 0 : $this->value() + 1);
	}

	/**
	 * Measure signed wrapped distance from another simulation tick.
	 *
	 * @param self $other Validated other used by this simulation tick.
	 * @return int Signed wrapped tick distance.
	 */
	public function distanceFrom(self $other): int
	{
		$maximum = self::representableMaximum();
		$halfRange = intdiv($maximum, 2) + 1;
		$difference = $this->value() - $other->value();

		if($difference >= $halfRange) return ($difference - $maximum) - 1;

		if($difference < -$halfRange) return ($difference + $maximum) + 1;

		return $difference;
	}
}
