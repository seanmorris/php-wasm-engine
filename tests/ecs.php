<?php
use PhpWasmEngine\Ecs\Query;
use PhpWasmEngine\Ecs\Registry;

interface EcsTagged {}

class EcsBase implements EcsTagged {}

class EcsChild extends EcsBase {}

class EcsSibling implements EcsTagged {}

class EcsOther {}

test('ECS snapshots stay independent across callers, mutations and registries', function() {
	$r = new Registry;
	$empty = $r->create();
	$a = $r->create(new EcsBase);
	$query = Query::all(EcsBase::class);
	$snapshot = $r->query($query);
	$edited = $r->query(Query::all(EcsBase::class));
	$edited[] = 999;
	check($r->query($query) === [$a]);
	check((new Registry)->query($query) === []);
	check($r->query(Query::all()) === [$empty, $a]);
	check($r->query(Query::any()) === []);
	check($r->query(Query::not()) === [$empty, $a]);
	$b = $r->create();
	check($r->query(Query::all()) === [$empty, $a, $b]);
	$r->add($b, new EcsBase);
	check($r->query($query) === [$a, $b]);
	$r->destroy($a);
	$r->destroy($empty);
	check($r->query($query) === [$b]);
	check($r->query(Query::not(EcsOther::class)) === [$b]);
	check($snapshot === [$a]);
});

test('ECS query descriptions remain mutable, including shared nested terms', function() {
	$r = new Registry;
	$a = $r->create(new EcsBase);
	$b = $r->create(new EcsOther);
	$nested = Query::any(EcsBase::class);
	$query = Query::all($nested);
	check($r->query($query) === [$a]);
	$nested->terms = [EcsOther::class];
	check($r->query($query) === [$b]);
	$nested->operation = 'not';
	check($r->query($query) === [$a]);
	$query->operation = 'any';
	$query->terms = ['nested' => $nested, 'other' => EcsOther::class];
	check($r->query($query) === [$a, $b]);
	$query->terms = [];
	check($r->query($query) === []);
});

test('ECS snapshots remain correct when many query descriptions exceed cache capacity', function() {
	$r = new Registry;
	$ids = [];

	for($i = 0; $i < 3000; $i++)
	{
		$ids[] = $r->create(new EcsBase);
	}

	$snapshot = $r->query(Query::all(EcsBase::class));

	for($i = 0; $i < 100; $i++)
	{
		$query = Query::all(EcsBase::class, Query::not('MissingEcsComponent'.$i));
		check($r->query($query) === $ids);
	}
	$r->destroy($ids[0]);
	check($snapshot === $ids);
	check($r->query(Query::all(EcsBase::class)) === array_slice($ids, 1));
	check($r->query(Query::all('MissingEcsComponent0')) === []);
});

test('ECS query ordering preserves direct index seeds and creation-order fallbacks', function() {
	$r = new Registry;
	$a = $r->create();
	$b = $r->create();
	$c = $r->create();
	$r->add($b, new EcsBase);
	$r->add($a, new EcsBase);
	$r->add($a, new EcsOther);
	$r->add($b, new EcsOther);
	check($r->query(EcsBase::class) === [$b, $a]);
	check($r->query(Query::all(EcsBase::class, EcsOther::class)) === [$b, $a]);
	check($r->query(Query::all(EcsOther::class, EcsBase::class)) === [$a, $b]);
	check($r->query(Query::any(EcsBase::class)) === [$a, $b]);
	check($r->query(Query::all(Query::any(EcsBase::class))) === [$a, $b]);
	check($r->query(Query::not(EcsOther::class)) === [$c]);
	$r->add($c, new EcsBase);
	check($r->query(EcsBase::class) === [$b, $a, $c]);
	check($r->query(Query::all(EcsBase::class)) === [$a, $b, $c]);
	$r->remove($b, EcsBase::class);
	$r->add($b, new EcsBase);
	check($r->query(EcsBase::class) === [$a, $c, $b]);
});

test('ECS shared ancestor indexes survive sibling removal and keep get precedence', function() {
	$r = new Registry;
	$child = new EcsChild;
	$base = new EcsBase;
	$sibling = new EcsSibling;
	$id = $r->create($child, $sibling, $base);
	check($r->get($id, EcsBase::class) === $base);
	check($r->get($id, EcsTagged::class) === $child);
	check($r->query(EcsTagged::class) === [$id]);
	$r->remove($id, EcsChild::class);
	check($r->has($id, EcsBase::class) && $r->has($id, EcsTagged::class));
	check($r->get($id, EcsTagged::class) === $sibling);
	$r->remove($id, EcsBase::class);
	check(!$r->has($id, EcsBase::class) && $r->has($id, EcsTagged::class));
	$r->remove($id, EcsTagged::class);
	check(!$r->has($id, EcsTagged::class));
	check($r->query(Query::all()) === [$id]);
	rejects(fn() => $r->get($id, EcsTagged::class));
});

test('ECS lifecycle listeners see fresh queries during replacement and destruction', function() {
	$r = new Registry;
	$id = $r->create(new EcsBase, new EcsOther);
	$query = Query::any(EcsBase::class, EcsOther::class);
	$events = [];
	$r->query($query);
	$r->query(EcsBase::class);

	foreach(['added', 'removed'] as $event)
	{
		$r->on($event, function($registry, $entity, $component) use (&$events, $query, $event) {
			$events[] = [$event, get_class($component), $registry->query($query)
				, $registry->query(EcsBase::class), $registry->alive($entity)
			];
		});
	}
	$r->add($id, new EcsBase);
	$r->destroy($id);

	check(
		$events === [['removed', EcsBase::class, [$id], [], true]
		, ['added'
		, EcsBase::class
		, [$id]
			, [$id], true
		]
		, ['removed', EcsOther::class, [$id], [$id], true]
		, ['removed'
			, EcsBase::class, [], [], true
		]
		]
	);
	check($r->query(Query::all()) === [] && $r->query($query) === []);
});

test('ECS deferred commands invalidate cached snapshots when flushed', function() {
	$r = new Registry;
	$id = $r->create(new EcsBase);
	$query = Query::not(EcsOther::class);
	$snapshot = $r->query($query);

	$r->defer(function($registry) use ($id) {
		$registry->add($id, new EcsOther);
		$registry->defer(fn($registry) => $registry->create(new EcsBase));
	});
	check($r->query($query) === $snapshot);
	$r->flush();
	check($r->query($query) === [$id + 1]);
	check($snapshot === [$id]);
});

test('ECS indexes survive structural changes made inside removal listeners', function() {
	$r = new Registry;
	$id = $r->create(new EcsChild, new EcsSibling);

	$r->on('removed', function($registry, $entity, $component) {
		if($component instanceof EcsChild)
		{
			$registry->remove($entity, EcsSibling::class);
		}
	});
	$r->remove($id, EcsTagged::class);
	check(!$r->has($id, EcsTagged::class));
	check(!$r->has($id, EcsSibling::class));
	check($r->query(Query::any(EcsTagged::class)) === []);

	$r = new Registry;
	$id = $r->create(new EcsBase);
	$replacement = new EcsBase;
	$replaceOnce = true;

	$r->on('removed', function($registry, $entity) use (&$replaceOnce) {
		if($replaceOnce)
		{
			$replaceOnce = false;
			$registry->add($entity, new EcsBase);
			check($registry->query(Query::all(EcsTagged::class)) === [$entity]);
		}
	});
	$r->add($id, $replacement);
	check($r->get($id, EcsBase::class) === $replacement);
	check($r->query(EcsTagged::class) === [$id]);
	$r->destroy($id);
	check($r->query(EcsTagged::class) === []);
	check($r->query(Query::all(EcsTagged::class)) === []);
});

/**
 * Evaluate query truth directly from component objects, without registry indexes.
 *
 * @param array<class-string,object> $components Components attached to one entity.
 * @param Query|class-string $query Predicate to evaluate.
 * @return bool Whether the components satisfy the predicate.
 */
function ecsReferenceMatch(array $components, $query): bool
{
	if(is_string($query))
	{
		foreach($components as $component)
		{
			if($component instanceof $query)
			{
				return true;
			}
		}
		return false;
	}

	$matches = array_map(fn($term) => ecsReferenceMatch($components, $term), $query->terms);

	return match($query->operation)
	{
		'all' => !in_array(false, $matches, true)
		, 'any' => in_array(true, $matches, true)
		, 'not' => !in_array(true, $matches, true)
	};
}

test('ECS nested boolean queries agree with object membership through structural churn'
	, function() {
		$r = new Registry;
		$model = [];
		$types = [EcsBase::class, EcsChild::class, EcsSibling::class, EcsOther::class];

		$queries = [Query::all(), Query::any(), Query::not()
		, Query::not(
			EcsBase::class
			, EcsOther::class
		)
			, Query::all(EcsTagged::class, Query::not(EcsOther::class))
			, Query::any(Query::all(EcsTagged::class, EcsOther::class), Query::not(EcsBase::class))
			, Query::not(
				Query::any(EcsOther::class, EcsSibling::class)
				, Query::not(EcsTagged::class)
			)
			, Query::all(Query::not(), Query::any(EcsBase::class, EcsSibling::class))
		];

		for($i = 0; $i < 30; $i++)
		{
			$model[$r->create()] = [];
		}

		// An arithmetic sequence gives repeatable churn without modifying global RNG state.
		for($step = 0; $step < 240; $step++)
		{
			$id = 1 + ($step * 13) % 30;
			$type = $types[intdiv($step, 7) % count($types)];
			$remove = $step % 3 === 0;

			if($remove || isset($model[$id][$type]))
			{
				foreach($model[$id] as $class => $component)
				{
					if($component instanceof $type)
					{
						unset($model[$id][$class]);
					}
				}
			}

			if($remove)
			{
				$r->remove($id, $type);
			}
			else
			{
				$component = new $type;
				$model[$id][$type] = $component;
				$r->add($id, $component);
			}

			foreach($queries as $query)
			{
				$expected = array_keys(
					array_filter($model, fn($parts) => ecsReferenceMatch($parts, $query))
				);
				$actual = $r->query($query);
				sort($actual);
				check($actual === $expected, "Query differs from object membership at step $step");
				check($r->query($query) === $r->query(clone $query));
			}

			foreach([...$types, EcsTagged::class] as $indexed)
			{
				check($r->has($id, $indexed) === ecsReferenceMatch($model[$id], $indexed));
			}
		}
	}
);
