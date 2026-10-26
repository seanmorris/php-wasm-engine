<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Phonics\Asset\Assets;
use Phonics\Asset\Resource;
use Phonics\Tiled\Loader;
use Phonics\Physics\Pose;
use Phonics\Physics\Terrain;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Query;
$checks = 0;

function verifyCore(bool $value, string $message): void
{
	global $checks;
	$checks++;

	if(!$value) throw new RuntimeException($message);
}

verifyCore(!class_exists('SanePhonics\\Physics\\Body'), 'core does not install game classes');

foreach([
	['https://cdn.test/a/b/map.tmj?x=1', '../set.tsj?v=2#tile', 'https://cdn.test/a/set.tsj?v=2#tile']
	, ['https://cdn.test/a/b/', 'tile.png', 'https://cdn.test/a/b/tile.png']
	, ['https://cdn.test/a/map.tmj', 'b//tile.png', 'https://cdn.test/a/b//tile.png']
	, ['https://cdn.test/a/map.tmj', '.', 'https://cdn.test/a/']
	, ['https://cdn.test/a/map.tmj', '/art/tile.png', 'https://cdn.test/art/tile.png']
	, ['https://cdn.test/a/map.tmj?q=1', '#tile', 'https://cdn.test/a/map.tmj?q=1#tile']
	, ['https://cdn.test/a/map.tmj?q=1', '?q=2', 'https://cdn.test/a/map.tmj?q=2']
	, ['/tmp/maps/a.tmj', '../art/tile.png', '/tmp/art/tile.png']
	, ['maps/a.tmj', '../tile.png', 'tile.png']
] as [$base, $reference, $expected])
verifyCore(Assets::relative($base, $reference) === $expected, 'URL resolution ' . $reference);
Resource::mount('https://cdn.test/maps/a.tmj', '/mounted/a.tmj');

verifyCore(
	Assets::relative('/mounted/a.tmj', 'tiles.tsj') === 'https://cdn.test/maps/tiles.tsj'
	, 'mount retains provenance'
);

$r = new Registry;
$id = $r->create(new Phonics\Render\Sprite(1));
verifyCore($r->has($id, PhpWasmEngine\Render\Sprite::class), 'mixed legacy has');
verifyCore($r->query(Query::all(PhpWasmEngine\Render\Sprite::class)) === [$id], 'mixed nested query');
$r->remove($id, PhpWasmEngine\Render\Sprite::class);
verifyCore($r->query(Phonics\Render\Sprite::class) === [], 'mixed remove');
$terrain = new Terrain([['points' => [[0, 100], [200, 100], [200, 120], [0, 120]]]]);
$hit = $terrain->castBody(new Pose(50, 0, 5, 5), 0, 200);
verifyCore($hit !== null && abs($hit->t - .475) < .0001, 'neutral swept geometry retains exact extent');
$data = [
	'https://cdn.test/maps/map.tmj' => [
		'type' => 'map'
		, 'tilewidth' => 16
		, 'tileheight' => 16
		, 'width' => 2
		, 'height' => 2
		, 'layers' => [
			[
				'id' => 1
				, 'type' => 'objectgroup'
				, 'objects' => [
					['id' => 1, 'template' => '../templates/player.tx', 'x' => 0, 'y' => 0]
				]
			]
		]
	]
	, 'https://cdn.test/templates/player.tx' => [
		'type' => 'template'
		, 'object' => [
			'type' => 'Actor'
			, 'properties' => [
				['name' => 'texture', 'type' => 'file', 'value' => '../art/pixel.png?v=1']
			]
		]
	]
	, 'https://cdn.test/maps/a.world' => [
		'maps' => [['fileName' => 'map.tmj', 'x' => 20, 'y' => 10, 'width' => 32, 'height' => 32]]
	]
	, 'https://cdn.test/maps/b.world' => [
		'maps' => [['fileName' => 'map.tmj', 'x' => 400, 'y' => 10, 'width' => 32, 'height' => 32]]
	]
];

$loader = new Loader(new Assets(static fn(string $url): string => json_encode($data[$url])));
$a = $loader->world('https://cdn.test/maps/a.world')->visible(0, 0, 1000, 1000)[0];
$b = $loader->world('https://cdn.test/maps/b.world')->visible(0, 0, 1000, 1000)[0];
verifyCore($a->x === 20.0 && $b->x === 400.0 && $a !== $b, 'separate mutable placements');
$properties = Loader::properties($a->layers[0]->objects[0]['properties']);
verifyCore($properties['texture'] === 'https://cdn.test/art/pixel.png?v=1', 'template file property provenance');
$a->layers[0]->objects[0]['x'] = 999;
verifyCore($b->layers[0]->objects[0]['x'] === 0, 'placed entities do not mutate source data');

$world = new \Phonics\Tiled\World($loader, 'https://cdn.test/maps/duplicate.world', [
	'maps' => [
		['fileName' => 'map.tmj','x' => 0,'y' => 0,'width' => 32,'height' => 32]
		, ['fileName' => 'map.tmj','x' => 100,'y' => 0,'width' => 32,'height' => 32]
	]
]);

$released = [];

$stream = new \Phonics\Tiled\WorldStream($world, null, static function($path) use (&$released): void {
	$released[] = $path;
}, 0, 0
);

$stream->update(0, 0, 140, 32);
$placed = array_values($stream->loaded());

verifyCore(
	count($placed) === 2 && $placed[0]->identity !== $placed[1]->identity
	, 'stream distinguishes duplicate source placements'
);

$placed[0]->layers[0]->objects[0]['x'] = 77;
verifyCore($placed[1]->layers[0]->objects[0]['x'] === 0, 'streamed placement state is independent');
$stream->update(100, 0, 32, 32);
verifyCore(count($stream->loaded()) === 1 && $released === [], 'evicting one placement retains shared dependencies');
$stream->close();

verifyCore(
	count(array_filter($released, static fn($url) => $url === 'https://cdn.test/maps/map.tmj')) === 1
	, 'last placement releases source exactly once'
);

echo "$checks core Phonics checks passed\n";
