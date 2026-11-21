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

// Mountain faces drawn twice in two colors of one material: at an amount of 0 the color must not show.
$mountainAmounts = [];

foreach([['talus', .2, [0, .5]], ['stain', .7, [0, .3]]] as [$material, $slope, $amounts])
foreach($amounts as $amount)
{
	$cases = [];

	foreach(['#ff00ff', '#00ff00'] as $color)
	$cases[] = (new TerrainAppearance([$material . 'Amount' => $amount, $material . 'Color' => $color]))->uniforms();

	$mountainAmounts[] = ['material' => $material, 'slope' => $slope, 'amount' => $amount, 'cases' => $cases];
}

/**
 * Pack spot lights as a scene uploads them at full night.
 * @param array ...$lights Lights for SpotLights::set().
 * @return array Uniform values by name.
 */
$spots = static function(array ...$lights): array {
	$set = new TerrainPhonics\SpotLights;
	$set->set($lights);

	return $set->uniforms(1);
};

// Level ground at the origin, 2 m below the overhead lamp. The lamp gives it the irradiance that the knee turns into 4
// scene units, and the next three cases give it the same.
$knee = TerrainPhonics\SpotLights::KNEE;
$perCandela = TerrainPhonics\SpotLights::LUX * TerrainPhonics\SpotLights::NIGHT_GAIN;
$intensity = 4 * $knee * (exp(4 / $knee) - 1) / $perCandela;
$overhead = ['position' => [0,2,0],'direction' => [0,-1,0],'color' => [1,1,1],'intensity' => $intensity,'range' => 100
	,'inner' => .2,'outer' => .4];
$band = acos((cos(.2) + cos(.4)) / 2);
$spotCases = [
	'none' => $spots(), 'overhead' => $spots($overhead)
	, 'higher' => $spots(['position' => [0,4,0],'intensity' => 4 * $intensity] + $overhead)
	// Twice as far at 45 degrees: eight times the squared distance and half the square root of two of the cosine.
	, 'oblique' => $spots(['position' => [2,2,0],'direction' => [-1,-1,0],'intensity' => sqrt(8) * $intensity]
		+ $overhead)
	, 'pair' => $spots(...array_fill(0, 2, ['intensity' => $intensity / 2] + $overhead))
	// Without the knee this would turn both surfaces white.
	, 'glare' => $spots(['intensity' => 64 * $intensity] + $overhead)
	// The glare lamp turned so the point lies halfway through its cone band, in cosine: a quarter of its light.
	, 'band' => $spots(['direction' => [sin($band), -cos($band), 0],'intensity' => 64 * $intensity] + $overhead)

	// These add no light.
	, 'away' => $spots(['direction' => [0,1,0]] + $overhead)
	, 'outside' => $spots(['direction' => [sin(.5),-cos(.5),0]] + $overhead)
	, 'short' => $spots(['range' => 1.5] + $overhead)
	, 'below' => $spots(['position' => [0,-2,0],'direction' => [0,1,0]] + $overhead)
	// A narrow lamp over the point where the sightline from (0, 5, 5) meets water at a level of 1, missing the ground.
	, 'column' => $spots(['position' => [0,3,1],'inner' => .1,'outer' => .2,'intensity' => 4 * $intensity] + $overhead)
];

echo json_encode([
	'spotLights' => $spotCases
	, 'lampFreeSpotLights' => TerrainPhonics\SpotLights::shaderSource(false)
	, 'uniforms' => $atmosphere->uniforms()
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
