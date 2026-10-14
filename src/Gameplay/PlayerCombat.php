<?php
declare(strict_types=1);
namespace PhpWasmEngine\Gameplay;

use PhpWasmEngine\Physics\Body;

/** Player attack, mercy, damage, and defeated-badnik totals independent of input devices. */
final class PlayerCombat
{
	public float $mercy = 0;
	public int $hits = 0,$defeats = 0;

	/**
	 * Configure the temporary immunity granted after touching a badnik or its projectile.
	 *
	 * @param float $mercyDuration Nonnegative post-hit immunity in seconds.
	 * @return void Initializes an undamaged player combat state.
	 */
	public function __construct(public float $mercyDuration = 2)
	{
		if(!is_finite($mercyDuration) || $mercyDuration < 0)
		{
			throw new \InvalidArgumentException('Mercy duration must be finite and nonnegative');
		}
	}

	/**
	 * Advance the post-hit immunity clock.
	 *
	 * @param float $dt Nonnegative simulation delta in seconds.
	 * @return void Clamps mercy at zero.
	 */
	public function update(float $dt): void
	{
		if(!is_finite($dt) || $dt < 0)
		{
			throw new \InvalidArgumentException('Combat delta must be finite and nonnegative');
		}

		$this->mercy = max(0, $this->mercy - $dt);
	}

	/**
	 * Determine whether the current movement pose can pop a contacted badnik.
	 *
	 * @param Body $body Player movement body at the contact endpoint.
	 * @return bool True while rolling or airborne outside the post-hit mercy state.
	 */
	public function attacking(Body $body): bool
	{
		return $this->mercy <= 0 && ($body->rolling || !$body->grounded);
	}

	/**
	 * Select player sprite opacity for the reference mercy flicker cadence.
	 *
	 * @return float One before and during the half-second lead-in, then alternating zero and one
	 * every eighth of a second until mercy ends.
	 */
	public function flickerOpacity(): float
	{
		if($this->mercy <= 0)
		{
			return 1;
		}

		$elapsed = $this->mercyDuration - $this->mercy;

		if($elapsed < .5)
		{
			return 1;
		}

		return (int)floor(($elapsed - .5) / .125) % 2 === 0 ? 1 : 0;
	}

	/**
	 * Apply one enemy hit and knock the player away unless mercy is still active.
	 *
	 * @param Body $body Player body whose movement state receives knockback.
	 * @param RingCounter|null $counter Optional ring inventory emptied by the hit.
	 * @param int $direction Horizontal knockback direction, normalized to minus or plus one.
	 * @return bool True when damage was applied; false during mercy.
	 */
	public function damage(Body $body, ?RingCounter $counter, int $direction): bool
	{
		if($this->mercy > 0)
		{
			return false;
		}

		$this->hits++;
		$this->mercy = $this->mercyDuration;

		if($counter !== null)
		{
			$counter->rings = 0;
		}

		$body->grounded = false;
		$body->rolling = false;
		$body->crouching = false;
		$body->ability = '';
		$body->charge = 0;
		$body->crouchTime = 0;
		$body->groundSpeed = 0;
		$body->vx = ($direction < 0 ? -1 : 1) * 240;
		$body->vy = -360;

		return true;
	}
}
