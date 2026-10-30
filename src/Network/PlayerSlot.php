<?php
declare(strict_types=1);
namespace Phonics\Network;

/**
 * One-based player position negotiated as part of a match.
 *
 * The typed value prevents zero-based indexes and arbitrary integers from leaking into negotiated player
 * ownership.
 */
final class PlayerSlot extends UnsignedValue
{

	/**
	 * Create and validate a player slot.
	 *
	 * @param int $value Zero-based player slot carried by commands and match assignments.
	 * @return void Stores the slot; rejects values outside 1 through 16.
	 */
	public function __construct(int $value)
	{
		if($value < 1 || $value > 16) throw new \OutOfRangeException('Player slots range from 1 through 16');

		parent::__construct($value, 16);
	}
}
