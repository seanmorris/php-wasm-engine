<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use Phonics\Render\FrameContext;
use Phonics\Render\FramePipeline;
use Phonics\Render\RenderPass;
use Phonics\World\ActiveScope;
use Phonics\World\AdapterContext;
use Phonics\World\ContentAdapter;
use Phonics\World\ContentSource;
use Phonics\World\ScopeContext;
use Phonics\World\ScopeDescriptor;
use Phonics\World\SourceDefinition;
use Phonics\World\SystemSet;
use Phonics\World\WorldRuntime;

$checks = 0;

function verifyWorldRuntime(bool $value, string $message): void
{
	global $checks;
	$checks++;

	if(!$value) throw new RuntimeException($message);
}

final class CoreFixtureActiveScope implements ActiveScope
{
	public bool $closed = false;

	public function __construct(private ScopeDescriptor $scope, private array $state = []) {}

	public function descriptor(): ScopeDescriptor { return $this->scope; }

	public function content() { return ['name' => $this->scope->id]; }

	public function snapshot(): array { return ['visits' => ($this->state['visits'] ?? 0) + 1]; }

	public function close(): void { $this->closed = true; }
}

final class CoreFixtureSource implements ContentSource
{
	public int $activations = 0;
	public array $active = [];
	public bool $closed = false;

	public function catalog(): iterable
	{
		return [
			new ScopeDescriptor('ROOT', 'archive')
			, new ScopeDescriptor('MAP01', 'map', 'ROOT', ['playable'])
		];
	}

	public function activate(string $id, ScopeContext $context): ActiveScope
	{
		$this->activations++;

		return $this->active[] = new CoreFixtureActiveScope($context->descriptor, $context->state());
	}

	public function close(): void { $this->closed = true; }
}

final class CoreFixtureAdapter implements ContentAdapter
{
	public CoreFixtureSource $source;

	public function __construct() { $this->source = new CoreFixtureSource; }

	public function open(SourceDefinition $definition, AdapterContext $context): ContentSource
	{
		return $this->source;
	}
}

final class CoreFixtureSystems implements SystemSet
{
	public array $activated = [];

	public function __construct(private string $name, private array &$order) {}

	public function activate(ScopeContext $scope, array $options = []): void
	{
		$this->order[] = $this->name;
		$this->activated[] = $scope->state();
		$scope->system($this->name, static function(): void {});

		$scope->entity((object) ['owner' => $this->name]);
		$scope->own($scope->registry->create((object) ['adopted' => $this->name]));
	}
}

$adapter = new CoreFixtureAdapter;
$order = [];
$first = new CoreFixtureSystems('first', $order);
$second = new CoreFixtureSystems('second', $order);
$runtime = new WorldRuntime(new AdapterContext);
$runtime->adapter('@wad/archive', $adapter);
$runtime->systemSet('@wad/first', $first);
$runtime->systemSet('@wad/second', $second);
$runtime->source(new SourceDefinition('episode', '@wad/archive', [['url' => 'DOOM1.WAD']]));
$runtime->bind('first', '@wad/first', ['source' => 'episode', 'kind' => 'map']);
$runtime->bind('second', '@wad/second', ['tags' => ['playable']], [], ['first']);

verifyWorldRuntime(count($runtime->catalog('episode')) === 2, 'adapter catalog is format neutral');
$a = $runtime->activate('episode', 'MAP01');
$b = $runtime->activate('episode', 'MAP01');
verifyWorldRuntime($adapter->source->activations === 1, 'active scopes are reference counted');
verifyWorldRuntime($order === ['first','second'], 'system-set bindings respect dependencies');
verifyWorldRuntime($runtime->scheduler->has('episode:MAP01/first'), 'scoped system uses stable namespace');
$a->release();
verifyWorldRuntime($runtime->scheduler->has('episode:MAP01/first'), 'first lease retains scope');
$b->release();
verifyWorldRuntime(!$runtime->scheduler->has('episode:MAP01/first'), 'last lease releases owned systems');
verifyWorldRuntime($adapter->source->active[0]->closed, 'adapter active scope closes');
$c = $runtime->activate('episode', 'MAP01');
verifyWorldRuntime($first->activated[1]['visits'] === 1, 'serializable scope state survives reactivation');
$c->release();
$runtime->close();
verifyWorldRuntime($adapter->source->closed, 'runtime closes opened sources');

final class CoreRebindAdapter implements ContentAdapter
{
	/** @var list<CoreFixtureSource> */
	public array $sources = [];
	/** @var list<SourceDefinition> */
	public array $definitions = [];

	public function open(SourceDefinition $definition, AdapterContext $context): ContentSource
	{
		$this->definitions[] = $definition;

		return $this->sources[] = new CoreFixtureSource;
	}
}

$rebindAdapter = new CoreRebindAdapter;
$rebind = new WorldRuntime(new AdapterContext);
$rebind->adapter('@wad/archive', $rebindAdapter);
$rebind->source(new SourceDefinition('match', '@wad/archive', [['slot' => 'base-wad','role' => 'iwad']]));

try
{
	$rebind->catalog('match');
	verifyWorldRuntime(false, 'unresolved source unexpectedly opened');
}
catch(LogicException $error)
{
	verifyWorldRuntime(str_contains($error->getMessage(), 'unresolved'), 'unresolved source reports its slot state');
}

$bound = [
	'sources' => [
		'match' => [
			'adapter' => '@wad/archive'
			, 'resources' => [['url' => 'https://one.example/base.wad','role' => 'iwad']]
		]
	]
];

$rebind->bindWorld($bound);
$lease = $rebind->activate('match', 'MAP01');

verifyWorldRuntime(
	$rebindAdapter->definitions[0]->resources[0]['url'] === 'https://one.example/base.wad'
	, 'bound resource reaches the adapter'
);

try
{
	$rebind->bindWorld($bound);
	verifyWorldRuntime(false, 'active source unexpectedly rebound');
}
catch(LogicException $error)
{
	verifyWorldRuntime(str_contains($error->getMessage(), 'active scopes'), 'active source prevents rebinding');
}

$lease->release();
$replacement = $bound;
$replacement['sources']['match']['resources'][0]['url'] = 'https://two.example/base.wad';
$rebind->bindWorld($replacement);
verifyWorldRuntime($rebindAdapter->sources[0]->closed, 'rebinding closes the prior parsed source');
$rebind->activate('match', 'MAP01')->release();

verifyWorldRuntime(
	$rebindAdapter->definitions[1]->resources[0]['url'] === 'https://two.example/base.wad'
	, 'rebound resource opens a fresh source'
);

$rebind->close();

final class CoreFixtureDevice
{
	public int $generation = 0, $begins = 0, $presents = 0;
	public bool $ready = true;

	public function ready(): bool { return $this->ready; }

	public function begin(array $color): void { $this->begins++; }

	public function present(): void { $this->presents++; }
}

final class CoreFixturePass implements RenderPass
{
	public int $restores = 0, $closes = 0;

	public function __construct(private string $name, private array &$order, private array $after = []) {}

	public function id(): string { return $this->name; }

	public function after(): array { return $this->after; }

	public function render(FrameContext $frame): void { $this->order[] = $this->name; }

	public function restore(): void { $this->restores++; }

	public function close(): void { $this->closes++; }
}

$device = new CoreFixtureDevice;
$drawn = [];
$worldPass = new CoreFixturePass('world', $drawn);
$hudPass = new CoreFixturePass('hud', $drawn, ['world']);
$pipeline = new FramePipeline($device);
$pipeline->add($hudPass);
$pipeline->add($worldPass);
verifyWorldRuntime($pipeline->render(.5), 'ready render pipeline presents a frame');
verifyWorldRuntime($drawn === ['world','hud'], 'render passes respect dependencies');
$device->generation++;
$pipeline->render();
verifyWorldRuntime($worldPass->restores === 1 && $hudPass->restores === 1, 'passes restore after context recovery');
$pipeline->close();
verifyWorldRuntime($worldPass->closes === 1 && $hudPass->closes === 1, 'pipeline closes passes once');

echo "$checks neutral world-runtime checks passed\n";
