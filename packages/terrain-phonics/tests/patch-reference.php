<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\Landscape;
use TerrainPhonics\Lod;
use TerrainPhonics\Noise;
use TerrainPhonics\Scene;

// Exercise the real incremental builder without creating a graphics device.
$landscape = new Landscape(json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR));
$recipe = $landscape->recipe();
$sceneType = new ReflectionClass(Scene::class);
$scene = $sceneType->newInstanceWithoutConstructor();
$sceneType->getProperty('builder')->setValue($scene, null);
$build = $sceneType->getMethod('buildVertex');
$lod = new Lod($recipe['worldSize'], $recipe['spacing']);
$parents = $patches = [];
foreach([[0,0,0], [1,0,0], [1,1,1], [2,2,3]] as [$level,$x,$z])
{
	$node = $lod->node($level, $x, $z);
	$job = $node + ['index' => 0, 'heights' => [], 'bytes' => ''];
	while($job['index'] < (Lod::CELLS + 1) ** 2)
		$build->invokeArgs($scene, [&$job, $landscape, $parents]);
	$parents[$node['key']] = $job;
	$bytes = pack('e*', $job['minY'], $job['maxY'], $job['geometricError'], ...$job['heights'])
		. $job['normals'] . $job['bytes'];
	$patches[] = ['node' => $node, 'bytes' => base64_encode($bytes)];
}
echo json_encode(['recipe' => $recipe, 'permutation' => (new Noise($recipe['seed']))->permutation(), 'patches' => $patches],
	JSON_THROW_ON_ERROR);
