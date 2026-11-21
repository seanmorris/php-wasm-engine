<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\TerrainSurface;
use TerrainPhonics\Water;
use TerrainPhonics\Watercraft;

$checks = 0;

$expect = static function(bool $condition, string $message) use (&$checks): void {
	$checks++;

	if(!$condition) throw new RuntimeException($message);
};

$flat = new Water(2);

$expect($flat->sample(4, -2, 100) === [
	'height' => 2.0
	, 'normal' => [-0.0,1.0,-0.0]
	, 'velocity' => 0.0
	, 'dx' => 0.0
	, 'dz' => 0.0
], 'empty wave list remains an exact flat plane'
);

$water = new Water(1, [
	['amplitude' => .3, 'wavelength' => 14, 'direction' => [3,4], 'phase' => .7]
	, ['amplitude' => .08, 'wavelength' => 3, 'direction' => [-1,.2], 'angularSpeed' => 1.7]
]);

$water->wake(2, -1, .5, 0);
$epsilon = 1e-4;

foreach([[2,-1,.7],[5,1,1.2],[-4,8,3.5],[0,0,8.1]] as [$x,$z,$time])
{
	$sample = $water->sample($x, $z, $time);
	$dx = ($water->sample($x + $epsilon, $z, $time)['height']
		- $water->sample($x - $epsilon, $z, $time)['height']
	) / (2 * $epsilon);

	$dz = ($water->sample($x, $z + $epsilon, $time)['height']
		- $water->sample($x, $z - $epsilon, $time)['height']
	) / (2 * $epsilon);

	$dt = ($water->sample($x, $z, $time + $epsilon)['height']
		- $water->sample($x, $z, $time - $epsilon)['height']
	) / (2 * $epsilon);

	$expect(abs($sample['dx'] - $dx) < 1e-6, 'analytic X gradient matches finite differences');
	$expect(abs($sample['dz'] - $dz) < 1e-6, 'analytic Z gradient matches finite differences');
	$expect(abs($sample['velocity'] - $dt) < 1e-6, 'analytic water velocity matches time derivative');

	$expect(
		abs(array_sum(array_map(static fn($v) => $v * $v, $sample['normal'])) - 1) < 1e-12
		, 'water normal is normalized'
	);
}

$water->update(1.2);
$uniforms = $water->uniforms();

$expect(
	$uniforms['waveVectors'][0][0] === .6 && $uniforms['waveVectors'][0][1] === .8
	, 'GPU receives normalized direction shared with CPU'
);

$expect(
	$uniforms['waveDynamics'][0][0] === sqrt(9.81 * $uniforms['waveVectors'][0][2])
	, 'GPU receives the same physical dispersion frequency'
);

$before = $water->sample(1, 2, 5);
$water->update(4);
$expect($water->sample(1, 2, 5) === $before, 'sample time is independent of last renderer update');
$revision = $water->revision();
$water->setLevel(2);
$expect($water->revision() === $revision + 1, 'water-level edits expose a revision');

$expect(
	abs($water->sample(1, 2, 5)['height'] - $before['height'] - 1) < 1e-12
	, 'water-level edit preserves wave shape'
);

$original = $water->uniforms();
$sample = $water->sample(2, 1, 2);
$water->setWaveScale(0);
$wakeOnly = $water->sample(2, 1, 2);
$expect($water->uniforms()['waveCount'] === 0 && $water->uniforms()['wakeCount'] === 1, 'calm water keeps the local wake');
$expect($water->heightRange() === [1.5,2.5], 'calm bounds still enclose wakes');
$water->setWaveScale(3);
$rough = $water->sample(2, 1, 2);
foreach(['height','dx','dz','velocity'] as $field)
$expect(abs($rough[$field] - $wakeOnly[$field] - 3 * ($sample[$field] - $wakeOnly[$field])) < 1e-10,
	"rough water scales the directional $field independently of wakes");
$expect($water->uniforms()['waveVectors'][0][3] === 3 * $original['waveVectors'][0][3], 'renderer receives scaled amplitudes');
$expect(abs($water->heightRange()[1] - (2.5 + 3 * (.3 + .08))) < 1e-12, 'rough bounds include scaled waves and wakes');
$revision = $water->revision();
$water->setWaveScale(3);
$expect($water->revision() === $revision, 'reapplying a wave scale leaves the revision stable');
$water->setWaveScale(1);
$expect($water->uniforms() === $original && $water->sample(2, 1, 2) === $sample, 'restoring the swell recovers its exact phase and amplitude');

for($i = 0; $i < 100; $i++) $water->wake($i, 0, .1, 5);

$expect($water->uniforms()['wakeCount'] === Water::MAX_WAKES, 'wake storage stays bounded');
$water->update(20);
$expect($water->uniforms()['wakeCount'] === 0, 'old wake packets expire');

$terrain = new class implements TerrainSurface {
	public float $base = -20;
	public bool $shore = false;
	public float $limit = 1000;
	public int $version = 0;

	public function height(float $x, float $z): float
	{
		if(abs($x) > $this->limit || abs($z) > $this->limit) throw new OutOfBoundsException('World boundary');

		return $this->shore ? max($this->base, $z - 12) : $this->base;
	}

	public function sample(float $x, float $z): array
	{
		return ['height' => $this->height($x, $z), 'dx' => 0, 'dz' => 0, 'normal' => [0,1,0], 'material' => null];
	}

	public function revision(): int { return $this->version; }
};

$lake = new Water;
$craft = new Watercraft(0, 0, 0, 2);

for($i = 0; $i < 600; $i++) $craft->step(1 / 120, 0, 0, $lake, $terrain, $i / 120);

$state = $craft->state();

$expect(
	abs($state['y'] - .232) < .01 && abs($state['velocity'][1]) < .01
	, 'four buoyancy probes settle a dropped hull at its waterline'
);

$expect(abs($state['pitch']) < 1e-8 && abs($state['roll']) < 1e-8, 'symmetric flat-water hull stays level');
$craft->spawn(0, 0, 0, $lake, $terrain, 0);

for($i = 0; $i < 300; $i++) $craft->step(1 / 60, 1, .3, $lake, $terrain, $i / 60);

$state = $craft->state();
$expect($state['speed'] > 5 && $state['yaw'] < -.2 && $state['x'] < 0,
	'right steering turns a forward-moving +Z hull toward starboard (-X)');
$expect(abs($state['pitch']) < .55 && abs($state['roll']) < .65, 'planing hull remains stable while turning');
$expect($lake->uniforms()['wakeCount'] <= Water::MAX_WAKES, 'moving craft emits bounded wakes');

$lake = new Water;
$craft->spawn(0, 0, 0, $lake, $terrain, 0);
for($i = 0; $i < 600; $i++)
{
	$craft->step(1 / 60, 1, 0, $lake, $terrain, $i / 60);
	if($i === 74) $expect($craft->state()['speed'] * 3.6 > 50, 'full throttle clears 50 km/h within 1.25 seconds');
}
$cruiseMph = $craft->state()['speed'] / .44704;
$expect($cruiseMph >= 90 && $cruiseMph < 120, 'the hull reaches a stable planing speed between 90 and 120 mph on open water');

foreach([[-1, 1], [1, -1], [-1, -1]] as [$steer, $throttle])
{
	$lake = new Water;
	$craft->spawn(0, 0, 0, $lake, $terrain, 0);
	for($i = 0; $i < 60; $i++) $craft->step(1 / 60, $throttle, $steer, $lake, $terrain, $i / 60);
	$state = $craft->state();
	$expect($state['yaw'] * $steer * $throttle < -.1 && $state['z'] * $throttle > 0,
		"bow turns in the expected direction at throttle $throttle and steering $steer");
}

$terrain->shore = true;
$lake = new Water;
$craft->spawn(0, -300, 0, $lake, $terrain, 0);
$contacts = 0;
$approachSpeed = 0.;

for($i = 0; $i < 600; $i++)
{
	$craft->step(1 / 60, 1, 0, $lake, $terrain, $i / 60);
	$state = $craft->state();
	$approachSpeed = max($approachSpeed, $state['speed']);
	$contacts += $state['contacts'];
}

$expect($approachSpeed / .44704 >= 90 && $contacts > 0 && $craft->state()['z'] < 12,
	'full-resolution shore samples stop a 90 mph approach before land');
$terrain->shore = false;
$terrain->base = 3;
$terrain->version++;
$craft->step(1 / 60, 0, 0, $lake, $terrain, 10);

$expect(
	$craft->state()['y'] >= 3.32 && $craft->state()['terrainRevision'] === 1
	, 'committed terrain edit beneath a craft immediately updates collision support'
);

$terrain->base = -20;
$terrain->limit = 10;
$craft->spawn(0, 8, 0, $lake, $terrain, 0);

for($i = 0; $i < 300; $i++) $craft->step(1 / 60, 1, .15, $lake, $terrain, $i / 60);

$expect(abs($craft->state()['x']) < 10 && abs($craft->state()['z']) < 10, 'world footprint rejects outgoing motion');
$a = new Watercraft;
$b = new Watercraft;
$terrain->limit = 1000;
$a->step(1 / 30, 1, .2, new Water, $terrain, 0);
$b->step(1 / 60, 1, .2, new Water, $terrain, 0);
$b->step(1 / 60, 1, .2, new Water, $terrain, 1 / 60);
$expect(abs($a->state()['z'] - $b->state()['z']) < 1e-12, 'fixed substeps agree across frame partitions');
echo "Water and watercraft: {$checks} checks passed\n";
