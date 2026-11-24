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

// Exercise the emitted PHP shader beside the standalone appearance reference on identical sample faces.
$ridge = static fn(TerrainAppearance $appearance) => TerrainPhonics\SurfaceLayers::landscape(['preset'=>'mountain'],$appearance);
$referenceAppearance = static function(TerrainAppearance $appearance) use ($ridge): array {
	$granite = array_column($ridge($appearance)->layers,null,'id')['granite-slabs'];
	return $appearance->uniforms() + ['uGranitePigments[0]'=>[
		...TerrainAppearance::linear($granite['color']), ...TerrainAppearance::linear($granite['patternColor']),
	]];
};
$bands = [];
foreach([1, 2, 3, 8] as $sharpness) $bands[] = $referenceAppearance(new TerrainAppearance(['bandSharpness' => $sharpness]));
$shader = TerrainPhonics\MaterialShader::assemble(file_get_contents(dirname(__DIR__).'/shaders/terrain.frag'),$ridge(new TerrainAppearance));
$finish = new TerrainPhonics\SurfaceLayers([['color'=>'#262626','reflectivity'=>1.], ['color'=>'#262626','opacity'=>0.]]);
$stackInputs = static function($stack): array {
	$inputs = ['uLayerCount'=>[count($stack->layers)],'uMaterialBounce'=>$stack->bounce];
	$inspect = new ReflectionClass($stack);
	foreach(['colors'=>'Color','ranges'=>'Range','blends'=>'Blend','modes'=>'Mode','effects'=>'Effect',
		'patterns'=>'Pattern','patternColors'=>'PatternColor'] as $key=>$name)
		$inputs['uLayer'.$name.'[0]'] = $inspect->getProperty($key)->getValue($stack);
	return $inputs + $inspect->getProperty('fieldUniforms')->getValue($stack);
};

// Mountain faces drawn twice in two colors of one material: at an amount of 0 the color must not show.
$mountainAmounts = [];

foreach([['talus', .2, [0, .5]], ['stain', .7, [0, .3]]] as [$material, $slope, $amounts])
foreach($amounts as $amount)
{
	$cases = []; $stacks = [];

	foreach(['#ff00ff', '#00ff00'] as $color)
	{
		$appearance = new TerrainAppearance([$material.'Amount'=>$amount,$material.'Color'=>$color]);
		$cases[] = $referenceAppearance($appearance);
		$stacks[] = $stackInputs($ridge($appearance));
	}

	$mountainAmounts[] = ['material'=>$material,'slope'=>$slope,'amount'=>$amount,'cases'=>$cases,'stacks'=>$stacks];
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

// A 900 m layer at 10 per km: the texture's rows near the ground sit 356 m apart, so lighting through it must come from
// the shaders' exact layer depth, not from the texture.
$fog = new Atmosphere(['layers' => [['name' => 'Fog', 'enabled' => true, 'bottom' => 0., 'top' => 900., 'density' => 10.,
	'color' => '#ffffff']]]);
$thinLayer = ['band' => [0., 900., .01], 'samples' => []];
foreach([200., 600., 880., 920., 1200.] as $height)
	foreach([sin(deg2rad(35)), 1.] as $mu)
		$thinLayer['samples'][] = [$height, $mu, $fog->transmittance($height, $mu)];

// Isolate the material inputs to lighting so the fork weights have an independent linear RGB reference.
$probe = <<<'GLSL'
#version 300 es
precision highp float;
precision highp int;
// LAYERS
uniform float uWaterLevel;
// MATERIAL NOISE
// MATERIAL STACK
uniform vec3 atWorld;
out vec4 color;
void main() {
	vec3 n, bounce;
	vec2 finish;
	color.rgb=journeySurface(atWorld,vec3(0,1,0),vec3(0,1,0),1.,false,n,finish,bounce);
	color.a=finish.x;
}
GLSL;
$stacks = $blocks = $journey = [];
foreach(['#ff0000','#00ff00','#0000ff'] as $i=>$color) {
	// At world height 10 the second region has local height 2. Water-relative height stays 10 in all three.
	$layers = match($i) {
		0 => [['color'=>$color]],
		1 => [['color'=>'#000000'],['color'=>$color,'high'=>3.,'fade'=>.1,'reflectivity'=>.8]],
		2 => [['color'=>'#000000'],['color'=>$color,'datum'=>'water','low'=>9.,'fade'=>.1]],
	};
	$stack = new TerrainPhonics\SurfaceLayers($layers,['macroVariation'=>0.]);
	$stacks[] = $stack;
	$blocks[] = $stack->journeyBlock(['seed'=>$i,'damping'=>1.,'slopeScale'=>1.],[$i*32.,$i*8.,0.]);
	if($i>0) $journey[] = ['shader'=>TerrainPhonics\MaterialShader::assemble($probe,$stacks), 'block'=>base64_encode(implode('',$blocks))];
}

echo json_encode([
	'thinLayer' => $thinLayer, 'journey'=>$journey,
	'tiltShader'=>TerrainPhonics\MaterialShader::assemble(file_get_contents(dirname(__DIR__).'/shaders/terrain.frag'),
		new TerrainPhonics\SurfaceLayers([['color'=>'#557755'],['channel'=>'both','pattern'=>'fall-line','relief'=>'rib-tilt','strength'=>24.]])),
	'chartShader'=>TerrainPhonics\MaterialShader::assemble(file_get_contents(dirname(__DIR__).'/shaders/chart.frag'),
		new TerrainPhonics\SurfaceLayers([['color'=>'#ff0000']]),chart:true),
	'ridgeShader'=>$shader, 'spotLights' => $spotCases
	, 'finishShader'=>TerrainPhonics\MaterialShader::assemble(file_get_contents(dirname(__DIR__).'/shaders/terrain.frag'),$finish)
	, 'finishInputs'=>$stackInputs($finish)
	, 'lampFreeSpotLights' => TerrainPhonics\SpotLights::shaderSource(false)
	, 'uniforms' => $atmosphere->uniforms()
	, 'nightAmbient' => (new Atmosphere(['sunDirection' => [0,-1,0]]))->uniforms()['nightAmbient']
	, 'lut' => base64_encode($lut['rgba8'])
	, 'source' => AtmosphereRenderer::shaderSource()
	, 'waterCases' => $waterCases

	// Terrain appearances for the paint, band and mountain amount checks.
	, 'appearance' => $referenceAppearance(new TerrainAppearance)
	, 'redGrass' => $referenceAppearance(new TerrainAppearance(['grassColor' => '#ff0000']))
	, 'bands' => $bands
	, 'mountainAmounts' => $mountainAmounts
], JSON_THROW_ON_ERROR
);
