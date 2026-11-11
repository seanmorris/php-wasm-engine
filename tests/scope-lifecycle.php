<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Phonics\Ecs\Query;
use Phonics\World\
{
	ActiveScope, AdapterContext, ContentAdapter, ContentSource, ScopeContext, ScopeDescriptor, ScopeLifecycle
	, SourceDefinition, SystemSet, WorldRuntime
};
$checks = 0;

function expectScope(bool $value, string $message): void
{
	global $checks;
	$checks++;

	if(!$value) throw new RuntimeException($message);
}

final class LifecycleCounter
{

	public function __construct(public int $value = 0) {} }

final class LifecyclePolicy implements ScopeLifecycle
{
	public array $events = [];
	public bool $failUnload = false;

	public function __construct(public string $behavior = 'snapshot') {}

	public function load(ScopeContext $scope): void { $this->events[] = 'load:' . $scope->descriptor->id; }

	public function activate(ScopeContext $scope): void { $this->events[] = 'activate:' . $scope->descriptor->id; }

	public function deactivate(ScopeContext $scope): string
	{
		$this->events[] = 'deactivate:' . $scope->descriptor->id;

		return $this->behavior;
	}

	public function unload(ScopeContext $scope): void
	{
		$this->events[] = 'unload:' . $scope->descriptor->id;

		if($this->failUnload) throw new RuntimeException('Unload failed');
	}
}

$runtime = new WorldRuntime(new AdapterContext);

$runtime->adapter('fixture', new class implements ContentAdapter {

	public function open(SourceDefinition $definition, AdapterContext $context): ContentSource
	{
		return new class implements ContentSource {

			public function catalog(): iterable
			{
				return [new ScopeDescriptor('world', 'world'), new ScopeDescriptor('map', 'map', 'world')];
			}

			public function activate(string $id, ScopeContext $context): ActiveScope
			{
				return new class($context->descriptor) implements ActiveScope {

					public function __construct(private ScopeDescriptor $scope) {}

					public function descriptor(): ScopeDescriptor { return $this->scope; }

					public function content(): mixed { return null; }

					public function snapshot(): array { return []; }

					public function close(): void {}
				};
			}

			public function close(): void {}
		};
	}
});

$policy = new LifecyclePolicy;
$runtime->lifecycle('game-policy', $policy);
$runtime->source(new SourceDefinition('game', 'fixture', options: ['lifecycle' => 'game-policy']));

$runtime->systemSet('counter', new class implements SystemSet {

	public function activate(ScopeContext $scope, array $options = []): void
	{
		$counter = new LifecycleCounter($scope->state()['counter'] ?? 0);
		$scope->entity($counter);
		$scope->onSnapshot('counter', fn() => $counter->value);
		$scope->system('tick', function() use ($counter): void { $counter->value++; });
	}
});

$runtime->bind('counter', 'counter');
$root = $runtime->activate('game', 'world');
$prepared = $runtime->prepare('game', 'map');
$runtime->scheduler->update(.1);

expectScope(
	$runtime->registry->get($prepared->entities()[0], LifecycleCounter::class)->value === 0
	, 'prepared systems do not run'
);

$lease = $runtime->activate('game', 'map');
$runtime->scheduler->update(.1);
$lease->release();
$again = $runtime->activate('game', 'map');

expectScope(
	$runtime->registry->get($again->context->entities()[0], LifecycleCounter::class)->value === 1
	, 'explicit snapshot captures and restores game state'
);

$policy->behavior = 'reset';
$again->release();
$again = $runtime->activate('game', 'map');

expectScope(
	$runtime->registry->get($again->context->entities()[0], LifecycleCounter::class)->value === 0
	, 'reset discards prior snapshot state'
);

$policy->behavior = 'retain';
$old = $again->context->entities()[0];
$again->release();
$runtime->scheduler->update(.1);
expectScope($runtime->registry->get($old, LifecycleCounter::class)->value === 0, 'retained systems stay suspended');
$again = $runtime->activate('game', 'map');
expectScope($again->context->entities()[0] === $old, 'retain resumes the same entity');
$again->context->transfer($old, $root->context);
$again->release();
$runtime->unloadScope('game', 'map', true);
expectScope($runtime->registry->alive($old), 'explicit ownership transfer survives old scope eviction');
$again = $runtime->activate('game', 'map');
$seen = [];

$runtime->scheduler->add('boundary', function() use ($runtime, &$seen): void {
	$runtime->deactivate('game', 'map');
	$seen[] = $runtime->context('game', 'map')->active();
	$runtime->transition(function() use (&$seen): void { $seen[] = 'committed'; });
});

$runtime->scheduler->update(.1);
expectScope($seen === [true, 'committed'] && !$again->context->active(), 'deactivation commits after simulation');
$runtime->scheduler->remove('boundary');
$policy->failUnload = true;

try
{
	$runtime->unload('game');
} catch(RuntimeException $error)
{
	expectScope($error->getMessage() === 'Unload failed', 'cleanup preserves the first failure');
}

expectScope(
	$runtime->registry->query(Query::all()) === []
	, 'cleanup failure still releases every child and owned entity'
);

expectScope(
	!$runtime->scheduler->has('game:world/tick') && !$runtime->scheduler->has('game:map/tick')
	, 'cleanup failure removes systems'
);

$events = array_slice($policy->events, -3);

expectScope(
	array_search('unload:map', $policy->events) < array_search('unload:world', $policy->events)
	, 'children unload before their parent'
);

$policy->failUnload = false;
$policy->events = [];
$runtime->prepare('game', 'map');
$runtime->prepare('game', 'world');
$runtime->close();

expectScope(
	array_slice($policy->events, -2) === ['unload:map', 'unload:world']
	, 'children prepared first still unload before parents'
);

// A failing deferred callback must not strand later cleanup callbacks.
$registry = new \Phonics\Ecs\Registry;
$scheduler = new \Phonics\Ecs\Scheduler($registry);
$finished = false;

$scheduler->add('queue', function() use ($scheduler, &$finished): void {
	$scheduler->defer(fn() => throw new RuntimeException('deferred failure'));
	$scheduler->defer(function() use (&$finished): void { $finished = true; });
});

try { $scheduler->update(.1); } catch(RuntimeException $error) {}

expectScope($finished && !$scheduler->updating(), 'all deferred cleanup executes after one callback fails');
echo "scope-lifecycle: $checks checks passed\n";
