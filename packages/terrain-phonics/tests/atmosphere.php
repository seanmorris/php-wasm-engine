<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\Atmosphere;

$checks = 0;

$expect = static function(bool $condition, string $message) use (&$checks): void {
	$checks++;

	if(!$condition) throw new RuntimeException($message);
};

$atmosphere = new Atmosphere(['sunDirection' => [0,1,0]]);
$zenith = $atmosphere->transmittance(0, 1);
$horizon = $atmosphere->transmittance(0, 0);
$high = $atmosphere->transmittance(10000, 1);
$expect($zenith[0] > $zenith[1] && $zenith[1] > $zenith[2], 'Rayleigh extinction attenuates blue most strongly');

for($channel = 0; $channel < 3; $channel++)
{
	$expect($horizon[$channel] < $zenith[$channel], 'longer horizon optical path has more extinction');
	$expect($high[$channel] > $zenith[$channel], 'high-altitude sun crosses less dense atmosphere');
}

$expect($atmosphere->transmittance(10, -1) === [0.0,0.0,0.0], 'planet blocks rays toward the ground');
$expect($atmosphere->transmittance(80000, 1) === [1.0,1.0,1.0], 'outward ray at atmosphere top is unattenuated');
$expect($atmosphere->transmittance(-100, 1) === $zenith, 'below-sea-level altitude remains stable');
$molecular = new Atmosphere(['mie' => 0]);
$numeric = $molecular->transmittance(0, 1, 256);
$params = $molecular->uniforms();
$integral = $params['rayleighHeight'] * (1 - exp(-$params['atmosphereHeight'] / $params['rayleighHeight']));

foreach($params['rayleigh'] as $channel => $beta)
$expect(
	abs($numeric[$channel] - exp(-$beta * $integral)) < 2e-5
	, 'zenith numerical transmittance matches the closed-form exponential density integral'
);

$lut = $atmosphere->transmittanceLut(32, 12, 32);

$expect(
	count($lut['data']) === 32 * 12 * 3 && strlen($lut['bytes']) === 32 * 12 * 16
	, 'GPU float texture data has exact dimensions'
);

$expect(strlen($lut['rgba8']) === 32 * 12 * 4, 'GPU byte texture has exact dimensions');

$expect(
	$atmosphere->transmittanceLut(32, 12, 32)['generation'] === $lut['generation']
	, 'repeated texture requests reuse cached numerical integration'
);

$atmosphere->setSun([1,.05,0]);

$expect(
	$atmosphere->transmittanceLut(32, 12, 32)['generation'] === $lut['generation']
	, 'moving the sun does not invalidate the physical transmittance cache'
);

$atmosphere->setHaze(2);

$expect(
	$atmosphere->transmittanceLut(32, 12, 32)['generation'] > $lut['generation']
	, 'changing aerosol density rebuilds the transmittance cache'
);

$expect($atmosphere->transmittance(0, 1)[0] < $zenith[0], 'higher aerosol density reduces direct light');
$atmosphere->setHaze(1);
$atmosphere->setSun([0,1,0]);
$blueSky = $atmosphere->sky([1,.8,0]);
$expect($blueSky[2] > $blueSky[0], 'daytime sky away from the sun is blue in linear radiance');
$disk = $atmosphere->sky([0,1,0]);
$expect(array_sum($disk) > 100 * array_sum($blueSky), 'finite solar disk contributes high dynamic range light');
$atmosphere->setSun([1,.02,0]);
$sunset = $atmosphere->transmittance(0, .02);
$expect($sunset[0] > 2 * $sunset[2], 'low-angle direct sunlight becomes warm');

foreach([0,.01,2000,80000,1e6] as $altitude)
foreach([[0,1,0],[1,0,0],[1,-.01,0]] as $direction)
{
	$rgb = $atmosphere->sky($direction, $altitude);

	$expect(
		count(array_filter($rgb, static fn($value) => is_finite($value) && $value >= 0)) === 3
		, 'sky radiance remains finite at atmosphere and horizon boundaries'
	);
}

$dark = Atmosphere::toneMap([0,0,0]);
$bright = Atmosphere::toneMap([1,2,100]);
$expect($dark === [0.0,0.0,0.0], 'zero radiance remains black');
$expect($bright[0] < $bright[1] && $bright[1] < $bright[2] && $bright[2] <= 1, 'tone mapping is monotone and bounded');
$expect(Atmosphere::toneMap([1,2,100], 0) === [0.0,0.0,0.0], 'zero exposure is black');
$atmosphere->setSun([0,-1,0], 0);
$sky = $atmosphere->sky([0,1,0]);
$expect($sky[2] > $sky[1] && $sky[1] > $sky[0] && $sky[0] > 0, 'full night has blue sky glow');
$expect($atmosphere->sky([1,0,0])[2] > $sky[2], 'night horizon glows above the zenith');
$atmosphere->setSun([1,-.085,0], 0);
$twilight = $atmosphere->uniforms()['nightVisibility'];
$expect($twilight > .45 && $twilight < .55, 'stars and ambient light fade continuously through dusk');
$atmosphere->setSun([1,0,0], 0);
$expect($atmosphere->uniforms()['nightVisibility'] === 0.0, 'daylight hides the night sky');
$atmosphere->setSun([0,-1,0], 0);
$atmosphere->setNightGlow(0);
$expect($atmosphere->uniforms()['nightVisibility'] === 1.0, 'stars remain visible with the fill off');
$expect($atmosphere->sky([0,1,0]) === [0.0,0.0,0.0], 'zero solar irradiance yields zero scattered radiance');
$vacuum = ['rayleigh'=>[0.,0.,0.], 'mie'=>0., 'sunDirection'=>[0.,1.,0.]];
$solar = new Atmosphere($vacuum);
$expect($solar->uniforms()['angularRadius'] === .00935, 'The default solar radius is twice the original radius.');
$inside = $solar->sky([sin(.008),cos(.008),0.])[0];
$outside = $solar->sky([sin(.011),cos(.011),0.])[0];
$expect($inside > 1000 && $outside === 0. && abs($inside * 2*M_PI*(1-cos(.00935))-20.) < 1e-8,
	'The enlarged disk ends at its angular radius and retains the integrated solar irradiance.');

$mist = ['bottom'=>100.,'top'=>120.,'density'=>5.,'color'=>'#ff0000'];
$upper = ['bottom'=>105.,'top'=>130.,'density'=>2.,'color'=>'#0000ff'];
$air = new Atmosphere($vacuum + ['layers'=>[$mist,$upper]]);
foreach([[0., .15], [110., .09], [120., .02], [130., 0.]] as [$eye,$tau])
	$expect(abs($air->transmittance($eye,1,8)[0]-exp(-$tau)) < 1e-10,
		'Vertical sunlight crosses the exact length of thin and overlapping shells.');
$horizonDistance = sqrt((120.-110.)*(2.*6371000+110.+120.));
$air->setLayers([['density'=>.05]+$mist]);
$expect(abs($air->transmittance(110.,0.,8)[0]-exp(-.00005*$horizonDistance)) < 1e-12,
	'A horizontal ray exits the curved layer at the sphere intersection.');
$expect($air->transmittance(130.,1.) === [1.,1.,1.], 'Looking up above the mist leaves the ray clear.');
$air->setLayers([$mist]);
$air->setSun([1.,.6,0.]);
$c = $air->sky([0.,1.,0.],0.,8);
$expect($c[0] > .01 && $c[1] === 0. && $c[2] === 0., 'A 20-meter band between regular samples scatters its own color.');
$generation = $air->transmittanceLut(8,4,8)['generation'];
$mist['color'] = '#0000ff';
$air->setLayers([$mist]);
$c = $air->sky([0.,1.,0.],0.,8);
$expect($air->transmittanceLut(8,4,8)['generation'] === $generation && $c[0] === 0. && $c[2] > .01,
	'Recoloring changes sky radiance and reuses the direct-light texture.');
$air->setLayers([['enabled'=>false]+$mist]);
$expect($air->transmittanceLut(8,4,8)['generation'] > $generation && $air->transmittance(0.,1.) === [1.,1.,1.]
	&& $air->layers()[0]['color'] === '#0000ff', 'Hiding a layer restores clear air and keeps its authored values.');
foreach([[['top'=>0]], [['density'=>-1]], [['density'=>INF]], [['color'=>'orange']], array_fill(0,7,[])] as $invalid) {
	$before = $air->layers();
	try { $air->setLayers($invalid); $expect(false,'Invalid atmosphere layers should fail'); }
	catch(InvalidArgumentException) { $expect($before === $air->layers(),'Invalid layer edits preserve the previous atmosphere.'); }
}
echo "Atmosphere: {$checks} checks passed\n";
