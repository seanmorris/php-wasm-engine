<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/autoload.php';
use TerrainPhonics\Landscape;
use TerrainPhonics\Lightning;
use TerrainPhonics\Weather;

$checks = 0;

function expectLightning(bool $condition, string $message): void
{
	global $checks;
	$checks++;

	if(!$condition) throw new RuntimeException($message);
}

// A storm strikes, and the same clock and place give the same strikes.
$storm = new Weather;
expectLightning($storm->controls()['lightning'] === 0, 'New weather has no lightning');
expectLightning(Lightning::begun($storm, 4, 0., 0.) === [], 'Clear weather does not strike');
$storm->edit(Weather::PRESETS['storm'], 0.);
expectLightning($storm->controls()['lightning'] === 60, 'The storm preset turns lightning on');
expectLightning($storm->moving(1., 0.), 'Lightning asks for new frames');

$count = $near = 0;
$reach = (Lightning::AROUND + 1) * Lightning::SQUARE;

for($slot = 0; $slot < 400; $slot++)
{
	$strikes = Lightning::begun($storm, $slot, 100., -200.);

	expectLightning(
		$strikes === Lightning::begun($storm, $slot, 100., -200.)
		, 'A slot lists the same strikes each time'
	);

	foreach($strikes as [$x, $z, $start, $seed])
	{
		$count++;

		if(hypot($x - 100., $z + 200.) < 3000.) $near++;

		expectLightning(
			$start >= $slot * Lightning::SLOT && $start < ($slot + 1) * Lightning::SLOT
			, 'A strike starts in its slot'
		);

		expectLightning(
			abs($x - 100.) <= $reach && abs($z + 200.) <= $reach
			, 'A strike lies in the squares around the viewer'
		);

		expectLightning(
			is_int($seed) && $seed >= 0 && $seed < 1 << 24
			, 'A seed fits the 24 bits a shader keeps exactly'
		);

		expectLightning(Lightning::clouded($x, $z, $storm->offset($start), .96), 'A strike leaves opaque cloud');
	}
}

// At 60 percent, about 1.2 strikes a second are in view and one in 37 seconds lands within 3 kilometers.
expectLightning(
	$count > 800 && $count < 1150
	, "The storm preset strikes about 1.2 times a second, not $count in 800 s"
);

expectLightning($near > 8 && $near < 45, "Close strikes are rare, not $near in 800 seconds");

// A viewer one square over looks at eight of the same nine columns, and sees the same strikes in them.
$mine = $both = 0;

for($slot = 0; $slot < 200; $slot++)
{
	$here = Lightning::begun($storm, $slot, 100., -200.);
	$there = Lightning::begun($storm, $slot, 100. + Lightning::SQUARE, -200.);
	$mine += count($here);
	$both += count(array_uintersect($here, $there, static fn(array $a, array $b): int => $a <=> $b));
}

expectLightning(
	$both > $mine * .8 && $both < $mine * .96
	, "Viewers a square apart share eight ninths of their strikes, not $both of $mine"
);

// Strikes come one at a time. A slot with many at once would mean neighboring squares strike together.
$most = 0;

for($slot = 0; $slot < 400; $slot++) $most = max($most, count(Lightning::begun($storm, $slot, 100., -200.)));

expectLightning($most <= 10, "No slot holds a burst of strikes, but one held $most");

// The rate follows the control, and thin cloud strikes less than a full deck.
$storm->edit(['lightning' => 100], 0.);
$full = 0;

for($slot = 0; $slot < 400; $slot++) $full += count(Lightning::begun($storm, $slot, 100., -200.));

expectLightning(
	$full > $count * 1.4 && $full < $count * 1.95
	, 'The full rate strikes five thirds as often as 60 percent'
);

$storm->edit(['coverage' => 50], 0.);
$thin = 0;

for($slot = 0; $slot < 400; $slot++) $thin += count(Lightning::begun($storm, $slot, 100., -200.));

expectLightning($thin > 0 && $thin < $full * .6, 'Half cover strikes well under the rate of a full deck');
$storm->edit(['coverage' => 0], 0.);
expectLightning(Lightning::begun($storm, 7, 100., -200.) === [], 'Lightning needs cloud');
expectLightning(!Lightning::clouded(0., 0., [0., 0.], 0.), 'A clear sky has no opaque cloud');
expectLightning(Lightning::clouded(0., 0., [0., 0.], 1.), 'A full deck is opaque everywhere');

// Strikes are lit from their start to the end of their life.
$storm->edit(Weather::PRESETS['storm'] + ['lightning' => 100], 0.);
$seen = 0;

foreach(Lightning::begun($storm, 12, 0., 0.) as [$x, $z, $start, $seed])
{
	$strike = [$x, $z, $start, $seed];
	expectLightning(in_array($strike, Lightning::lit($storm, $start, 0., 0.), true), 'A strike is lit as it starts');

	expectLightning(
		in_array($strike, Lightning::lit($storm, $start + Lightning::LIFE - .01, 0., 0.), true)
		, 'A strike stays lit'
	);

	expectLightning(
		!in_array($strike, Lightning::lit($storm, $start - .01, 0., 0.), true)
		, 'A strike is dark before it starts'
	);

	expectLightning(
		!in_array($strike, Lightning::lit($storm, $start + Lightning::LIFE, 0., 0.), true)
		, 'A strike ends'
	);

	$seen++;
}

expectLightning($seen > 0, 'The full rate strikes within one slot');

// A strike moves to the highest ground nearby, and to the water's surface over water.
$ground = new Landscape(['preset' => 'mountain', 'seed' => 'first-light']);
$sea = $ground->recipe()['waterLevel'];
mt_srand(11);
$rise = 0.;

for($n = 0; $n < 40; $n++)
{
	$x = (float)mt_rand(-15000, 15000);
	$z = (float)mt_rand(-15000, 15000);
	$land = Lightning::ground($ground, $x, $z, $sea);
	$under = max($sea, $ground->height(round($x), round($z)));
	expectLightning($land === Lightning::ground($ground, $x, $z, $sea), 'A strike lands in the same place each time');
	expectLightning($land[1] >= $under, 'A strike never lands lower than the ground under it');
	expectLightning(hypot($land[0] - $x, $land[2] - $z) <= 801., 'A strike stays within 800 meters');

	expectLightning(
		abs($land[1] - max($sea, $ground->height($land[0], $land[2]))) < 1e-9
		, 'A strike lands on the ground, or on the water over it'
	);

	$rise += $land[1] - $under;
}

expectLightning($rise / 40 > 15., 'On a mountain map strikes climb to high ground');
$outside = Lightning::ground($ground, 40000., 12., $sea);
expectLightning($outside === [40000., $sea, 12.], 'Past the edge of the map a strike runs down to the water level');
$ground->close();

// The top of a bolt stays over where the strike began unless that would lay the bolt nearly flat.
expectLightning(Lightning::top(100., 50., [130., 0., 90.], 1000.) === [100., 1000., 50.], 'A steep bolt keeps its top');
[$topX, $topY, $topZ] = Lightning::top(600., 0., [0., 300., 0.], 500.);

expectLightning(
	abs($topX - 120.) < 1e-9 && $topY === 500. && $topZ === 0.
	, 'A low cloud draws the top in toward the landing'
);

expectLightning(
	Lightning::top(5., 5., [5., 0., 5.], 800.) === [5., 800., 5.]
	, 'A strike straight down has its top overhead'
);

expectLightning(
	Lightning::top(600., 0., [0., 300., 0.], 200.) === [0., 200., 0.]
	, 'A cloud below the ground leaves no lean'
);

// The bolt comes down dimly, flashes, flashes again, and is dark at the end of its life.
expectLightning(
	Lightning::glow(-.01, 5) === [0., 0., 0.] && Lightning::glow(Lightning::LIFE, 5) === [0., 0., 0.]
	, 'No light outside a strike'
);

[$main, $forks, $down] = Lightning::glow(Lightning::DESCENT / 2, 5);

expectLightning(
	$main === .5 && $forks === .5 && abs($down - 1 / 3) < 1e-9
	, 'Half way through the descent a third of the bolt shows'
);

expectLightning(
	Lightning::glow(Lightning::DESCENT, 5) === [1., 1., 1.]
	, 'The first stroke is the brightest and shows the whole bolt'
);

$peaks = 0;
$last = 1.;
$rising = false;

for($age = Lightning::DESCENT; $age < Lightning::LIFE; $age += .002)
{
	[$main, $forks] = Lightning::glow($age, 5);
	expectLightning($main >= 0. && $main <= 1. && $forks >= 0. && $forks <= 1., 'Light stays between nothing and full');

	if($main > $last + 1e-9 && !$rising) $peaks++;

	$rising = $main > $last + 1e-9;
	$last = $main;
}

expectLightning(
	$peaks === 2 && $last < .02
	, "Seed 5 flashes twice more and fades out, not $peaks times ending at $last"
);

$once = 0;
$last = 1.;
$rising = false;

for($age = Lightning::DESCENT; $age < Lightning::LIFE; $age += .002)
{
	[$main] = Lightning::glow($age, 4);

	if($main > $last + 1e-9 && !$rising) $once++;

	$rising = $main > $last + 1e-9;
	$last = $main;
}

expectLightning($once === 1, 'An even seed flashes once more');

// The strike's length, its reach for high ground and its color are weather controls.
expectLightning(
	Lightning::glow(.9, 5, 1.6) === Lightning::glow(.45, 5)
	, 'A longer strike plays the same light more slowly'
);

expectLightning(
	Lightning::glow(1.6, 5, 1.6) === [0., 0., 0.] && Lightning::glow(.3, 5, .3) === [0., 0., 0.]
	, 'Light ends with the strike'
);

$long = new Weather;
$long->edit(Weather::PRESETS['storm'] + ['lightning' => 100, 'boltSeconds' => 2], 0.);
$strike = Lightning::begun($long, 12, 0., 0.)[0];

expectLightning(
	in_array($strike, Lightning::lit($long, $strike[2] + 1.9, 0., 0.), true)
	, 'A two-second strike is still lit after 1.9'
);

expectLightning(
	!in_array($strike, Lightning::lit($long, $strike[2] + 2., 0., 0.), true)
	, 'A two-second strike ends at two'
);

$long->strike(0., 0., 50.);

expectLightning(
	isset($long->snapshot(51.5)['strike']) && $long->moving(51.6, 51.5)
	, 'A called strike lasts as long as the control says'
);

$mesas = new Landscape(['preset' => 'red-mesas', 'seed' => 'red-mesas']);
$floor = $mesas->recipe()['waterLevel'];
mt_srand(23);
$near = $far = 0.;

for($n = 0; $n < 30; $n++)
{
	$x = (float)mt_rand(-12000, 12000);
	$z = (float)mt_rand(-12000, 12000);
	$below = Lightning::ground($mesas, $x, $z, $floor, 0.);
	$short = Lightning::ground($mesas, $x, $z, $floor, 300.);
	$wide = Lightning::ground($mesas, $x, $z, $floor, 1500.);
	expectLightning([$below[0], $below[2]] === [round($x), round($z)], 'With no reach a strike lands straight below');
	expectLightning(hypot($short[0] - $x, $short[2] - $z) <= 301., 'A strike moves no farther than its reach');

	expectLightning(
		$short[1] >= $below[1] && hypot($wide[0] - $x, $wide[2] - $z) <= 1501.
		, 'A reach never lowers the landing'
	);

	$near += $short[1] - $below[1];
	$far += $wide[1] - $below[1];
}

expectLightning($far > $near && $near > 0., 'A longer reach finds higher ground on Red Mesas');
$mesas->close();
$tinted = new Weather;
$tinted->edit(['boltColor' => '#FF8800', 'flashDark' => 40, 'boltForks' => 0], 0.);
expectLightning($tinted->controls()['boltColor'] === '#FF8800', 'The bolt takes a color');

foreach([
	[
		'boltColor' => 'orange'
	]
	, [
		'boltColor' => '#f80'
	]
	, [
		'boltColor' => 16746496
	]
	, [
		'boltSeconds' => 2.5
	]
	, [
		'boltReach' => -1
	]
] as $bad)
{
	try
	{
		$tinted->edit($bad, 1.);
		throw new RuntimeException('Accepted ' . json_encode($bad));
	}
	catch(InvalidArgumentException)
	{
	}
}

// A strike called by hand joins the weather's saved state while it is lit.
$called = new Weather;
expectLightning($called->called() === [] && !isset($called->snapshot(0.)['strike']), 'No strike is called at first');
$called->edit(Weather::PRESETS['rain'], 10.);
$called->strike(300., -40., 12.);
[$x, $z, $start, $seed] = $called->called();

expectLightning(
	[$x, $z, $start] === [300., -40., 12.] && $seed >= 0 && $seed < 1 << 24
	, 'A called strike keeps its place and time'
);

expectLightning($called->moving(12.2, 12.1), 'A called strike asks for new frames while it is lit');
expectLightning(!$called->controls()['lightning'], 'Calling a strike leaves the lightning rate alone');
$saved = $called->snapshot(12.3);

expectLightning(
	($saved['strike'] ?? null) === [300., -40., 12., $seed] && Weather::valid($saved)
	, 'A lit strike is saved'
);

$replayed = new Weather;
$replayed->restore(json_decode(json_encode($saved), true), 100.);
[$x, $z, $start, $again] = $replayed->called();

expectLightning(
	[$x, $z, $again] === [300., -40., $seed] && abs($start - 99.7) < 1e-9
	, 'A restored strike keeps its age on the new clock'
);

expectLightning(!isset($called->snapshot(12.01 + Lightning::LIFE)['strike']), 'A spent strike is not saved');
$called->translate(1000., -500., 12.4);
expectLightning(array_slice($called->called(), 0, 2) === [1300., -540.], 'A called strike moves with the map');

$malformed = [[1, 2, 3], 'now', [1, 2, NAN, 4], ['a' => 1, 2, 3, 4]];

foreach($malformed as $n => $bad)
expectLightning(!Weather::valid(['strike' => $bad] + $saved), "Malformed strike $n is refused");

foreach([NAN, INF, 1e13] as $invalid)
{
	try
	{
		$called->strike($invalid, 0., 0.);
		throw new RuntimeException('Accepted a strike at ' . json_encode((string)$invalid));
	}
	catch(InvalidArgumentException)
	{
	}
}

// Weather saved before lightning existed still loads, without lightning.
$old = (new Weather)->snapshot(0.);

foreach([
	'lightning'
	, 'boltSeconds'
	, 'flashDark'
	, 'flashGlow'
	, 'boltWidth'
	, 'boltZigzag'
	, 'boltForks'
	, 'boltReach'
	, 'boltColor'
] as $key)
unset($old['controls'][$key]);

expectLightning(Weather::valid($old), 'Weather saved before lightning existed is valid');
$restored = new Weather;
$restored->restore($old, 0.);

expectLightning(
	$restored->controls() === Weather::DEFAULTS
	, 'Old weather loads without lightning, with every option as designed'
);

foreach(['clear', 'cloudy', 'rain', 'snow'] as $preset)
expectLightning(Weather::PRESETS[$preset]['lightning'] === 0, "Choosing $preset ends the storm");

echo "Lightning: $checks checks passed.\n";
