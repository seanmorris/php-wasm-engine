<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Explicit, game-registered component serializer; arbitrary object reflection is forbidden. */
interface ComponentCodec
{

	/**
	 * Read the registered schema identifier or schema catalog.
	 *
	 * @return string The stable schema ID or the ordered registered schema catalog.
	 */
	public function schema(): string;

	/**
	 * Read the component codec version.
	 *
	 * @return int The positive component codec version.
	 */
	public function version(): int;

	/**
	 * Read the PHP component class handled by this codec.
	 *
	 * @return class-string The handled component class name.
	 */
	public function componentType(): string;

	/**
	 * Read the delivery and authority policy for this codec.
	 *
	 * @return ReplicationPolicy The validated replication policy.
	 */
	public function policy(): ReplicationPolicy;

	/**
	 * Encode a whitelisted component into scalar transport fields.
	 *
	 * @param object $component Component instance to encode.
	 * @return array<string,mixed> JSON-safe component fields.
	 */
	public function encode(object $component): array;

	/**
	 * Decode transport fields into a validated component instance.
	 *
	 * @param array<string,mixed> $payload Validated scalar data carried by the message or component.
	 * @param ?object $current Existing component instance to update when one is present.
	 * @return object The validated decoded component.
	 */
	public function decode(array $payload, ?object $current = null): object;
}
