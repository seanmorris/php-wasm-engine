<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\Atmosphere;
use TerrainPhonics\AtmosphereRenderer;
use TerrainPhonics\TerrainAppearance;
use TerrainPhonics\Water;

// PHP is the independent numeric reference; the Node test executes the actual shader includes in WebGL2.
$atmosphere = new Atmosphere;
$lut = $atmosphere->transmittanceLut(64, 16, 24);
$waves = [
	['amplitude' => .27,'wavelength' => 13,'direction' => [2,1],'phase' => .3]
	, ['amplitude' => .13,'wavelength' => 5,'direction' => [-1,3],'angularSpeed' => 1.7,'phase' => -.8]
];

$wakeWater = new Water(2.5, $waves);
$wakeWater->wake(0, 0, .7, 0, 0, 20);
$wakeWater->wake(4, -3, -.4, 1.2);
$wakeWater->wake(-2, 7, 1.5, -7.5);
$manyWaves = [];

for($i = 0; $i < Water::MAX_WAVES; $i++)
$manyWaves[] = [
	'amplitude' => .02 * ($i + 1)
	, 'wavelength' => 20 + 9 * $i
	, 'direction' => [cos($i),sin($i)]
	, 'angularSpeed' => .6 + .1 * $i
	, 'phase' => $i
];

$fullWater = new Water(-1.75, $manyWaves);
$fullWater->setWaveScale(2.5);

for($i = 0; $i < Water::MAX_WAKES; $i++) $fullWater->wake(2 * $i - 10, $i - 8, .02 * ($i + 1), -2 + .05 * $i);

$waterCases = [];

foreach(['flat' => new Water(3.5),'waves' => new Water(-2.25, $waves),'wakes' => $wakeWater,'capacity' => $fullWater]
	as $name => $water
){
	$samples = [];

	foreach([[0,0],[4,-3],[-120.5,85.25],[16300,-16200]] as [$x,$z])
	foreach([0,.001,1.25,5.9,8,9,1000] as $time)
	{
		$sample = $water->sample($x, $z, $time);
		$frame = clone $water;
		$frame->update($time);
		$draw = $frame->uniforms();
		$samples[] = [
			'position' => [$x,$z]
			, 'time' => $time
			, 'packets' => $draw['frameWakes'], 'jets' => $draw['jetVelocities'], 'bounds' => $draw['wakeBounds']
			, 'expected' => [$sample['height'],$sample['dx'],$sample['dz'],$sample['velocity'],...$sample['normal']]
		];
	}

	$waterCases[] = ['name' => $name,'uniforms' => $water->uniforms(),'samples' => $samples];
}

$bands = [];
foreach([1, 2, 3, 8] as $sharpness) $bands[] = (new TerrainAppearance(['bandSharpness' => $sharpness]))->uniforms();

// Mountain faces drawn twice in two colours of one material: at an amount of 0 the colour must not show.
$mountainAmounts = [];

foreach([['talus', .2, [0, .5]], ['stain', .7, [0, .3]]] as [$material, $slope, $amounts])
foreach($amounts as $amount)
{
	$cases = [];

	foreach(['#ff00ff', '#00ff00'] as $color)
	$cases[] = (new TerrainAppearance([$material . 'Amount' => $amount, $material . 'Color' => $color]))->uniforms();

	$mountainAmounts[] = ['material' => $material, 'slope' => $slope, 'amount' => $amount, 'cases' => $cases];
}

echo json_encode([
	'uniforms' => $atmosphere->uniforms()
	, 'nightAmbient' => (new Atmosphere(['sunDirection' => [0,-1,0]]))->uniforms()['nightAmbient']
	, 'lut' => base64_encode($lut['rgba8'])
	, 'source' => AtmosphereRenderer::shaderSource()
	, 'waterCases' => $waterCases

	// Terrain appearances for the paint, band and mountain amount checks.
	, 'appearance' => (new TerrainAppearance)->uniforms()
	, 'redGrass' => (new TerrainAppearance(['grassColor' => '#ff0000']))->uniforms()
	, 'bands' => $bands
	, 'mountainAmounts' => $mountainAmounts
], JSON_THROW_ON_ERROR
);
