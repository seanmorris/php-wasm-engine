<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use Phonics\Ecs\Registry;
use Phonics\Network\AuthorityOwner;
use Phonics\Network\BrowserBridge;
use Phonics\Network\CallbackComponentCodec;
use Phonics\Network\CommandSequence;
use Phonics\Network\CommandValidator;
use Phonics\Network\Component\NetworkAuthority;
use Phonics\Network\Component\NetworkIdentity;
use Phonics\Network\Component\NetworkPrefab;
use Phonics\Network\InputCommand;
use Phonics\Network\MatchContext;
use Phonics\Network\NetworkClock;
use Phonics\Network\NetworkEntityAllocator;
use Phonics\Network\NetworkEntityId;
use Phonics\Network\Packet;
use Phonics\Network\PeerId;
use Phonics\Network\PlayerSlot;
use Phonics\Network\Protocol;
use Phonics\Network\ReplicationCallbacks;
use Phonics\Network\ReplicationPolicy;
use Phonics\Network\ReplicationRegistry;
use Phonics\Network\ReplicationScope;
use Phonics\Network\ReplicationSession;
use Phonics\Network\SimulationTick;

$checks = 0;

function verifyNetworkTiming(bool $value, string $message): void
{
	global $checks;
	$checks++;

	if(!$value) throw new RuntimeException($message);
}

final class NetworkTimingPosition
{

	public function __construct(public float $x) {}
}

/** Direct, deterministic stand-in for the RTCDataChannel left after MB2's offer/answer exchange. */
final class DirectPeerWire
{
	private Protocol $protocol;
	private int $time = 0;
	/** @var array<string,int> */
	private array $sequences = ['host' => 0,'guest' => 0];
	/** @var array<string,list<array{at:int,channel:string,data:string}>> */
	private array $queues = ['host' => [],'guest' => []];

	public function __construct(private int $latencyTicks = 1)
	{
		if($latencyTicks < 0) throw new InvalidArgumentException('Direct peer latency cannot be negative');

		$this->protocol = new Protocol;
	}

	public function bridge(string $side): BrowserBridge
	{
		if(!isset($this->queues[$side])) throw new InvalidArgumentException("Unknown direct peer $side");

		return new BrowserBridge(
			function(int $limit) use ($side): string {
				$ready = [];
				$waiting = [];

				foreach($this->queues[$side] as $record)
				{
					if($record['at'] <= $this->time && count($ready) < $limit) $ready[] = $record;
					else $waiting[] = $record;
				}

				$this->queues[$side] = $waiting;

				return json_encode(array_map(
					static fn(array $record): array => [
						'channel' => $record['channel']
						, 'data' => $record['data']
					]
					, $ready
				), JSON_THROW_ON_ERROR
				);
			}
			, function(string $channel, int $family, int $tick, int $schema, string $json) use ($side): bool {
				$target = $side === 'host' ? 'guest' : 'host';

				$packet = new Packet(
					$channel
					, $family
					, 0
					, 1
					, $side === 'host' ? 1 : 2
					, ++$this->sequences[$side]
					, new SimulationTick($tick)
					, 0
					, 0
					, $schema
					, json_decode($json, true, 128, JSON_THROW_ON_ERROR)
				);

				$this->queues[$target][] = [
					'at' => $this->time + $this->latencyTicks
					, 'channel' => $channel
					, 'data' => base64_encode($this->protocol->encode($packet))
				];

				return true;
			}
			, $this->protocol
		);
	}

	public function advance(int $ticks = 1): void { $this->time += $ticks; }
}

/** @return array{Registry,NetworkEntityAllocator,array<int,int>} */
function networkTimingWorld(
	ReplicationScope $scope
	, PeerId $hostPeer
	, PeerId $guestPeer
	, float $hostPosition
	, float $guestPosition
): array {
	$registry = new Registry;
	$allocator = new NetworkEntityAllocator;
	$entities = [];

	foreach([1 => [$hostPeer,$hostPosition],2 => [$guestPeer,$guestPosition]] as $id => [$peer,$position])
	{
		$entity = $registry->create(new NetworkTimingPosition($position));
		$networkId = new NetworkEntityId($id);
		$registry->add($entity, new NetworkIdentity($networkId, $scope));
		$registry->add($entity, new NetworkPrefab('fixture.actor'));
		$registry->add($entity, new NetworkAuthority(AuthorityOwner::peer($peer)));
		$allocator->bind($networkId, $entity);
		$entities[$id] = $entity;
	}

	return [$registry,$allocator,$entities];
}

$scope = new ReplicationScope('E1M1');
$hostPeer = new PeerId('host');
$guestPeer = new PeerId('guest');
$hostSlot = new PlayerSlot(1);
$guestSlot = new PlayerSlot(2);
$digest = 'sha256-' . base64_encode(hash('sha256', 'direct-peer-timing', true));
$descriptor = ['settings' => ['mode' => 'deathmatch']];
$hostMatch = new MatchContext('host', $hostSlot, $guestSlot, $hostPeer, $guestPeer, $digest, $descriptor);
$guestMatch = new MatchContext('guest', $guestSlot, $hostSlot, $guestPeer, $hostPeer, $digest, $descriptor);
$replication = new ReplicationRegistry;

$replication->register(
	new CallbackComponentCodec(
		'fixture.position'
		, 1
		, NetworkTimingPosition::class
		, static fn(NetworkTimingPosition $value): array => ['x' => $value->x]
		, static fn(array $value): NetworkTimingPosition => new NetworkTimingPosition((float)$value['x'])
		, new ReplicationPolicy(ReplicationPolicy::AUTHORITY_OWNER)
	)
);

$replication->prefab('fixture.actor', static fn(Registry $registry): int => $registry->create());

[$hostWorld,$hostEntities,$hostIds] = networkTimingWorld($scope, $hostPeer, $guestPeer, 10, 20);
[$guestWorld,$guestEntities,$guestIds] = networkTimingWorld($scope, $hostPeer, $guestPeer, -10, 100);
$wire = new DirectPeerWire(1);
$hostClock = new NetworkClock;
$guestClock = new NetworkClock(new SimulationTick(1482));
$hostApplied = [];
$guestApplied = [];

$hostSession = new ReplicationSession(
	$hostWorld
	, $replication
	, $hostEntities
	, $wire->bridge('host')
	, $hostMatch
	, new ReplicationCallbacks(
		static fn(PlayerSlot $slot, SimulationTick $tick): array => ['sampledAt' => $tick->value()]
		, static function(InputCommand $command, bool $replay = false) use (&$hostApplied): void {
			$hostApplied[] = [$command->player->value(),$command->tick->value(),$replay];
		}
	)
	, 1
	, 70
	, null
	, $hostClock
	, $scope
);

$guestSession = new ReplicationSession(
	$guestWorld
	, $replication
	, $guestEntities
	, $wire->bridge('guest')
	, $guestMatch
	, new ReplicationCallbacks(
		static fn(PlayerSlot $slot, SimulationTick $tick): array => ['sampledAt' => $tick->value()]
		, static function(InputCommand $command, bool $replay = false) use (&$guestApplied): void {
			$guestApplied[] = [$command->player->value(),$command->tick->value(),$replay];
		}
	)
	, 1
	, 70
	, null
	, $guestClock
	, $scope
);

// Reproduce the report: the guest emits tick 1482 while the authoritative host is still at tick zero.
$guestSession->beforeStep($guestWorld, 1 / 35);
$guestSession->afterStep($guestWorld, 1 / 35);
$wire->advance();
$hostSession->beforeStep($hostWorld, 1 / 35);
$hostSession->afterStep($hostWorld, 1 / 35);

verifyNetworkTiming(
	count(array_filter($hostApplied, static fn(array $value): bool => $value[0] === 2)) === 0
	, 'an out-of-window peer command is dropped without terminating the host loop'
);

// The next authoritative snapshot rebases only the guest's network labels; engine time is untouched.
$wire->advance();
$guestSession->beforeStep($guestWorld, 1 / 35);

verifyNetworkTiming($guestClock->current()->value() === 1, 'guest clock rebases to the tick after the host snapshot');

verifyNetworkTiming(
	$guestClock->corrections() === 1 && $guestClock->lastCorrection() === -1482
	, 'guest clock records the startup skew observed over the direct peer link'
);

verifyNetworkTiming(
	$guestWorld->get($guestIds[1], NetworkTimingPosition::class)->x === 10.0
	, 'guest applies host-owned remote motion from the authoritative snapshot'
);

verifyNetworkTiming(
	$guestWorld->get($guestIds[2], NetworkTimingPosition::class)->x === 100.0
	, 'guest preserves its locally predicted owner-authoritative motion'
);

$guestSession->afterStep($guestWorld, 1 / 35);
$wire->advance();
$hostSession->beforeStep($hostWorld, 1 / 35);

$remoteCommands = array_values(array_filter($hostApplied, static fn(array $value): bool => $value[0] === 2));

verifyNetworkTiming(
	$remoteCommands === [[2,1,false]]
	, 'host accepts the first rebased peer command while redundant stale input remains harmless'
);

// An acknowledgement can arrive after a newer local command was sampled. Re-applying that
// history entry here would replace the live device state without replaying the simulation.
$hostSession->afterStep($hostWorld, 1 / 35);
$guestSession->beforeStep($guestWorld, 1 / 35);
$guestSession->afterStep($guestWorld, 1 / 35);
$wire->advance();
$guestSession->beforeStep($guestWorld, 1 / 35);

verifyNetworkTiming(
	count(array_filter($guestApplied, static fn(array $value): bool => $value[2])) === 0
	, 'snapshot acknowledgement does not overwrite current input with stale command history'
);

$futureValidator = new CommandValidator;
$futureValidator->own($guestSlot, $guestPeer);

verifyNetworkTiming(
	$futureValidator->validate(
		$guestPeer
		, new InputCommand($guestSlot, new SimulationTick(8), new CommandSequence(1), [])
		, new SimulationTick(0)
	)
	, 'host command window accepts the same bounded lead tolerated by the guest clock'
);

$tickBeforeTransition = $hostClock->current()->value();
$nextScope = new ReplicationScope('E1M2');
[$nextWorld,$nextEntities] = networkTimingWorld($nextScope, $hostPeer, $guestPeer, 0, 0);
$nextApplied = [];

$nextSession = new ReplicationSession(
	$nextWorld
	, $replication
	, $nextEntities
	, $wire->bridge('host')
	, $hostMatch
	, new ReplicationCallbacks(
		static fn(PlayerSlot $slot, SimulationTick $tick): array => []
		, static function(InputCommand $command, bool $replay = false) use (&$nextApplied): void {
			$nextApplied[] = [$command->player->value(),$command->tick->value(),$replay];
		}
	)
	, 1
	, 70
	, null
	, $hostClock
	, $nextScope
);

verifyNetworkTiming(
	$nextSession->tick()->value() === $tickBeforeTransition
	, 'a recreated map session keeps the match clock instead of returning to tick zero'
);

$guestSession->beforeStep($guestWorld, 1 / 35);
$wire->advance();
$nextSession->beforeStep($nextWorld, 1 / 35);

verifyNetworkTiming(
	count(array_filter($nextApplied, static fn(array $value): bool => $value[0] === 2)) === 0
	, 'a delayed packet from the previous map scope cannot mutate the new scope'
);

// Keep the guest several ticks ahead, within the clock's normal transit tolerance. The host
// must retain those commands until their ticks become due instead of leaving player two at spawn.
$leadScope = new ReplicationScope('E1M3');
[$leadHostWorld,$leadHostEntities,$leadHostIds] = networkTimingWorld($leadScope, $hostPeer, $guestPeer, 0, 0);
[$leadGuestWorld,$leadGuestEntities,$leadGuestIds] = networkTimingWorld($leadScope, $hostPeer, $guestPeer, 0, 0);
$leadWire = new DirectPeerWire(1);
$leadHostClock = new NetworkClock;
$leadGuestClock = new NetworkClock(new SimulationTick(6));
$leadHostForward = 0.0;
$leadGuestForward = 0.0;
$leadGuestTrace = [];

$leadHostSession = new ReplicationSession(
	$leadHostWorld
	, $replication
	, $leadHostEntities
	, $leadWire->bridge('host')
	, $hostMatch
	, new ReplicationCallbacks(
		static fn(PlayerSlot $slot, SimulationTick $tick): array => ['forward' => 0]
		, static function(InputCommand $command, bool $replay = false) use (&$leadHostForward, $guestSlot): void {
			if($command->player->equals($guestSlot)) $leadHostForward = (float)($command->actions()['forward'] ?? 0);
		}
	)
	, 1
	, 70
	, null
	, $leadHostClock
	, $leadScope
);

$leadGuestSession = new ReplicationSession(
	$leadGuestWorld
	, $replication
	, $leadGuestEntities
	, $leadWire->bridge('guest')
	, $guestMatch
	, new ReplicationCallbacks(
		static fn(PlayerSlot $slot, SimulationTick $tick): array => ['forward' => 1]
		, static function(InputCommand $command, bool $replay = false) use (&$leadGuestForward, $guestSlot): void {
			if($command->player->equals($guestSlot)) $leadGuestForward = (float)($command->actions()['forward'] ?? 0);
		}
	)
	, 1
	, 70
	, null
	, $leadGuestClock
	, $leadScope
);

for($step = 0; $step < 10; $step++)
{
	$leadHostSession->beforeStep($leadHostWorld, 1 / 35);
	$leadGuestSession->beforeStep($leadGuestWorld, 1 / 35);
	$leadHostWorld->get($leadHostIds[2], NetworkTimingPosition::class)->x += $leadHostForward;
	$leadGuestWorld->get($leadGuestIds[2], NetworkTimingPosition::class)->x += $leadGuestForward;
	$leadGuestTrace[] = $leadGuestWorld->get($leadGuestIds[2], NetworkTimingPosition::class)->x;
	$leadHostSession->afterStep($leadHostWorld, 1 / 35);
	$leadGuestSession->afterStep($leadGuestWorld, 1 / 35);
	$leadWire->advance();
}

verifyNetworkTiming(
	$leadHostWorld->get($leadHostIds[2], NetworkTimingPosition::class)->x > 0
	, 'host queues an in-window future command and moves the remote player instead of holding its spawn pose'
);

verifyNetworkTiming(
	$leadGuestTrace === array_map('floatval', range(1, 10))
	, 'authoritative snapshots never pull owner-predicted local movement back to spawn'
);

verifyNetworkTiming(
	$leadGuestClock->corrections() === 0
	, 'normal direct-peer tick lead remains stable without repeated clock rebases'
);

echo "$checks direct peer timing checks passed\n";
