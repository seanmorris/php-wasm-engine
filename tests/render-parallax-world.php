<?php
require dirname(__DIR__) . '/autoload.php';
use PhpWasmEngine\Tiled\
{
	Loader,WorldStream
};
use PhpWasmEngine\Render\
{
	Device,Camera,MapRenderer,WorldRenderer
};

$device = new Device(64, 48);
$image = dirname(__DIR__) . '/examples/assets/test-parallax.png';
$other = dirname(__DIR__) . '/examples/assets/test.png';
$properties = [['name' => 'from','value' => 'center']
, ['name' => 'scrollX','value' => 6]
, [
	'name' => 'scrollY'
	, 'value' => -3
]];

$back = [
	'type' => 'imagelayer'
	, 'image' => $image
	, 'repeatx' => true
	, 'repeaty' => true
	, 'parallaxx' => .43
	, 'parallaxy' => .17
	, 'offsetx' => 3
	, 'offsety' => -2
	, 'opacity' => .6
	, 'properties' => $properties
];

$front = $back;
$front['image'] = $other;
$front['offsetx'] = -5;
$front['opacity'] = .3;

$front['properties'][] = [
	'name' => 'frames'
	, 'value' => json_encode([['image' => $other, 'duration' => 100], ['image' => $image,'duration' => 200]])
];

$data = [
	'orientation' => 'orthogonal'
	, 'width' => 8
	, 'height' => 16
	, 'tilewidth' => 16
	, 'tileheight' => 16
	, 'layers' => [$back,$front]
	, 'tilesets' => []
];

$entries = [];

foreach([-128,0,128] as $i => $x)
{
	$path = '/phase-' . $i . '.tmj';

	// Map-local Tiled reference points must not reset viewport-anchored strips.
	file_put_contents($path, json_encode($data + ['parallaxoriginx' => $i * 11, 'parallaxoriginy' => $i * -9]));
	$entries[] = ['fileName' => $path,'x' => $x,'y' => -128,'width' => 128,'height' => 256];
}

file_put_contents('/phase-reference.tmj', json_encode($data));
file_put_contents('/phase.world', json_encode(['maps' => $entries]));
$source = (new Loader)->world('/phase.world');
$reference = new MapRenderer((new Loader)->map('/phase-reference.tmj'));
$world = new WorldRenderer($source, 1);

function frame(
	Device $device
	, MapRenderer|WorldRenderer $renderer
	, Camera $camera
	, float $time
	, float $margin = 0
): array {
	$device->begin([0,0,0,1]);

	if($renderer instanceof WorldRenderer)
	{
		$stats = $renderer->render($camera, $time, $margin);
	}
	else
	{
		$renderer->render($camera, $time);
		$stats = $renderer->stats;
	}

	return [glReadPixels(0, 0, 64, 48, GL_RGBA, GL_UNSIGNED_BYTE),$stats];
}

$checks = [];

foreach([.5,1,2] as $zoom)
{
	foreach([-96,-.25,.25,96,128.25,-96] as $focus)
	{
		foreach([0,180,3125] as $time)
		{
			$camera = new Camera($focus - 32 / $zoom, -24 / $zoom, 64, 48, $zoom);
			[$expected] = frame($device, $reference, $camera, $time);

			foreach([0,128] as $margin)
			{
				[$actual,$stats] = frame($device, $world, $camera, $time, $margin);
				$checks["phase $zoom $focus $time $margin"] = $actual === $expected && $stats[
					'draws'
				] === 2;
			}
		}
	}
}

// A single translucent stack must survive explicit GPU restoration too.
$world->restore();
[$actual] = frame($device, $world, $camera, $time);
$checks['restore'] = $actual === $expected;
$world->close();

// Different backgrounds switch at the camera center, rather than when a neighbor
// first enters the viewport or preload margin. Ordinary map images still draw per map.
$source->load($entries[1])->layers[0]->offsetX += 2;
$candidates = [];

foreach($entries as $entry)
{
	$candidates[] = new MapRenderer($source->load($entry));
}

$world = new WorldRenderer($source);

foreach([[-.25,0],[.25,1],[-.25,0]] as $i => [$focus,$selected])
{
	$camera = new Camera($focus - 32, -24, 64, 48);
	[$expected] = frame($device, $candidates[$selected], $camera, 600);
	[$different] = frame($device, $candidates[1 - $selected], $camera, 600);
	[$actual] = frame($device, $world, $camera, 600, 128);
	$checks['selection ' . $i] = $actual === $expected && $actual !== $different;
}

$world->close();

foreach($candidates as $candidate)
{
	$candidate->close();
}

// Keep the previous ready backdrop while the destination loads, then switch
// without a phase jump. Revisiting an evicted map uses the same global phase.
$ready = false;

$stream = new WorldStream((new Loader)->world('/phase.world'), static function($path) use (
	&$ready
) {
	return $path !== '/phase-1.tmj' || $ready;
}, null, 0, 0
);

$world = new WorldRenderer($stream, 1);

foreach([[-64,false],[.25,false],[.25,true],[192,true],[-64,true]] as $i => [$focus,$available])
{
	$ready = $available;
	$camera = new Camera($focus - 32, -24, 64, 48);
	$stream->update($camera->x, $camera->y, 64, 48);
	[$expected] = frame($device, $reference, $camera, 725);
	[$actual,$stats] = frame($device, $world, $camera, 725);
	$checks['stream ' . $i] = $actual === $expected && $stats['draws'] === 2;

	if($i === 1)
	{
		$checks['destination pending'] = $stream->pending() === ['/phase-1.tmj'];
	}

	if($i === 3)
	{
		$checks['old map evicted'] = !isset($stream->loaded()['/phase-0.tmj']);
	}
}

$world->close();
$stream->close();
$reference->close();
$error = glGetError();
$device->close();
echo json_encode(compact('checks', 'error'));
