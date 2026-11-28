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
$molecular = new Atmosphere(['mie' => 0, 'ozone' => [0, 0, 0]]);
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
$vacuum = ['rayleigh' => [0.,0.,0.], 'mie' => 0., 'ozone' => [0.,0.,0.], 'sunDirection' => [0.,1.,0.]];
$solar = new Atmosphere($vacuum);
$expect($solar->uniforms()['angularRadius'] === .00935, 'The default solar radius is twice the original radius.');
$inside = $solar->sky([sin(.008),cos(.008),0.])[0];
$outside = $solar->sky([sin(.011),cos(.011),0.])[0];

$expect(
	$inside > 1000 && $outside === 0. && abs($inside * 2 * M_PI * (1 - cos(.00935)) - 20.) < 1e-8
	, 'The enlarged disk ends at its angular radius and retains the integrated solar irradiance.'
);

$mist = ['bottom' => 100.,'top' => 120.,'density' => 5.,'color' => '#ff0000'];
$upper = ['bottom' => 105.,'top' => 130.,'density' => 2.,'color' => '#0000ff'];
$air = new Atmosphere($vacuum + ['layers' => [$mist,$upper]]);

foreach([[0., .15], [110., .0948], [120., .0176], [130., 0.]] as [$eye,$tau])
$expect(
	abs($air->transmittance($eye, 1, 8)[0] - exp(-$tau)) < 1e-7
	, 'Vertical sunlight integrates the tapered density of thin and overlapping shells.'
);
$horizonDistance = sqrt((120. - 110.) * (2. * 6371000 + 110. + 120.));
$air->setLayers([['density' => .05] + $mist]);
// Numerical reference along a curved, grazing ray, independent of the renderer's polynomial integral.
$column = 0.;

for($i = 0; $i < 2000; $i++)
{
	$d = ($i + .5) * $horizonDistance / 2000;
	$h = (hypot(6371000 + 110., $d) - 6371000 - 100.) / 20.;
	$column += 6 * $h * (1 - $h) * $horizonDistance / 2000;
}

$expect(
	abs($air->transmittance(110., 0., 8)[0] - exp(-.00005 * $column)) < 1e-6
	, 'The tapered horizontal column follows the curvature of its layer.'
);

$below = new Atmosphere($vacuum + ['layers' => [['bottom' => -500.,'top' => -100.,'density' => 1.]]]);

foreach([[-600.,.4],[-500.,.4],[-300.,.2],[-100.,0.],[0.,0.]] as [$eye,$depth])
$expect(
	abs($below->transmittance($eye, 1., 8)[0] - exp(-$depth)) < 2e-6
	, 'Sunlight includes only the haze above the signed sample height.'
);
$expect($air->transmittance(130., 1.) === [1.,1.,1.], 'Looking up above the mist leaves the ray clear.');
$air->setLayers([$mist]);
$air->setSun([1.,.6,0.]);
$c = $air->sky([0.,1.,0.], 0., 8);
$expect($c[0] > .01 && $c[1] === 0. && $c[2] === 0., 'A 20-meter band between regular samples scatters its own color.');
$generation = $air->transmittanceLut(8, 4, 8)['generation'];
$mist['color'] = '#0000ff';
$air->setLayers([$mist]);
$c = $air->sky([0.,1.,0.], 0., 8);

$expect(
	$air->transmittanceLut(8, 4, 8)['generation'] === $generation && $c[0] === 0. && $c[2] > .01
	, 'Recoloring changes sky radiance and reuses the direct-light texture.'
);

$air->setLayers([['enabled' => false] + $mist]);

$expect(
	$air->transmittanceLut(8, 4, 8)['generation'] > $generation && $air->transmittance(0., 1.) === [1.,1.,1.] && $air
		->layers()[0]['color'] === '#0000ff'
	, 'Hiding a layer restores clear air and keeps its authored values.'
);

foreach([
	[
		[
			'top' => 0
		]
	]
	, [
		[
			'density' => -1
		]
	]
	, [
		[
			'density' => INF
		]
	]
	, [
		[
			'color' => 'orange'
		]
	]
	, array_fill(0, 7, [])
] as $invalid)
{
	$before = $air->layers();

	try
	{
		$air->setLayers($invalid);
		$expect(false, 'Invalid atmosphere layers should fail');
	}
	catch(InvalidArgumentException)
	{
		$expect($before === $air->layers(), 'Invalid layer edits preserve the previous atmosphere.');
	}
}

// The sky's diffuse light on terrain comes from the model's own sky.
$sky = new Atmosphere;

$fill = static function(Atmosphere $a, float $degrees): array {
	$e = deg2rad($degrees);
	$a->setSun([.6 * cos($e), sin($e), .8 * cos($e)]);

	return $a->uniforms()['skyAmbient'];
};

$noon = $fill($sky, 60.);
$low = $fill($sky, 8.);
$dusk = $fill($sky, -3.);

$expect(
	$noon[2] > $noon[1] && $noon[1] > $noon[0] && $noon[2] > .4
	, 'A high sun lights the ground with a strong blue sky'
);

$expect($low[2] / $low[0] < $noon[2] / $noon[0] && $low[2] < $noon[2], 'A low sun gives a dimmer, less blue sky light');
$expect(max($dusk) < .05, 'Sky light fades after sunset');
$e = deg2rad(35.5);
$sky->setSun([0., sin($e), cos($e)]);
$middle = $sky->uniforms()['skyAmbient'];
$sky->setSun([cos($e), sin($e), 0.]);
$expect($sky->uniforms()['skyAmbient'] === $middle, 'Sky light on level ground ignores the sun azimuth');
$a35 = $fill($sky, 35.);
$a36 = $fill($sky, 36.);

$expect(
	abs($middle[2] - .5 * ($a35[2] + $a36[2])) < 1e-12
	, 'Sky light interpolates between whole degrees of elevation'
);

$sky->setSkyLight(.5);
$half = $fill($sky, 35.);
$expect(abs($half[1] - .5 * $a35[1]) < 1e-12, 'The sky-light setting scales the fill');
$sky->setSkyLight(1.);
$sky->setHaze(3.);
$expect($fill($sky, 35.) !== $a35, 'Haze changes the sky light');

try
{
	$sky->setSkyLight(-1.);
	$expect(false, 'A negative sky light must fail');
}
catch(InvalidArgumentException)
{
	$expect(true, 'A negative sky light fails');
}

// Sky color scales molecular scattering against the default zenith color.
$colored = new Atmosphere;
$colored->setSkyColor(Atmosphere::DEFAULT_SKY_COLOR);
$generation = $colored->transmittanceLut(8, 4, 8)['generation'];

$expect(
	$colored->uniforms()['rayleigh'] === Atmosphere::DEFAULT_RAYLEIGH
	, 'The default sky color keeps the default scattering'
);

$expect(
	$colored->transmittanceLut(8, 4, 8)['generation'] === $generation
	, 'An unchanged sky color keeps the sunlight texture'
);

$colored->setSkyColor('#2C4FA8');
$royal = $colored->uniforms()['rayleigh'];

$expect(
	$colored->skyColor() === '#2c4fa8' && $royal[2] / $royal[0] > Atmosphere::DEFAULT_RAYLEIGH[
		2
	] / Atmosphere::DEFAULT_RAYLEIGH[0]
	, 'A royal blue sky scatters blue more strongly against red'
);

$expect($colored->transmittanceLut(8, 4, 8)['generation'] > $generation, 'A new sky color rebuilds sunlight');

$expect(
	$royal[0] < Atmosphere::DEFAULT_RAYLEIGH[0] && $royal[2] / $royal[1] <= (Atmosphere::DEFAULT_RAYLEIGH[
		2
	] / Atmosphere::DEFAULT_RAYLEIGH[1]
	) ** 1.25 + 1e-12
	, 'The scattering law stays within a fifth-power fit, however blue the chosen color'
);

$e = deg2rad(1.);
$colored->setSun([0., sin($e), cos($e)]);
$glow = $colored->sky([0., sin(deg2rad(2.)), cos(deg2rad(2.))], 0., 48);
$low = $colored->transmittance(0., sin(deg2rad(2.)));

$expect(
	$glow[0] >= $glow[1] && $glow[1] > $glow[2] && $low[0] > $low[1] && $low[1] > $low[2]
	, 'A deep blue sky still gives a warm sunset glow and reddened sunlight'
);

$alpine = new Atmosphere;
$alpine->setSkyColor('#41659a');
$e = deg2rad(35.);
$alpine->setSun([0., sin($e), cos($e)]);
$zenith = Atmosphere::toneMap($alpine->sky([0.,1.,0.], 0., 64));

foreach([0x41, 0x65, 0x9a] as $c => $wanted)
$expect(
	abs($zenith[$c] * 255 - $wanted) < 12
	, 'A sky color the scattering law can reach displays close to it at the zenith'
);
$pale = new Atmosphere;
$pale->setSkyColor('#8fa6bd');
$expect($pale->uniforms()['rayleigh'][0] > Atmosphere::DEFAULT_RAYLEIGH[0], 'A pale sky scatters more red');

foreach(['blue', '#12345', '#gg0000'] as $invalid)
{
	try
	{
		$pale->setSkyColor($invalid);
		$expect(false, 'An invalid sky color must fail');
	}
	catch(InvalidArgumentException)
	{
		$expect($pale->skyColor() === '#8fa6bd', 'An invalid sky color keeps the previous one');
	}
}

// Eyes adapt to a shadow by the ratio of sunlit to shaded level ground.
$adapt = new Atmosphere;
$ratios = [];

foreach([60., 35., 15., 5., 1.] as $degrees)
{
	$e = deg2rad($degrees);
	$adapt->setSun([0., sin($e), cos($e)]);
	$ratios[] = $adapt->shadeAdaptation();
}

$sorted = $ratios;
rsort($sorted);

$expect(
	$sorted === $ratios && $ratios[1] > 9 && $ratios[1] < 14 && $ratios[4] < 1.2
	, 'Shade adaptation falls from about 11 at 35 degrees to one at sunset'
);

$adapt->setSun([0., -.1, 1.]);
$expect($adapt->shadeAdaptation() === 1.0, 'After sunset the shade adaptation is one');
$e = deg2rad(35.);
$adapt->setSun([0., sin($e), cos($e)]);
$adapt->setSkyLight(2.);
$expect($adapt->shadeAdaptation() < $ratios[1], 'More sky light leaves less to adapt to');

// Ozone absorbs green and red along long sunlight paths and barely touches the noon sun.
$clear = new Atmosphere(['ozone' => [0., 0., 0.]]);
$ozone = new Atmosphere;
$sunset = [$clear->transmittance(0., sin(deg2rad(2.))), $ozone->transmittance(0., sin(deg2rad(2.)))];
$noon = [$clear->transmittance(0., 1.), $ozone->transmittance(0., 1.)];

$expect(
	$sunset[1][1] < .9 * $sunset[0][1] && $sunset[1][2] > .97 * $sunset[0][2]
	, 'Ozone takes green out of a setting sun'
);

$expect($noon[1][1] > .95 * $noon[0][1], 'Ozone barely dims the noon sun');

try
{
	new Atmosphere(['ozone' => [0., -1., 0.]]);
	$expect(false, 'Negative ozone must fail');
}
catch(InvalidArgumentException)
{
	$expect(true, 'Negative ozone fails');
}

// Haze color tints aerosol scattering without touching sunlight.
$dust = new Atmosphere;
$e = deg2rad(35.);
$dust->setSun([0., sin($e), cos($e)]);
$generation = $dust->transmittanceLut(8, 4, 8)['generation'];
$before = $dust->sky([0.,.2,1.], 0., 24);
$sun = $dust->transmittance(0., .5);
$dust->setHazeColor('#ffffff');
$expect($dust->sky([0.,.2,1.], 0., 24) === $before, 'White haze reproduces the neutral sky');
$dust->setHazeColor('#d9b380');
$after = $dust->sky([0.,.2,1.], 0., 24);

$expect(
	$dust->hazeColor() === '#d9b380' && $after[0] === $before[0] && $after[2] < $before[2]
	, 'Warm haze keeps its red glow toward the sun and scatters less blue'
);

$dust->setHazeColor('#808080');
$expect($dust->sky([0.,.2,1.], 0., 24) === $before, 'Gray haze is neutral: the haze setting alone sets its strength');
$dust->setHazeColor('#d9b380');

$expect(
	$dust->transmittance(0., .5) === $sun && $dust->transmittanceLut(8, 4, 8)['generation'] === $generation
	, 'Haze color leaves sunlight and its texture unchanged'
);

$expect($dust->uniforms()['hazeColor'][2] < $dust->uniforms()['hazeColor'][0], 'Haze color uploads as linear channels');

try
{
	new Atmosphere(['hazeColor' => [1., 1.2, 1.]]);
	$expect(false, 'Haze brighter than white must fail');
}
catch(InvalidArgumentException)
{
	$expect(true, 'Haze channels above one fail');
}

$sky = new Atmosphere;
$sky->setSun([1.,.04,0.]);
$expect($sky->starVisibility() === 0., 'Default field waits until sunset');
$sky->stars = new TerrainPhonics\Starfield(200., 100., 0.);

$expect(
	$sky->starVisibility() > 0. && $sky->uniforms()['nightVisibility'] === 0.
	, 'Brighter stars appear before sunset while the ground stays daylit'
);

$expect($sky->starsMoving(10., 9.) && !$sky->starsMoving(10., 10.), 'Visible twinkle uses simulation time');
$sky->setSun([0.,1.,0.]);
$expect(!$sky->starsMoving(10., 9.), 'A daylight view sleeps');
$sky->setSun([1.,-.25,0.]);
$sky->stars = new TerrainPhonics\Starfield(0., 0., 6.);
$starts = [];
$previous = null;

for($tick = 0; $tick < 6000; $tick++)
{
	$t = $tick * .01;
	$event = $sky->stars->meteor($t);

	if($event && !$previous) $starts[] = $event[0];

	if(!$event && $previous) $expect($sky->starsMoving($t, $t - .01), 'Last visible meteor frame is erased');

	$previous = $event;
}

$expect(
	$starts === range(0, 5) && $sky->starVisibility() === 0.
	, 'Six meteors per minute independent of ordinary star brightness'
);

$sky->stars = new TerrainPhonics\Starfield;
$expect($sky->stars->meteor(1.) === null && !$sky->starsMoving(12., 11.), 'Default sky is steady between frames');

$source = new Atmosphere;
$source->setHaze(1.4);
$job = $source->prepareOptics(17.5);
$steps = iterator_count($job);
$prepared = $job->getReturn();
$live = clone $source;
$live->setSun([0., .3, 1.]);
$live->usePreparedOptics($prepared);
$expected = $source->transmittanceLut(64, 16, 24);
$expect($steps > 1 && $live->transmittanceLut(64, 16, 24)['bytes'] === $expected['bytes'],
	'Staged integration yields and produces identical transmission for a moving sun.');
$live->setHaze(2.8);
$live->usePreparedOptics($prepared);
$changed = $live->transmittanceLut(64, 16, 24);
$expect($changed['generation'] > $expected['generation'] && $changed['bytes'] !== $expected['bytes'],
	'A haze edit rejects the stale arrival table and preserves the local generation.');

echo "Atmosphere: {$checks} checks passed\n";
