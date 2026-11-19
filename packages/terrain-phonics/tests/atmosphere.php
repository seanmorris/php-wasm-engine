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
echo "Atmosphere: {$checks} checks passed\n";
