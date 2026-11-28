<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/autoload.php';
use TerrainPhonics\Weather;

$w = new Weather;

function equalWeather($got, $expected): void
{
	if($got != $expected) throw new RuntimeException(json_encode([$got,$expected]));
}

equalWeather($w->moving(20., 19.), false);
$w->edit(Weather::PRESETS['snow'], 0.);
equalWeather($w->ground(30.), [0.,.5]);
equalWeather($w->ground(90.), [0.,1.]);
$w->edit(Weather::PRESETS['clear'], 90.);
equalWeather($w->ground(150.), [.5,.5]);
equalWeather($w->ground(210.), [1.,0.]);
equalWeather($w->ground(255.), [.5,0.]);
equalWeather($w->ground(300.), [0.,0.]);
equalWeather($w->moving(301., 300.), false);
$w->edit(Weather::PRESETS['snow'], 300.);
$w->edit(Weather::PRESETS['rain'], 360.);
equalWeather($w->ground(380.), [1.,1. - 1. / 3.]);
$w->edit([], 380., true);
equalWeather($w->ground(380.), [0.,0.]);
equalWeather($w->ground(390.), [.5,0.]);
$before = $w->offset(391.);
$w->edit(['wind' => 37,'heading' => 270], 391.);
equalWeather($w->offset(391.), $before);
$w->translate(100., -500., 391.);
equalWeather($w->offset(391.), [$before[0] + 100.,$before[1] - 500.]);
$saved = $w->snapshot(397.);
$copy = new Weather;
$copy->restore($saved, 0.);
equalWeather($copy->ground(2.), $w->ground(399.));
equalWeather($copy->offset(0.), $w->offset(397.));
equalWeather(Weather::valid(array_replace($saved, ['offset' => [INF,2]])), false);
$bad = $saved;
$bad['controls']['kind'] = 'hail';
equalWeather(Weather::valid($bad), false);

// Roads can be kept clearer than the ground. The limit is a setting, so presets leave it alone.
$roads = new Weather;
equalWeather($roads->controls()['roadSnow'], 100);
$roads->edit(['roadSnow' => 25], 0.);
$roads->edit(Weather::PRESETS['snow'], 0.);
equalWeather($roads->controls()['roadSnow'], 25);
equalWeather($roads->controls()['kind'], 'snow');

foreach([-1, 101, '50', NAN] as $invalid)
{
	try
	{
		$roads->edit(['roadSnow' => $invalid], 1.);
		throw new RuntimeException('Accepted a road snow limit of ' . json_encode($invalid));
	}
	catch(InvalidArgumentException)
	{
	}
}

// A state saved before the limit existed opens with no limit.
$earlier = $roads->snapshot(5.);
unset($earlier['controls']['roadSnow']);
equalWeather(Weather::valid($earlier), true);
$opened = new Weather;
$opened->restore($earlier, 0.);
equalWeather($opened->controls()['roadSnow'], 100);
$kept = new Weather;
$kept->restore($roads->snapshot(5.), 0.);
equalWeather($kept->controls()['roadSnow'], 25);

// Snow cover can be set outright. The weather goes on from it: snowfall adds to it and clear weather melts it.
$laid = new Weather;
$laid->cover(.25, 10.);
equalWeather($laid->ground(10.)[1], .25);
equalWeather($laid->ground(10.)[0], 0.);
$laid->edit(Weather::PRESETS['snow'], 10.);
equalWeather($laid->ground(25.)[1], .5);
$laid->cover(1., 25.);
equalWeather($laid->ground(25.)[1], 1.);
$laid->edit(Weather::PRESETS['clear'], 25.);
equalWeather($laid->ground(85.)[1], .5);
$restored = new Weather;
$restored->restore($laid->snapshot(85.), 85.);
equalWeather($restored->ground(85.)[1], .5);
$laid->cover(0., 85.);
equalWeather($laid->ground(85.)[1], 0.);

foreach([-.1, 1.1, NAN, INF] as $invalid)
{
	try
	{
		$laid->cover($invalid, 90.);
		throw new RuntimeException('Accepted a snow cover of ' . json_encode((string)$invalid));
	}
	catch(InvalidArgumentException)
	{
	}
}

// A held ground keeps its snow and wetness whatever the weather, and lets go again when released.
$held = new Weather;
equalWeather($held->controls()['hold'], 0);
$held->cover(.5, 0.);
$held->edit(['hold' => 1], 0.);
equalWeather($held->ground(600.)[1], .5);
$held->edit(Weather::PRESETS['rain'], 600.);
equalWeather($held->controls()['hold'], 1);
equalWeather($held->ground(900.), [0., .5]);
$held->edit(Weather::PRESETS['clear'] + ['hold' => 0], 900.);
equalWeather($held->ground(930.)[1], .25);
$before = $held->snapshot(930.);
unset($before['controls']['hold']);
equalWeather(Weather::valid($before), true);
$unheld = new Weather;
$unheld->restore($before, 930.);
equalWeather($unheld->controls()['hold'], 0);

echo "Weather accumulation, clearing, edits, translation, restore, the road snow limit, set cover and hold passed.\n";
