<?php
declare(strict_types=1);
namespace PhpWasmEngine\Benchmarks;

use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;

interface Located {}

class Position implements Located
{

	/**
	 * Store a position that systems can update without structural changes.
	 *
	 * @param float $x Initial horizontal coordinate.
	 * @return void Initializes the component value.
	 */
	public function __construct(public float $x = 0) {}
}

class Velocity { public float $x = 1; }

class Health { public int $value = 100; }

class Frozen {}

class Rare {}

class Marker {}

/**
 * Populate a deterministic mixture of common and rare components.
 *
 * @param int $size Number of entities to create, each with a position.
 * @return Registry Registry whose IDs and memberships are reproducible between runs.
 */
function scene(int $size): Registry
{
	$registry = new Registry;
	populate($registry, $size);

	return $registry;
}

/**
 * Add the deterministic benchmark fixture to an existing registry.
 *
 * @param Registry $registry Registry that receives the fixture entities.
 * @param int $size Number of entities to create.
 * @return void Populates the supplied registry in stable ID order.
 */
function populate(Registry $registry, int $size): void
{
	for($i = 0; $i < $size; $i++)
	{
		$components = [new Position];

		if($i % 2 === 0)
		{
			$components[] = new Velocity;
		}

		if($i % 4 !== 0)
		{
			$components[] = new Health;
		}

		if($i % 5 === 0)
		{
			$components[] = new Frozen;
		}

		if($i % 1000 === 0)
		{
			$components[] = new Rare;
		}

		$registry->create(...$components);
	}
}

/**
 * Consume a snapshot without adding a full iteration to query-only timings.
 *
 * @param list<int> $ids Ordered entity snapshot returned by the registry.
 * @return int Count and boundary checksum, bounded for wasm32 integers.
 */
function fingerprint(array $ids): int
{
	return count($ids) + ($ids[0] ?? 0) + ($ids[count($ids) - 1] ?? 0);
}

/**
 * Measure a workload after warming it and verify every timed batch.
 *
 * @param callable():int $run Workload returning a deterministic consumed result.
 * @param int $expected Expected checksum for one invocation.
 * @param int $iterations Invocations per measured sample.
 * @param int $samples Number of separately timed samples.
 * @return array<string,mixed> Per-invocation microsecond timings and checksum.
 */
function measure(callable $run, int $expected, int $iterations, int $samples): array
{
	for($warm = 0; $warm < 2; $warm++)
	{
		if($run() !== $expected)
		{
			throw new \RuntimeException('Warmup checksum mismatch');
		}
	}

	$start = hrtime(true);

	for($probe = 0; $probe < $iterations; $probe++)
	{
		$run();
	}

	$elapsed = max(1, hrtime(true) - $start);
	$iterations = min(100000, max($iterations, (int)ceil($iterations * 20000000 / $elapsed)));

	$times = [];

	for($sample = 0; $sample < $samples; $sample++)
	{
		$checksum = 0;
		$start = hrtime(true);

		for($iteration = 0; $iteration < $iterations; $iteration++)
		{
			$checksum += $run();
		}

		$times[] = (hrtime(true) - $start) / $iterations / 1000;

		if($checksum !== $expected * $iterations)
		{
			throw new \RuntimeException('Timed workload checksum mismatch');
		}
	}

	$sorted = $times;
	sort($sorted);
	$middle = intdiv(count($sorted), 2);
	$median = count($sorted) % 2 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;

	return [
		'median_us' => $median
		, 'min_us' => min($times)
		, 'max_us' => max($times)
		, 'samples_us' => $times
		, 'checksum' => $expected
		, 'iterations' => $iterations
	];
}

/**
 * Measure only the requested lifecycle phase while rebuilding its fixture outside the timer.
 *
 * @param callable():mixed $prepare Build the state required by one invocation.
 * @param callable(mixed):int $run Timed phase returning a deterministic checksum.
 * @param int $expected Expected checksum for one invocation.
 * @param int $iterations Invocations per measured sample.
 * @param int $samples Number of separately accumulated samples.
 * @return array<string,mixed> Per-invocation microsecond timings and checksum.
 */
function measurePrepared(
	callable $prepare
	, callable $run
	, int $expected
	, int $iterations
	, int $samples
): array {
	for($warm = 0; $warm < 2; $warm++)
	{
		if($run($prepare()) !== $expected)
		{
			throw new \RuntimeException('Prepared warmup checksum mismatch');
		}
	}

	$probeElapsed = 0;

	for($probe = 0; $probe < $iterations; $probe++)
	{
		$state = $prepare();
		$start = hrtime(true);
		$run($state);
		$probeElapsed += hrtime(true) - $start;
	}

	$probeElapsed = max(1, $probeElapsed);
	$iterations = min(1000, max($iterations, (int)ceil($iterations * 20000000 / $probeElapsed)));
	$times = [];

	for($sample = 0; $sample < $samples; $sample++)
	{
		$checksum = 0;
		$elapsed = 0;

		for($iteration = 0; $iteration < $iterations; $iteration++)
		{
			$state = $prepare();
			$start = hrtime(true);
			$checksum += $run($state);
			$elapsed += hrtime(true) - $start;
		}

		$times[] = $elapsed / $iterations / 1000;

		if($checksum !== $expected * $iterations)
		{
			throw new \RuntimeException('Prepared timed workload checksum mismatch');
		}
	}

	$sorted = $times;
	sort($sorted);
	$middle = intdiv(count($sorted), 2);
	$median = count($sorted) % 2 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;

	return [
		'median_us' => $median
		, 'min_us' => min($times)
		, 'max_us' => max($times)
		, 'samples_us' => $times
		, 'checksum' => $expected
		, 'iterations' => $iterations
	];
}

/**
 * Run query, component, lifecycle, and system workloads against the selected engine.
 *
 * @param array<string,mixed> $options Entity count, sample count, and repetition overrides.
 * @return array<string,mixed> Reproducible timings and PHP memory/runtime metadata.
 */
function benchmark(array $options): array
{
	$size = (int)($options['entities'] ?? 10000);
	$samples = (int)($options['samples'] ?? 7);
	$iterations = (int)($options['iterations'] ?? 40);

	if($size < 1000 || $size > 100000 || $samples < 1 || $iterations < 1 || $iterations > 1000)
	{
		throw new \InvalidArgumentException('Use 1000–100000 entities and 1–1000 iterations');
	}

	gc_collect_cycles();
	$before = memory_get_usage();
	$registry = scene($size);
	$resident = memory_get_usage() - $before;

	$queries = [
		'string_snapshot' => Position::class
		, 'all_common' => Query::all(Position::class, Velocity::class, Query::not(Frozen::class))
		, 'all_rare' => Query::all(Position::class, Velocity::class, Rare::class)
		, 'any' => Query::any(Velocity::class, Health::class)
		, 'not' => Query::not(Velocity::class, Frozen::class)
		, 'nested' => Query::all(Query::any(Velocity::class, Health::class), Query::not(Frozen::class, Rare::class))
	];

	$cases = [];
	$preparedCases = [];
	$counts = [];

	foreach($queries as $name => $query)
	{
		$expected = [];

		for($i = 0; $i < $size; $i++)
		{
			$match = [
				'string_snapshot' => true
				, 'all_common' => $i % 2 === 0 && $i % 5 !== 0
				, 'all_rare' => $i % 1000 === 0
				, 'any' => $i % 2 === 0 || $i % 4 !== 0
				, 'not' => $i % 2 !== 0 && $i % 5 !== 0
				, 'nested' => $i % 5 !== 0 && $i % 1000 !== 0
			][$name];

			if($match)
			{
				$expected[] = $i + 1;
			}
		}

		if($registry->query($query) !== $expected)
		{
			throw new \RuntimeException("Incorrect initial result for $name");
		}

		$cases[$name] = [fn() => fingerprint($registry->query($query)), fingerprint($expected), 1];
		$counts[$name] = count($expected);
	}

	$cases['all_fresh_description'] = [
		fn() => fingerprint($registry->query(Query::all(Position::class, Velocity::class, Query::not(Frozen::class))))
		, $cases['all_common'][1], 1
	];

	$cases['string_after_mutation'] = [
		function() use ($registry): int {
			$registry->add(1, new Marker);
			$registry->remove(1, Marker::class);

			return fingerprint($registry->query(Position::class));
		}
		, $cases['string_snapshot'][1], 1
	];

	$cases['all_after_mutation'] = [
		function() use ($registry, $queries): int {
			$registry->add(1, new Marker);
			$registry->remove(1, Marker::class);

			return fingerprint($registry->query($queries['all_common']));
		}
		, $cases['all_common'][1], 1
	];

	$cases['nested_after_mutation'] = [
		function() use ($registry, $queries): int {
			$registry->add(1, new Marker);
			$registry->remove(1, Marker::class);

			return fingerprint($registry->query($queries['nested']));
		}
		, $cases['nested'][1], 1
	];

	foreach(['get_exact' => Position::class, 'get_interface' => Located::class] as $name => $type)
	{
		$cases[$name] = [
			function() use ($registry, $size, $type): int {
				$found = 0;

				for($id = 1; $id <= $size; $id++)
				{
					$found += $registry->get($id, $type) instanceof Position ? 1 : 0;
				}

				return $found;
			}
			, $size, $size
		];
	}

	$cases['has_sparse'] = [
		function() use ($registry, $size): int {
			$found = 0;

			for($id = 1; $id <= $size; $id++)
			{
				$found += $registry->has($id, Rare::class) ? 1 : 0;
			}

			return $found;
		}
		, intdiv($size - 1, 1000) + 1, $size
	];

	$cases['add_remove_1000'] = [
		function() use ($registry): int {
			$found = 0;

			for($id = 1; $id <= 1000; $id++)
			{
				$registry->add($id, new Marker);
				$found += $registry->has($id, Marker::class) ? 1 : 0;
				$registry->remove($id, Marker::class);
			}

			return $found;
		}
		, 1000, 1000
	];

	$cases['replace_1000'] = [
		function() use ($registry): int {
			for($id = 1; $id <= 1000; $id++)
			{
				$registry->add($id, new Position);
			}

			return count($registry->query(Position::class));
		}
		, $size, 1000
	];

	$cases['create_destroy_1000'] = [
		function(): int {
			$registry = scene(1000);

			for($id = 1; $id <= 1000; $id++)
			{
				$registry->destroy($id);
			}

			return count($registry->query(Query::all()));
		}
		, 0, 1000
	];

	$preparedCases['add_1000'] = [
		fn() => scene(1000)
		, function(Registry $registry): int {
			for($id = 1; $id <= 1000; $id++)
			{
				$registry->add($id, new Marker);
			}

			return $registry->has(1000, Marker::class) ? 1000 : 0;
		}
		, 1000, 1000
	];

	$preparedCases['remove_1000'] = [
		function(): Registry {
			$registry = scene(1000);

			for($id = 1; $id <= 1000; $id++)
			{
				$registry->add($id, new Marker);
			}

			return $registry;
		}
		, function(Registry $registry): int {
			for($id = 1; $id <= 1000; $id++)
			{
				$registry->remove($id, Marker::class);
			}

			return $registry->has(1, Marker::class) || $registry->has(1000, Marker::class) ? 1 : 0;
		}
		, 0, 1000
	];

	$preparedCases['create_1000'] = [
		fn() => new Registry
		, function(Registry $registry): int {
			populate($registry, 1000);

			return $registry->alive(1000) ? 1000 : 0;
		}
		, 1000, 1000
	];

	$preparedCases['destroy_1000'] = [
		fn() => scene(1000)
		, function(Registry $registry): int {
			for($id = 1; $id <= 1000; $id++)
			{
				$registry->destroy($id);
			}

			return $registry->alive(1000) ? 1 : 0;
		}
		, 0, 1000
	];

	$listener = static function(Registry $registry, int $id, object $component): void {};

	$observedRegistry = scene($size);
	$observedRegistry->on('added', $listener);
	$observedRegistry->on('removed', $listener);

	$cases['add_remove_1000_observed'] = [
		function() use ($observedRegistry): int {
			$found = 0;

			for($id = 1; $id <= 1000; $id++)
			{
				$observedRegistry->add($id, new Marker);
				$found += $observedRegistry->has($id, Marker::class) ? 1 : 0;
				$observedRegistry->remove($id, Marker::class);
			}

			return $found;
		}
		, 1000, 1000
	];

	$cases['replace_1000_observed'] = [
		function() use ($observedRegistry, $size): int {
			for($id = 1; $id <= 1000; $id++)
			{
				$observedRegistry->add($id, new Position);
			}

			return count($observedRegistry->query(Position::class));
		}
		, $size, 1000
	];

	$cases['create_destroy_1000_observed'] = [
		function() use ($listener): int {
			$registry = new Registry;
			$registry->on('added', $listener);
			$registry->on('removed', $listener);
			populate($registry, 1000);

			for($id = 1; $id <= 1000; $id++)
			{
				$registry->destroy($id);
			}

			return count($registry->query(Query::all()));
		}
		, 0, 1000
	];

	$scheduler = new Scheduler($registry);
	$moved = 0;

	$scheduler->add('move', function(Registry $registry, float $dt) use (&$moved, $queries): void {
		$moved = 0;

		foreach($registry->query($queries['all_common']) as $id)
		{
			$registry->get($id, Position::class)->x += $registry->get($id, Velocity::class)->x * $dt;
			$moved++;
		}
	});

	$cases['system_update'] = [
		function() use ($scheduler, &$moved): int {
			$scheduler->update(1 / 60);

			return $moved;
		}
		, $counts['all_common'], 1
	];

	$results = [];

	foreach($cases as $name => [$run, $expected, $units])
	{
		$results[$name] = measure($run, $expected, $iterations, $samples) + ['units_per_call' => $units];
	}

	foreach($preparedCases as $name => [$prepare, $run, $expected, $units])
	{
		$results[$name] = measurePrepared($prepare, $run, $expected, $iterations, $samples)
			+ ['units_per_call' => $units, 'fixture_setup_timed' => false];
	}

	// Report retained query storage separately from entity/component storage.
	$registry->add(1, new Marker);
	$registry->remove(1, Marker::class);
	$before = memory_get_usage();

	foreach($queries as $query)
	{
		$registry->query($query);
	}

	return [
		'php' => PHP_VERSION
		, 'int_bytes' => PHP_INT_SIZE
		, 'sapi' => PHP_SAPI
		, 'jit' => ini_get('opcache.jit')
		, 'entities' => $size
		, 'samples' => $samples
		, 'registry_bytes' => $before ? $resident : null
		, 'query_cache_bytes' => $before ? memory_get_usage() - $before : null
		, 'results' => $results
	];
}

$benchmarkOptions ??= [];

foreach(array_slice($argv ?? [], 1) as $argument)
{
	if(preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match))
	{
		$benchmarkOptions[$match[1]] = $match[2];
	}
}

require ($benchmarkOptions['engine-root'] ?? dirname(__DIR__)) . '/autoload.php';
echo json_encode(benchmark($benchmarkOptions), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
