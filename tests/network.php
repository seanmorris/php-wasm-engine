<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use Phonics\Ecs\Registry;
use Phonics\Network\BrowserBridge;
use Phonics\Network\CallbackComponentCodec;
use Phonics\Network\CommandSequence;
use Phonics\Network\CommandValidator;
use Phonics\Network\Component\NetworkIdentity;
use Phonics\Network\Component\NetworkPrefab;
use Phonics\Network\EventLedger;
use Phonics\Network\InputCommand;
use Phonics\Network\InputHistory;
use Phonics\Network\MessageFamily;
use Phonics\Network\NetworkEntityAllocator;
use Phonics\Network\Packet;
use Phonics\Network\PeerId;
use Phonics\Network\PlayerSlot;
use Phonics\Network\Protocol;
use Phonics\Network\ReliableEvent;
use Phonics\Network\ReplicationRegistry;
use Phonics\Network\ReplicationScope;
use Phonics\Network\SimulationTick;
use Phonics\Network\SnapshotApplier;
use Phonics\Network\SnapshotBuilder;
use Phonics\Network\SnapshotSequence;

$checks = 0;

function verifyNetwork(bool $value, string $message): void
{
	global $checks;
	$checks++;

	if(!$value) throw new RuntimeException($message);
}

final class NetworkFixturePosition
{
	public function __construct(public float $x, public float $y) {}
}

$counterMaximum = PHP_INT_SIZE >= 8 ? 0xffffffff : PHP_INT_MAX;
$lastTick = new SimulationTick($counterMaximum);

verifyNetwork($lastTick->next()->value() === 0, 'simulation ticks wrap within the runtime-safe uint32 range');
verifyNetwork(
	(new SimulationTick(0))->distanceFrom($lastTick) === 1
	, 'simulation tick distance wraps forward across zero'
);
verifyNetwork(
	$lastTick->distanceFrom(new SimulationTick(0)) === -1
	, 'simulation tick distance wraps backward across zero'
);

$replication = new ReplicationRegistry;
$replication->register(new CallbackComponentCodec(
	'fixture.position'
	, 1
	, NetworkFixturePosition::class
	, static fn(NetworkFixturePosition $value): array => ['x' => $value->x,'y' => $value->y]
	, static fn(array $value): NetworkFixturePosition => new NetworkFixturePosition(
		(float)$value['x']
		, (float)$value['y']
	)
));

$replication->prefab(
	'fixture.actor'
	, static fn(Registry $registry): int => $registry->create()
);

$host = new Registry;
$hostIds = new NetworkEntityAllocator;
$hostEntity = $host->create(new NetworkFixturePosition(10, 20));
$networkId = $hostIds->allocate($hostEntity);
$host->add($hostEntity, new NetworkIdentity($networkId, new ReplicationScope('map:E1M1')));
$host->add($hostEntity, new NetworkPrefab('fixture.actor'));
$builder = new SnapshotBuilder($replication, 2);
$full = $builder->capture($host, new SnapshotSequence(10), new SimulationTick(100));

$guest = new Registry;
$guestIds = new NetworkEntityAllocator;
$applier = new SnapshotApplier($replication, $guestIds);
$applier->apply($guest, $full);
$guestEntity = $guestIds->local($networkId);

verifyNetwork($guestEntity !== null && $guest->alive($guestEntity), 'full snapshot spawns a bound entity');
verifyNetwork(
	$guest->get($guestEntity, NetworkFixturePosition::class)->x === 10.0
	, 'registered component codec applies a full snapshot'
);

$host->add($hostEntity, new NetworkFixturePosition(30, 40));
$delta = $builder->capture(
	$host
	, new SnapshotSequence(11)
	, new SimulationTick(101)
	, new SnapshotSequence(10)
);
$applier->apply($guest, $delta);
verifyNetwork(
	$guest->get($guestEntity, NetworkFixturePosition::class)->x === 30.0
	, 'baseline delta updates an existing entity without respawning it'
);

$host->remove($hostEntity, NetworkFixturePosition::class);
$withoutComponent = $builder->capture(
	$host
	, new SnapshotSequence(12)
	, new SimulationTick(102)
	, new SnapshotSequence(11)
);
$applier->apply($guest, $withoutComponent);
verifyNetwork(!$guest->has($guestEntity, NetworkFixturePosition::class), 'delta removes a replicated component');
verifyNetwork(!$builder->has(new SnapshotSequence(10)), 'baseline capacity evicts the original sequence key');
verifyNetwork($builder->has(new SnapshotSequence(11)), 'baseline capacity preserves numeric sequence identities');

$host->add($hostEntity, new NetworkFixturePosition(50, 60));
$resync = $builder->capture($host, new SnapshotSequence(13), new SimulationTick(103));
$applier->apply($guest, $resync);
verifyNetwork(
	$guestIds->local($networkId) === $guestEntity
		&& $guest->get($guestEntity, NetworkFixturePosition::class)->x === 50.0
	, 'full resync reconciles a live binding instead of duplicating it'
);

$host->destroy($hostEntity);
$despawn = $builder->capture(
	$host
	, new SnapshotSequence(14)
	, new SimulationTick(104)
	, new SnapshotSequence(13)
);
$applier->apply($guest, $despawn);
verifyNetwork(!$guest->alive($guestEntity) && $guestIds->retired($networkId), 'despawn retires the network identity');

$peer = new PeerId('peer-two');
$slot = new PlayerSlot(2);
$validator = new CommandValidator;
$validator->own($slot, $peer);
$command = new InputCommand($slot, new SimulationTick(50), new CommandSequence(7), ['forward' => 1.0]);
verifyNetwork($validator->validate($peer, $command, new SimulationTick(50)), 'owned command is accepted');
verifyNetwork(!$validator->validate($peer, $command, new SimulationTick(50)), 'redundant input is ignored idempotently');

$history = new InputHistory(2);
$history->push(new InputCommand($slot, new SimulationTick(50), new CommandSequence(10), ['forward' => 1.0]));
$history->push(new InputCommand($slot, new SimulationTick(51), new CommandSequence(11), ['forward' => 0.5]));
$history->push(new InputCommand($slot, new SimulationTick(52), new CommandSequence(12), ['forward' => 0.0]));
verifyNetwork(
	array_map(static fn(InputCommand $value): int => $value->sequence->value(), $history->redundant()) === [11,12]
	, 'bounded input history preserves command sequence keys'
);

$ledger = new EventLedger(2);
$event = new ReliableEvent('pickup:44', new SimulationTick(52), 'pickup', ['entity' => 44]);
verifyNetwork($ledger->accept($event) && !$ledger->accept($event), 'reliable events apply once');

$protocol = new Protocol;
$wirePacket = new Packet(
	'realtime'
	, MessageFamily::SNAPSHOT
	, 0
	, 3
	, 2
	, 91
	, new SimulationTick(52)
	, 90
	, 3
	, 1
	, ['snapshot' => $resync->jsonSerialize()]
);
$wire = $protocol->encode($wirePacket);
$sent = [];
$bridge = new BrowserBridge(
	static fn(int $limit): string => json_encode([
		['channel' => 'realtime','data' => base64_encode($wire)]
	], JSON_THROW_ON_ERROR)
	, static function(...$arguments) use (&$sent): bool { $sent[] = $arguments; return true; }
	, $protocol
);
$received = $bridge->receive();
verifyNetwork(
	count($received) === 1 && $received[0]->sequence === 91 && $received[0]->tick->value() === 52
	, 'browser bridge decodes the shared binary header'
);
$bridge->send(MessageFamily::INPUT, ['commands' => [$command->jsonSerialize()]], new SimulationTick(53));
verifyNetwork(
	$sent[0][0] === 'realtime' && $sent[0][1] === MessageFamily::INPUT
		&& json_decode($sent[0][4], true)['commands'][0]['sequence'] === 7
	, 'PHP delegates gameplay framing to the browser shared sequence allocator'
);

echo "$checks network checks passed\n";
