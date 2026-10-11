<?php
use PhpWasmEngine\Asset\Assets;
use PhpWasmEngine\Ecs\
{ObjectSpawner,Registry};
use PhpWasmEngine\Render\SpriteSheet;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Physics\Body;

test('sprite sheets expose Tiled classes as named animations',function() {
	$sheet = new SpriteSheet(new Loader,dirname(__DIR__).'/examples/assets/tiles.tsj');
	check($sheet->sprite('runner')->gid === 7);
	check($sheet->map->animatedGid(5,180) === 6);
	rejects(fn() => $sheet->sprite('missing'));
});
test('object factories produce PHP components at world positions',function() {
	$loader = new Loader;
	$map = $loader->map(dirname(__DIR__).'/examples/assets/platformer.tmj');
	$map->x = -100;
	$spawner = new ObjectSpawner;
	$spawner->register('Player',fn($object) => [new Body($object['x'],$object['y'])]);
	$registry = new Registry;
	$entities = $spawner->spawn($map,$registry);
	near($registry->get($entities[1],Body::class)->x,-20);
});
test('asset paths normalize parent segments and preserve URL authorities',function() {
	check(Assets::relative('/assets/maps/a.tmj','../tiles/b.tsj') === '/assets/tiles/b.tsj');
	check(Assets::relative('https://example.test/maps/a.tmj','../tiles/b.tsj') === 'https://example.test/tiles/b.tsj');
});
test('asset read failures can be retried and unsupported formats fail explicitly',function() {
	$tries = 0;
	$assets = new Assets(function() use (&$tries) { return ++$tries === 1?false:'{}'; });
	rejects(fn() => $assets->read('a'));
	check($assets->read('a') === '{}');
	rejects(fn() => Loader::decode('!!!!',1,'base64'));
	rejects(fn() => Loader::decode('AAAAAA==',1,'base64','zstd'));
	$loader = fixture(['a' => mapData([],['orientation' => 'hexagonal'])]);
	rejects(fn() => $loader->map('a'));
});
