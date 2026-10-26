<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Base for bounded unsigned protocol counters represented safely by PHP integers. */
abstract class UnsignedValue implements \JsonSerializable
{

	/**
	 * Create and validate an unsigned protocol value.
	 *
	 * @param int $value Scalar value or serialized fields to validate.
	 * @param ?int $maximum Largest unsigned value accepted by this value object, or the runtime's
	 * representable uint32 maximum when omitted.
	 */
	public function __construct(private int $value, ?int $maximum = null)
	{
		$maximum ??= self::representableMaximum();

		if($value < 0 || $value > $maximum)
		throw new \OutOfRangeException(static::class . " must be between 0 and $maximum");
	}

	/**
	 * Return the uint32 ceiling that this PHP runtime can store without narrowing.
	 *
	 * @return int Full uint32 maximum on 64-bit PHP, or the positive signed ceiling on wasm32.
	 */
	protected static function representableMaximum(): int
	{
		return PHP_INT_SIZE >= 8 ? 0xffffffff : PHP_INT_MAX;
	}

	/**
	 * Read the validated scalar value.
	 *
	 * @return int The validated scalar value.
	 */
	public function value(): int { return $this->value; }

	/**
	 * Compare this unsigned protocol value with another value of the same kind.
	 *
	 * @param self $other Validated other used by this unsigned protocol value.
	 * @return bool True when both values represent the same identity.
	 */
	public function equals(self $other): bool
	{
		return get_class($this) === get_class($other) && $this->value === $other->value;
	}

	/**
	 * Serialize the unsigned protocol value into transport-safe fields.
	 *
	 * @return int Scalar fields suitable for JSON transport.
	 */
	public function jsonSerialize(): int { return $this->value; }

	/**
	 * Render the unsigned protocol value as its canonical string.
	 *
	 * @return string The canonical scalar string.
	 */
	public function __toString(): string { return (string)$this->value; }
}
