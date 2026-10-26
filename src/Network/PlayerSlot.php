<?php
declare(strict_types=1);
namespace Phonics\Network;

/** One-based player position negotiated as part of a match. */
final class PlayerSlot extends UnsignedValue
{

	/**
	 * Create and validate a player slot.
	 *
	 * @param int $value Scalar value or serialized fields to validate.
	 */
	public function __construct(int $value)
	{
		if($value < 1 || $value > 16) throw new \OutOfRangeException('Player slots range from 1 through 16');

		parent::__construct($value, 16);
	}
}
