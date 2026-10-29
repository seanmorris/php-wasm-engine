<?php
declare(strict_types=1);
namespace Phonics\Network;

use Phonics\Ecs\Registry;
use Phonics\Network\Component\NetworkAuthority;

/** Two-peer host-authoritative command and snapshot coordinator for fixed-step systems. */
final class ReplicationSession
{
	private NetworkClock $clock;
	private int $commandSequence = 0;
	private int $snapshotSequence = 0;
	private InputHistory $history;
	private CommandValidator $validator;
	private SnapshotBuilder $builder;
	private SnapshotApplier $applier;
	private SnapshotBuffer $buffer;
	private EventLedger $events;
	private ?SnapshotSequence $peerBaseline = null;
	private ?SnapshotSequence $latestSnapshot = null;
	private bool $forceFull = true;
	/** @var array<int,InputCommand> */
	private array $pending = [];

	/**
	 * Create and validate a replication session.
	 *
	 * @param Registry $world ECS registry coordinated by this replication session.
	 * @param ReplicationRegistry $replication Whitelist of component codecs and prefab factories.
	 * @param NetworkEntityAllocator $entities Bidirectional network/local entity identity map.
	 * @param BrowserBridge $bridge Browser packet bridge used for gameplay messages.
	 * @param MatchContext $match Negotiated peer identities, slots, and settings.
	 * @param ReplicationCallbacks $callbacks Game-owned input and reliable-event callbacks.
	 * @param int $snapshotInterval Ticks between authoritative snapshot sends.
	 * @param int $fullSnapshotInterval Ticks between forced complete baselines.
	 * @param ?callable $commandRule Validated command rule used by this replication session.
	 * @param ?NetworkClock $clock Match-lifetime clock shared by consecutive replication scopes.
	 * @param ?ReplicationScope $scope Active world scope used to reject delayed traffic from another map.
	 * @return void Creates a replication session for the active world scope.
	 */
	public function __construct(
		private Registry $world
		, private ReplicationRegistry $replication
		, private NetworkEntityAllocator $entities
		, private BrowserBridge $bridge
		, /** Negotiated match context. */
		public MatchContext $match
		, private ReplicationCallbacks $callbacks
		, private int $snapshotInterval = 2
		, private int $fullSnapshotInterval = 70
		, ?callable $commandRule = null
		, ?NetworkClock $clock = null
		, private ?ReplicationScope $scope = null
	){
		if($snapshotInterval < 1 || $fullSnapshotInterval < $snapshotInterval)
		throw new \InvalidArgumentException('Invalid snapshot intervals');

		$this->clock = $clock ?? new NetworkClock;
		$this->history = new InputHistory;
		$this->validator = new CommandValidator(8, $this->clock->maximumLead, 4, $commandRule);
		$this->validator->own($match->remoteSlot, $match->remotePeer);
		$this->builder = new SnapshotBuilder($replication);

		$this->applier = new SnapshotApplier(
			$replication
			, $entities
			, \Closure::fromCallable([$this,'shouldApplyComponent'])
		);

		$this->buffer = new SnapshotBuffer;
		$this->events = new EventLedger;
	}

	/**
	 * Process incoming packets and install commands before gameplay systems run.
	 *
	 * @param Registry $registry ECS registry whose replicated state is read or changed.
	 * @param float $dt Fixed simulation step duration in seconds.
	 * @return void No value is returned.
	 */
	public function beforeStep(Registry $registry, float $dt): void
	{
		if($registry !== $this->world) throw new \LogicException('Replication session received another ECS registry');

		foreach($this->bridge->receive() as $packet) $this->receive($packet);

		if($this->match->host()) $this->applyPending();

		$tick = $this->clock->current();

		$command = new InputCommand(
			$this->match->localSlot
			, $tick
			, new CommandSequence(++$this->commandSequence)
			, $this->callbacks->sample($this->match->localSlot, $tick)
		);

		$this->callbacks->apply($command);

		if(!$this->match->host())
		{
			$this->history->push($command);

			/**
			 * Serialize one retained command for redundant transmission.
			 *
			 * @param InputCommand $value Recent local command.
			 * @return array Scalar command fields.
			 */
			$serializeCommand = static fn(InputCommand $value): array => $value->jsonSerialize();

			$this->bridge->send(MessageFamily::INPUT, $this->outgoing([
				'commands' => array_map($serializeCommand, $this->history->redundant())
				, 'snapshotAck' => $this->latestSnapshot?->value()
			]), $tick
			);
		}
	}

	/**
	 * Publish authoritative state and advance the network tick after gameplay systems run.
	 *
	 * @param Registry $registry ECS registry whose replicated state is read or changed.
	 * @param float $dt Fixed simulation step duration in seconds.
	 * @return void No value is returned.
	 */
	public function afterStep(Registry $registry, float $dt): void
	{
		if($registry !== $this->world) throw new \LogicException('Replication session received another ECS registry');

		$tick = $this->clock->current();

		if($this->match->host() && $tick->value() % $this->snapshotInterval === 0)
		{
			$sequence = new SnapshotSequence(++$this->snapshotSequence);
			$full = $this->forceFull || $tick->value() % $this->fullSnapshotInterval === 0;
			$baseline = !$full && $this->peerBaseline && $this->builder->has($this->peerBaseline)
				? $this->peerBaseline
				: null;

			$latest = $this->validator->latest($this->match->remotePeer, $this->match->remoteSlot);
			$acknowledged = $latest ? [$this->match->remoteSlot->value() => $latest->value()] : [];
			$snapshot = $this->builder->capture($registry, $sequence, $tick, $baseline, $acknowledged);

			$this->bridge->send(
				MessageFamily::SNAPSHOT
				, $this->outgoing(['snapshot' => $snapshot->jsonSerialize()])
				, $tick
			);

			$this->forceFull = false;
		}

		$this->clock->advance();
	}

	/**
	 * Publish one host-owned reliable gameplay event.
	 *
	 * @param ReliableEvent $event Idempotent reliable gameplay event.
	 * @return void No value is returned.
	 */
	public function emit(ReliableEvent $event): void
	{
		if(!$this->match->host()) throw new \LogicException('Only the host may originate gameplay events');

		if($this->events->accept($event)) $this->callbacks->event($event);

		$this->bridge->send(
			MessageFamily::EVENT
			, $this->outgoing(['event' => $event->jsonSerialize()])
			, $event->tick
			, 'control'
		);
	}

	/**
	 * Read the current fixed simulation tick.
	 *
	 * @return SimulationTick The current fixed simulation tick.
	 */
	public function tick(): SimulationTick { return $this->clock->current(); }

	/**
	 * Read the guest snapshot interpolation buffer.
	 *
	 * @return SnapshotBuffer The snapshot buffer used for guest presentation.
	 */
	public function samples(): SnapshotBuffer { return $this->buffer; }

	/**
	 * Discard all retained replication session state.
	 *
	 * @return void No value is returned.
	 */
	public function clear(): void
	{
		$this->history->clear();
		$this->validator->clear();
		$this->builder->clear();
		$this->applier->clear();
		$this->buffer->clear();
		$this->events->clear();
		$this->pending = [];
	}

	/**
	 * Route one gameplay packet for this session's scope to its family handler.
	 *
	 * @param Packet $packet Decoded gameplay packet from the browser bridge.
	 * @return void Ignores packets for other scopes; a host answers resync requests with a full snapshot.
	 */
	private function receive(Packet $packet): void
	{
		if($packet->schema !== 1) throw new \UnexpectedValueException("Unsupported gameplay schema {$packet->schema}");

		if($this->scope && ($packet->payload['scope'] ?? null) !== $this->scope->value()) return;

		switch($packet->family)
		{
			case MessageFamily::INPUT: $this->receiveInput($packet->payload);
				break;
			case MessageFamily::SNAPSHOT: $this->receiveSnapshot($packet->payload);
				break;
			case MessageFamily::EVENT: $this->receiveEvent($packet->payload);
				break;
			case MessageFamily::RESYNC:
				if($this->match->host()) $this->forceFull = true;

				break;
		}
	}

	/**
	 * Accept a guest's redundant input commands on the host.
	 *
	 * @param array<string,mixed> $payload Input packet fields with commands and an optional snapshot acknowledgement.
	 * @return void Records a usable snapshot baseline and queues validated commands for the remote slot.
	 */
	private function receiveInput(array $payload): void
	{
		if(!$this->match->host()) throw new \UnexpectedValueException('Guest received peer input commands');

		if(isset($payload['snapshotAck']))
		{
			$baseline = new SnapshotSequence((int)$payload['snapshotAck']);

			if($this->builder->has($baseline)) $this->peerBaseline = $baseline;
		}

		foreach($payload['commands'] ?? [] as $value)
		{
			if(!is_array($value)) throw new \UnexpectedValueException('Input packet contains an invalid command');

			$command = InputCommand::fromArray($value);

			if(!$command->player->equals($this->match->remoteSlot))
			throw new \UnexpectedValueException('Input command names an unowned player slot');

			if($this->validator->validate($this->match->remotePeer, $command, $this->clock->current()))
			$this->pending[$command->sequence->value()] = $command;
		}
	}

	/**
	 * Apply a newer authoritative snapshot on the guest.
	 *
	 * @param array<string,mixed> $payload Snapshot packet fields.
	 * @return void Synchronizes the clock, applies and buffers the snapshot, and acknowledges local commands;
	 * requests a resync when the delta baseline is unavailable.
	 */
	private function receiveSnapshot(array $payload): void
	{
		if($this->match->host()) throw new \UnexpectedValueException('Host received an authoritative snapshot');

		if(!is_array($payload['snapshot'] ?? null)) throw new \UnexpectedValueException('Snapshot packet is invalid');

		$snapshot = WorldSnapshot::fromArray($payload['snapshot']);

		if($this->latestSnapshot && $snapshot->sequence->value() <= $this->latestSnapshot->value()) return;

		$this->clock->synchronize($snapshot->tick);

		try
		{
			$this->applier->apply($this->world, $snapshot);
		}
		catch(\UnexpectedValueException $error)
		{
			if(!str_contains($error->getMessage(), 'baseline is unavailable')) throw $error;

			$this->bridge->send(MessageFamily::RESYNC, $this->outgoing([
				'latest' => $this->latestSnapshot?->value()
			]), $this->clock->current(), 'control'
			);

			return;
		}

		$this->latestSnapshot = $snapshot->sequence;
		$this->buffer->push($snapshot);
		$acknowledged = $snapshot->acknowledged[$this->match->localSlot->value()] ?? null;

		if($acknowledged !== null)
		{
			$ack = new CommandSequence((int)$acknowledged);
			$this->history->acknowledge($ack);
		}
	}

	/**
	 * Deliver a reliable gameplay event once.
	 *
	 * @param array<string,mixed> $payload Event packet fields.
	 * @return void Passes a first occurrence to the game's event callback.
	 */
	private function receiveEvent(array $payload): void
	{
		if(!is_array($payload['event'] ?? null)) throw new \UnexpectedValueException('Event packet is invalid');

		$event = ReliableEvent::fromArray($payload['event']);

		if($this->events->accept($event)) $this->callbacks->event($event);
	}

	/**
	 * Apply queued remote commands whose tick has been reached, in sequence order.
	 *
	 * @return void Leaves commands for future ticks queued.
	 */
	private function applyPending(): void
	{
		ksort($this->pending, SORT_NUMERIC);

		foreach($this->pending as $sequence => $command)
		{
			if($command->tick->distanceFrom($this->clock->current()) > 0) continue;

			$this->callbacks->apply($command);
			unset($this->pending[$sequence]);
		}
	}

	/**
	 * Tag an outgoing payload with this session's replication scope.
	 *
	 * @param array<string,mixed> $payload Message fields to send.
	 * @return array<string,mixed> Payload prefixed with the scope when the session has one.
	 */
	private function outgoing(array $payload): array
	{
		return $this->scope ? ['scope' => $this->scope->value()] + $payload : $payload;
	}

	/**
	 * Keep locally owned predicted components while applying authoritative state for other components.
	 *
	 * @param Registry $registry ECS registry receiving the snapshot.
	 * @param int $entity Local entity whose component is being reconciled.
	 * @param ComponentCodec $codec Registered component codec and authority policy.
	 * @return bool True when the authoritative component should be installed locally.
	 */
	private function shouldApplyComponent(Registry $registry, int $entity, ComponentCodec $codec): bool
	{
		if($codec->policy()->authority !== ReplicationPolicy::AUTHORITY_OWNER
			|| !$registry->has($entity, NetworkAuthority::class)
		) return true;

		$owner = $registry->get($entity, NetworkAuthority::class)->owner->peerId();

		return !$owner || !$owner->equals($this->match->localPeer);
	}
}
