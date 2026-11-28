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
echo "Weather accumulation, clearing, edits, translation, restore and the road snow limit passed.\n";
