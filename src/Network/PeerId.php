<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Opaque session peer identity kept distinct from provider-specific user IDs. */
final class PeerId implements \JsonSerializable
{
	private string $value;

	/**
	 * Create and validate a peer ID.
	 *
	 * @param string $value Scalar value or serialized fields to validate.
	 * @return void Stores the trimmed ID; rejects empty, overlong or control-character values.
	 */
	public function __construct(string $value)
	{
		$value = trim($value);

		if($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value))
		throw new \InvalidArgumentException('Peer IDs must be printable strings of at most 255 bytes');

		$this->value = $value;
	}

	/**
	 * Read the validated scalar value.
	 *
	 * @return string The validated scalar value.
	 */
	public function value(): string { return $this->value; }

	/**
	 * Compare this peer ID with another value of the same kind.
	 *
	 * @param self $other Validated other used by this peer ID.
	 * @return bool True when both values represent the same identity.
	 */
	public function equals(self $other): bool { return hash_equals($this->value, $other->value); }

	/**
	 * Serialize the peer ID into transport-safe fields.
	 *
	 * @return string Scalar fields suitable for JSON transport.
	 */
	public function jsonSerialize(): string { return $this->value; }

	/**
	 * Render the peer ID as its canonical string.
	 *
	 * @return string The canonical scalar string.
	 */
	public function __toString(): string { return $this->value; }
}
