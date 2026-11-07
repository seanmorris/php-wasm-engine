<?php
declare(strict_types=1);
namespace Phonics\Network;

/**
 * Match-lifetime network tick independent of engine time and fixed-step accumulation.
 *
 * The clock advances exactly once per network simulation step and can accept bounded authoritative
 * correction without coupling to render-frame time.
 */
final class NetworkClock
{
	/** Current authoritative fixed-step simulation tick. */
	private SimulationTick $current;
	/** Pending bounded authoritative tick corrections. */
	private int $corrections = 0;
	/** Most recent correction value, or null before correction. */
	private ?int $lastCorrection = null;

	/**
	 * Create a match clock at an optional initial tick.
	 *
	 * @param ?SimulationTick $initial Initial network tick, normally zero for a new match.
	 * @param int $maximumLead Largest local lead over a received host snapshot tolerated as transit time.
	 * @return void Creates a match-lifetime network clock.
	 */
	public function __construct(
		?SimulationTick $initial = null
		, /** Largest local lead over a received host snapshot tolerated as transit time. */
		public int $maximumLead = 8
	){
		if($maximumLead < 0) throw new \InvalidArgumentException('Network clock lead tolerance cannot be negative');

		$this->current = $initial ?? new SimulationTick(0);
	}

	/**
	 * Read the current match tick.
	 *
	 * @return SimulationTick Current network tick.
	 */
	public function current(): SimulationTick { return $this->current; }

	/**
	 * Advance the match clock by one wrapping tick.
	 *
	 * @return SimulationTick Advanced network tick.
	 */
	public function advance(): SimulationTick
	{
		$this->current = $this->current->next();

		return $this->current;
	}

	/**
	 * Install the paused authoritative tick after a deliberate connection recovery.
	 *
	 * @param SimulationTick $tick Tick captured in the recovery snapshot.
	 * @return void Updates the clock without advancing simulation.
	 */
	public function rebase(SimulationTick $tick): void { $this->current = $tick; }

	/**
	 * Rebase a guest clock when an authoritative snapshot proves it is outside the useful window.
	 *
	 * The snapshot represents completed host tick N, so the receiving simulation continues at N + 1.
	 * A small local lead is expected while the snapshot is in transit and is left untouched.
	 *
	 * @param SimulationTick $hostTick Tick represented by the newest accepted host snapshot.
	 * @return bool True when the local network tick was corrected.
	 */
	public function synchronize(SimulationTick $hostTick): bool
	{
		$target = $hostTick->next();
		$distance = $target->distanceFrom($this->current);

		if($distance <= 0 && $distance >= -$this->maximumLead) return false;

		$this->current = $target;
		$this->corrections++;
		$this->lastCorrection = $distance;

		return true;
	}

	/**
	 * Read how many authoritative clock corrections occurred.
	 *
	 * @return int Number of guest clock corrections.
	 */
	public function corrections(): int { return $this->corrections; }

	/**
	 * Read the signed size of the newest correction.
	 *
	 * @return ?int Positive for a jump forward, negative for a jump backward, or null before correction.
	 */
	public function lastCorrection(): ?int { return $this->lastCorrection; }
}
