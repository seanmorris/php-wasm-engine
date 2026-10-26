<?php
declare(strict_types=1);
namespace Phonics\Network;

use Phonics\Ecs\Registry;

/** Whitelist of component codecs and prefab factories used by a match profile. */
final class ReplicationRegistry
{
	/** @var array<string,ComponentCodec> */
	private array $schemas = [];
	/** @var array<class-string,string> */
	private array $types = [];
	/** @var array<string,array{spawn:callable,despawn:?callable}> */
	private array $prefabs = [];

	/**
	 * Register one component codec in the replication whitelist.
	 *
	 * @param ComponentCodec $codec Explicit serializer to register for one component type.
	 * @return void No value is returned.
	 */
	public function register(ComponentCodec $codec): void
	{
		$schema = $codec->schema();
		$type = Registry::canonical($codec->componentType());

		if(isset($this->schemas[$schema])) throw new \LogicException("Conflicting replication schema $schema");

		if(isset($this->types[$type])) throw new \LogicException("Component $type already has a replication codec");

		$this->schemas[$schema] = $codec;
		$this->types[$type] = $schema;
	}

	/**
	 * Register a game-owned spawn and optional despawn factory.
	 *
	 * @param string $id Stable identifier for an authority, event, prefab, or network entity.
	 * @param callable(Registry,array<string,mixed>):int $spawn Game factory that creates a live ECS entity.
	 * @param (callable(Registry,int):void)|null $despawn Optional game callback that removes a replicated entity.
	 * @return void No value is returned.
	 */
	public function prefab(string $id, callable $spawn, ?callable $despawn = null): void
	{
		if(!preg_match('/^[A-Za-z][A-Za-z0-9_.:\/-]{0,127}$/', $id))
		throw new \InvalidArgumentException("Invalid network prefab $id");

		if(isset($this->prefabs[$id])) throw new \LogicException("Conflicting network prefab $id");

		$this->prefabs[$id] = compact('spawn', 'despawn');
	}

	/**
	 * Resolve a registered component codec by schema ID.
	 *
	 * @param string $schema Stable schema identifier or payload schema version.
	 * @return ComponentCodec The registered codec for the requested schema.
	 */
	public function codec(string $schema): ComponentCodec
	{
		return $this->schemas[$schema] ?? throw new \OutOfBoundsException("Unknown replication schema $schema");
	}

	/**
	 * Encode registered components attached to one ECS entity.
	 *
	 * @param Registry $registry ECS registry whose replicated state is read or changed.
	 * @param int $entity Local ECS entity ID to capture, update, or remove.
	 * @return array<string,array{version:int,payload:array<string,mixed>}> Encoded components or an authoritative world snapshot.
	 */
	public function capture(Registry $registry, int $entity): array
	{
		$result = [];

		foreach($this->schemas as $schema => $codec)
		{
			$type = $codec->componentType();

			if(!$registry->has($entity, $type)) continue;

			$result[$schema] = [
				'version' => $codec->version()
				, 'payload' => $codec->encode($registry->get($entity, $type))
			];
		}

		ksort($result);

		return $result;
	}

	/**
	 * Decode and apply component records to one ECS entity.
	 *
	 * @param Registry $registry ECS registry whose replicated state is read or changed.
	 * @param int $entity Local ECS entity ID to capture, update, or remove.
	 * @param array<string,array{version:int,payload:array<string,mixed>}> $components Validated components used by this replication registry.
	 * @return void No value is returned.
	 */
	public function apply(Registry $registry, int $entity, array $components): void
	{
		foreach($components as $schema => $record)
		{
			$codec = $this->codec($schema);

			if(($record['version'] ?? null) !== $codec->version() || !is_array($record['payload'] ?? null))
			throw new \UnexpectedValueException("Incompatible payload for replication schema $schema");

			$type = $codec->componentType();
			$current = $registry->has($entity, $type) ? $registry->get($entity, $type) : null;
			$registry->add($entity, $codec->decode($record['payload'], $current));
		}
	}

	/**
	 * Create a replicated entity through a registered prefab factory.
	 *
	 * @param string $prefab Registered game prefab identifier.
	 * @param Registry $registry ECS registry whose replicated state is read or changed.
	 * @param array<string,mixed> $seed Validated spawn fields passed to the prefab factory.
	 * @return int The live local ECS entity created by the prefab.
	 */
	public function spawn(string $prefab, Registry $registry, array $seed = []): int
	{
		$factory = $this->prefabs[$prefab] ?? throw new \OutOfBoundsException("Unknown network prefab $prefab");
		$entity = ($factory['spawn'])($registry, $seed);

		if(!is_int($entity) || !$registry->alive($entity))
		throw new \UnexpectedValueException("Network prefab $prefab did not return a live entity");

		return $entity;
	}

	/**
	 * Remove a replicated entity through its registered prefab factory.
	 *
	 * @param string $prefab Registered game prefab identifier.
	 * @param Registry $registry ECS registry whose replicated state is read or changed.
	 * @param int $entity Local ECS entity ID to capture, update, or remove.
	 * @return void No value is returned.
	 */
	public function despawn(string $prefab, Registry $registry, int $entity): void
	{
		$factory = $this->prefabs[$prefab] ?? throw new \OutOfBoundsException("Unknown network prefab $prefab");

		if($factory['despawn']) ($factory['despawn'])($registry, $entity);
		elseif($registry->alive($entity)) $registry->destroy($entity);
	}

	/**
	 * Describe every registered component schema and policy.
	 *
	 * @return array<string,array{version:int,policy:ReplicationPolicy}> The stable schema ID or the ordered registered schema catalog.
	 */
	public function schema(): array
	{
		$result = [];

		foreach($this->schemas as $id => $codec)
		$result[$id] = ['version' => $codec->version(), 'policy' => $codec->policy()];

		ksort($result);

		return $result;
	}
}
