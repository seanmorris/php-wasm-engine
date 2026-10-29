<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Host-allocated stable entity identity for one match. */
final class NetworkEntityId extends UnsignedValue
{

	/**
	 * Create and validate a network entity ID.
	 *
	 * @param int $value Scalar value or serialized fields to validate.
	 * @return void Stores the ID; zero is reserved and rejected.
	 */
	public function __construct(int $value)
	{
		if($value === 0) throw new \OutOfRangeException('Network entity ID zero is reserved');

		parent::__construct($value);
	}
}
