<?php
use PhpWasmEngine\Asset\Assets;
use PhpWasmEngine\Tiled\
{
	Loader,WorldStream
};

function streamFixture(?callable $request = null, ?callable $release = null): WorldStream
{
	$maps = [
		['fileName' => 'a.tmj','x' => 0,'y' => 0,'width' => 100,'height' => 100]
		, ['fileName' => 'b.tmj','x' => 200,'y' => 0,'width' => 100,'height' => 100]
		, ['fileName' => 'far.tmj','x' => 2000,'y' => 0,'width' => 100,'height' => 100]
	];

	$data = ['world.world' => ['maps' => $maps],'a.tmj' => mapData([layer()])
	, 'b.tmj' => mapData([
		layer()
	])
		, 'far.tmj' => mapData([layer()]),'tiles.tsj' => tiles()
	];

	return new WorldStream(fixture($data)->world('world.world'), $request, $release, 20, 100);
}

test('world metadata queries preserve manifest order without reading map files', function() {
	$reads = [];
	$maps = [
		['fileName' => 'far.tmj','x' => 1000000000,'y' => 0,'width' => 10,'height' => 10]
		, ['fileName' => 'negative.tmj','x' => -200,'y' => -100,'width' => 100,'height' => 100]
		, ['fileName' => 'overlap.tmj','x' => -150,'y' => -50,'width' => 100,'height' => 100]
	];

	$world = (new Loader(new Assets(function($path) use (&$reads, $maps) {
		$reads[] = $path;

		return json_encode(['maps' => $maps]);
	})))->world('/maps/demo.world');

	check(
		array_column($world->entries(-125, -25, 0, 0), 'fileName') === ['negative.tmj'
			, 'overlap.tmj'
		]
	);

	check(count($world->entries(0, -25, 0, 0, 50)) === 1);
	check($reads === ['/maps/demo.world']);
	rejects(fn() => $world->entries(0, 0, -1, 1));
	rejects(fn() => $world->entries(INF, 0, 1, 1));
	rejects(fn() => $world->entries(0, 0, 1, 1, -1));
});

test('world streaming waits for map, tileset and image dependencies before activation', function() {
	$ready = [];
	$requests = [];

	$stream = streamFixture(function($path) use (&$ready, &$requests) {
		$requests[$path] = true;

		return isset($ready[$path]);
	});

	$stream->update(0, 0, 40, 40);
	check(array_keys($requests) === ['a.tmj']);
	check(!$stream->isReady(0, 0, 40, 40) && $stream->loaded() === []);
	$ready['a.tmj'] = true;
	$stream->update(0, 0, 40, 40);
	check(isset($requests['tiles.tsj']) && !isset($requests['tiles.png']));
	$ready['tiles.tsj'] = true;
	$stream->update(0, 0, 40, 40);
	check(isset($requests['tiles.png']) && $stream->loaded() === []);
	$ready['tiles.png'] = true;
	$stream->update(0, 0, 40, 40);
	check(count($stream->entered) === 1 && $stream->isReady(0, 0, 40, 40));
	check(count($stream->visible(0, 0, 40, 40)) === 1);
	check(!isset($requests['b.tmj'], $requests['far.tmj']));
	$stream->update(0, 0, 40, 40);
	check($stream->entered === [] && $stream->exited === []);
});

test(
	'world streaming retains boundary maps and releases shared files only after their last map'
	, function() {
		$released = [];
		$stream = streamFixture(null, function($path) use (&$released) { $released[] = $path; });

		$stream->update(0, 0, 40, 40);
		$first = $stream->loaded()['a.tmj'];
		$first->layers[0]->set(0, 0, 2);
		$stream->update(170, 0, 40, 40);
		check(count($stream->loaded()) === 2 && $released === []);
		check($stream->loaded()['a.tmj'] === $first);
		$stream->update(225, 0, 40, 40);
		check(array_keys($stream->loaded()) === ['b.tmj']);
		check($stream->exited === [$first] && $released === ['a.tmj']);
		$stream->update(2000, 0, 40, 40);
		check(!in_array('tiles.png', $released, true));
		$stream->close();
		check(count(array_keys($released, 'tiles.png')) === 1);
		check(count(array_keys($released, 'tiles.tsj')) === 1);
		check($stream->loaded() === []);
		$stream->close();
		check(count(array_keys($released, 'tiles.png')) === 1);
	}
);

test('stream failures remain explicit and retryable without requesting distant maps', function() {
	$fail = true;
	$retries = [];

	$stream = streamFixture(function($path, $retry = false) use (&$fail, &$retries) {
		if($retry)
		{
			$retries[] = $path;
			$fail = false;
		}

		return $path === 'tiles.png' && $fail?'HTTP 503':true;
	});

	$stream->update(0, 0, 40, 40);
	check(isset($stream->errors['a.tmj']) && !$stream->isReady(0, 0, 40, 40));
	$stream->retry('a.tmj');
	$stream->update(0, 0, 40, 40);
	check($stream->errors === [] && $stream->isReady(0, 0, 40, 40));
	check($retries === ['tiles.png']);
});

test('pending maps release requests when leaving the retention area', function() {
	$released = [];

	$stream = streamFixture(fn($path) => false, function($path) use (&$released) {
		$released[] = $path;
	});

	$stream->update(0, 0, 40, 40);
	$stream->update(2000, 0, 40, 40);
	check($released === ['a.tmj']);
	check($stream->pending() === ['far.tmj']);
	$stream->close();
	check($released === ['a.tmj','far.tmj']);
});

test('world bounds index agrees with a flat scan across sparse and overlapping maps', function() {
	$maps = [];

	for($i = 0; $i < 160; $i++)
	{
		$maps[] = ['fileName' => "map-$i.tmj",'x' => (($i * 71) % 37 - 18) * 300
		, 'y' => ((
			$i * 13
		) % 31 - 15
		) * 200
			, 'width' => ($i % 7 + 1) * 150
			, 'height' => ($i % 5 + 1) * 200
		];
	}

	$world = fixture(['world.world' => ['maps' => $maps]])->world('world.world');

	for($i = 0; $i < 80; $i++)
	{
		$x = (($i * 17) % 23 - 11) * 400;
		$y = (($i * 19) % 29 - 14) * 300;

		$expected = array_filter(
			$maps
			, fn($m) => $m['x'] + $m['width'] >= $x && $m['x'] <= $x + 700 && $m['y'] + $m[
				'height'
			] >= $y && $m['y'] <= $y + 500
		);

		check($world->entries($x, $y, 700, 500) === $expected);
	}

	$world->data['maps'][0]['x'] = 1000000;
	$world->reindex();
	check(count($world->entries(1000000, $maps[0]['y'], 0, 0)) === 1);
});

test('a malformed external tileset can be replaced and retried', function() {
	$broken = true;
	$data = ['world.world' => ['maps' => [['fileName' => 'a.tmj'
	, 'x' => 0
	, 'y' => 0
	, 'width' => 100
		, 'height' => 100
	]]]
		, 'a.tmj' => mapData([layer()]),'tiles.tsj' => tiles()
	];

	$loader = new Loader(new Assets(function($path) use (&$broken, $data) {
		return $broken && $path === 'tiles.tsj'?'{broken':json_encode($data[$path]);
	}));

	$retried = [];

	$stream = new WorldStream($loader->world('world.world'), function($path, $retry = false) use (
		&$retried
	) {
		if($retry)
		{
			$retried[] = $path;
		}

		return true;
	}
	);

	$stream->update(0, 0, 20, 20);
	check(isset($stream->errors['a.tmj']));
	$broken = false;
	$stream->retry('a.tmj');
	$stream->update(0, 0, 20, 20);
	check($retried === ['tiles.tsj'] && $stream->errors === [] && $stream->isReady(0, 0, 20, 20));
});

test('stream retries accept error keys relative to the application working directory', function() {
	$data = ['maps/world.world' => ['maps' => [['fileName' => 'a.tmj'
	, 'x' => 0
	, 'y' => 0
	, 'width' =>
		100
		, 'height' => 100
	]]]
		, 'maps/a.tmj' => mapData([layer()])
		, 'maps/tiles.tsj' => tiles()
	];

	$stream = new WorldStream(
		fixture($data)->world('maps/world.world')
		, fn($path, $retry = false) => $path === 'maps/a.tmj'?'HTTP 503':true
	);

	$stream->update(0, 0, 20, 20);
	check(isset($stream->errors['maps/a.tmj']));
	$stream->retry('maps/a.tmj');
	check($stream->errors === []);
});

test(
	'local world bounds select negative infinite chunks without shifting their origin'
	, function() {
		$entry = ['fileName' => 'infinite.tmj'
		, 'x' => 1000
		, 'y' => 500
		, 'width' => 300
		, 'height' => 200
			, 'bounds' => ['x' => -200,'y' => -100,'width' => 300,'height' => 200]
		];

		$chunk = ['x' => -12,'y' => -6,'width' => 1,'height' => 1,'data' => [1]];

		$data = ['world.world' => ['maps' => [$entry]]
		, 'infinite.tmj' => mapData([layer(['chunks' => [
			$chunk
		]])], ['infinite' => true]
		)
			, 'tiles.tsj' => tiles()
		];

		$stream = new WorldStream(fixture($data)->world('world.world'), loadMargin:0, retainMargin:0);
		$stream->update(801, 401, 10, 10);
		$map = $stream->loaded()['infinite.tmj'];
		check($map->x === 1000.0 && $map->y === 500.0);
		check($map->layers[0]->gidAt(-12, -6)->id === 1);
		check(count($stream->visible(801, 401, 10, 10)) === 1);
		check($stream->visible(1101, 401, 1, 1) === []);
	}
);
