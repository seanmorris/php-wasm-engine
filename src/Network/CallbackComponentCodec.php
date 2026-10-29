<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Component codec backed by game-owned encode/decode callbacks. */
final class CallbackComponentCodec implements ComponentCodec
{
	private string $schema;
	private string $componentType;
	private $encoder;
	private $decoder;

	/**
	 * Create and validate a callback component codec.
	 *
	 * @param string $schema Stable schema identifier or payload schema version.
	 * @param int $version Positive component codec version.
	 * @param class-string $componentType PHP class or interface handled by the codec.
	 * @param callable(object):array<string,mixed> $encoder Game callback that converts a component into scalar fields.
	 * @param callable(array<string,mixed>,?object):object $decoder Game callback that reconstructs or updates
	 * a component.
	 * @param ?ReplicationPolicy $policy Authority, delivery, and interpolation rules for this component.
	 * @return void Stores the codec after validating its schema, version and component type.
	 */
	public function __construct(
		string $schema
		, int $version
		, string $componentType
		, callable $encoder
		, callable $decoder
		, ?ReplicationPolicy $policy = null
	){
		if(!preg_match('/^[A-Za-z][A-Za-z0-9_.:\/-]{0,127}$/', $schema))
		throw new \InvalidArgumentException("Invalid component schema $schema");

		if($version < 1 || $version > 0xffff) throw new \OutOfRangeException('Codec version must fit unsigned 16 bits');

		if(!class_exists($componentType) && !interface_exists($componentType))
		throw new \InvalidArgumentException("Unknown component type $componentType");

		$this->schema = $schema;
		$this->codecVersion = $version;
		$this->componentType = $componentType;
		$this->encoder = $encoder;
		$this->decoder = $decoder;
		$this->replicationPolicy = $policy ?? new ReplicationPolicy;
	}

	private int $codecVersion;
	private ReplicationPolicy $replicationPolicy;

	/**
	 * Read the registered schema identifier or schema catalog.
	 *
	 * @return string The stable schema ID or the ordered registered schema catalog.
	 */
	public function schema(): string { return $this->schema; }

	/**
	 * Read the component codec version.
	 *
	 * @return int The positive component codec version.
	 */
	public function version(): int { return $this->codecVersion; }

	/**
	 * Read the PHP component class handled by this codec.
	 *
	 * @return string The handled component class name.
	 */
	public function componentType(): string { return $this->componentType; }

	/**
	 * Read the delivery and authority policy for this codec.
	 *
	 * @return ReplicationPolicy The validated replication policy.
	 */
	public function policy(): ReplicationPolicy { return $this->replicationPolicy; }

	/**
	 * Encode a whitelisted component into scalar transport fields.
	 *
	 * @param object $component Component instance to encode.
	 * @return array JSON-safe component fields.
	 */
	public function encode(object $component): array
	{
		$type = $this->componentType;

		if(!$component instanceof $type)
		throw new \InvalidArgumentException("Codec {$this->schema} cannot encode " . get_class($component));

		$value = ($this->encoder)($component);
		json_encode($value, JSON_THROW_ON_ERROR);

		return $value;
	}

	/**
	 * Decode transport fields into a validated component instance.
	 *
	 * @param array $payload Validated scalar data carried by the message or component.
	 * @param ?object $current Existing component instance to update when one is present.
	 * @return object The validated decoded component.
	 */
	public function decode(array $payload, ?object $current = null): object
	{
		$type = $this->componentType;

		if($current !== null && !$current instanceof $type)
		throw new \InvalidArgumentException("Codec {$this->schema} received the wrong current component");

		$value = ($this->decoder)($payload, $current);

		if(!$value instanceof $type)
		throw new \UnexpectedValueException("Codec {$this->schema} decoded the wrong component type");

		return $value;
	}
}
