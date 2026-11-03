<?php
declare(strict_types=1);
namespace Phonics\Network;

/**
 * The host's latest result for an entity this peer predicts, with the local commands it has not simulated yet.
 *
 * A guest keeps the components it owns, so a snapshot never overwrites them. A correction hands the game those
 * authoritative values together with every retained command the host had not applied when it built the snapshot.
 * The game rewinds its entity to the components and replays the commands, as Zandronum's client prediction does.
 */
final class OwnerCorrection
{
	/**
	 * Create a correction for one predicted entity.
	 *
	 * @param int $entity Local entity the correction applies to.
	 * @param SnapshotSequence $snapshot Snapshot the authoritative values came from.
	 * @param SimulationTick $tick Host tick at which the snapshot was captured.
	 * @param CommandSequence $acknowledged Newest local command the host had applied in that snapshot.
	 * @param array<class-string,object> $components Fresh owner-authoritative components decoded from the snapshot,
	 * keyed by component class. They are detached from the registry.
	 * @param list<InputCommand> $commands Retained local commands after the acknowledgement, oldest first.
	 * @return void Creates an immutable correction value.
	 */
	public function __construct(
		/** Local entity the correction applies to. */
		public readonly int $entity
		, /** Snapshot the authoritative values came from. */
		public readonly SnapshotSequence $snapshot
		, /** Host tick at which the snapshot was captured. */
		public readonly SimulationTick $tick
		, /** Newest local command the host had applied in that snapshot. */
		public readonly CommandSequence $acknowledged
		, /**
		 * Fresh owner-authoritative components decoded from the snapshot, keyed by component class.
		 *
		 * @var array<class-string,object>
		 */
		public readonly array $components
		, /**
		 * Retained local commands after the acknowledgement, oldest first.
		 *
		 * @var list<InputCommand>
		 */
		public readonly array $commands
	){
	}

	/**
	 * Read one authoritative component by class.
	 *
	 * @template T of object
	 * @param class-string<T> $type Component class.
	 * @return ?T The detached component, or null when the snapshot carried none.
	 */
	public function component(string $type): ?object
	{
		$value = $this->components[$type] ?? null;

		return $value instanceof $type ? $value : null;
	}
}
