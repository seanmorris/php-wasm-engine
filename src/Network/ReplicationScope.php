<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Stable lifetime boundary for replicated entities, normally session or source:scope. */
final class ReplicationScope implements \JsonSerializable
{
	private string $value;

	/**
	 * Create and validate a replication scope.
	 *
	 * @param string $value Scalar value or serialized fields to validate.
	 * @return void Stores the scope after validating its spelling.
	 */
	public function __construct(string $value)
	{
		if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,254}$/', $value))
		throw new \InvalidArgumentException("Invalid replication scope $value");

		$this->value = $value;
	}

	/**
	 * Read the validated scalar value.
	 *
	 * @return string The validated scalar value.
	 */
	public function value(): string { return $this->value; }

	/**
	 * Compare this replication scope with another value of the same kind.
	 *
	 * @param self $other Validated other used by this replication scope.
	 * @return bool True when both values represent the same identity.
	 */
	public function equals(self $other): bool { return $this->value === $other->value; }

	/**
	 * Serialize the replication scope into transport-safe fields.
	 *
	 * @return string Scalar fields suitable for JSON transport.
	 */
	public function jsonSerialize(): string { return $this->value; }

	/**
	 * Render the replication scope as its canonical string.
	 *
	 * @return string The canonical scalar string.
	 */
	public function __toString(): string { return $this->value; }
}
