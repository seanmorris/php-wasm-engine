<?php
declare(strict_types=1);
/** Terrain appearance validation, defaults and uniform uploads. Real shader compilation belongs to gpu.test.mjs. */
require dirname(__DIR__, 3) . '/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use Phonics\Render\Program;
use TerrainPhonics\TerrainAppearance;

foreach(['GL_VERTEX_SHADER', 'GL_FRAGMENT_SHADER', 'GL_COMPILE_STATUS', 'GL_LINK_STATUS'] as $i => $name)
if(!defined($name)) define($name, $i + 1);

/** Uniform uploads by name: the GL function used and its arguments after the location. */
$GLOBALS['appearanceGl'] = ['names' => [], 'uploads' => []];

function glCreateProgram(): int { return 1; }

function glDeleteProgram(int $id): void {}

function glCreateShader(int $type): int { return $type; }

function glShaderSource(...$args): void {}

function glCompileShader(...$args): void {}

function glGetShaderiv(...$args): bool { return true; }

function glAttachShader(...$args): void {}

function glDeleteShader(...$args): void {}

function glLinkProgram(...$args): void {}

function glGetProgramiv(...$args): bool { return true; }

function glGetUniformLocation(int $program, string $name): int
{
	$names = &$GLOBALS['appearanceGl']['names'];
	$names[] = $name;

	return count($names) - 1;
}

/**
 * Record one uniform upload under the name its location was looked up with.
 * @param string $function GL function that made the upload.
 * @param int $location Location returned by glGetUniformLocation().
 * @param array $values Arguments after the location.
 * @return void Replaces any earlier record for the same uniform.
 */
function appearanceUpload(string $function, int $location, array $values): void
{
	$GLOBALS['appearanceGl']['uploads'][$GLOBALS['appearanceGl']['names'][$location]] = [$function, $values];
}

function glUniform1f(int $location, float ...$values): void { appearanceUpload('glUniform1f', $location, $values); }

function glUniform2f(int $location, float ...$values): void { appearanceUpload('glUniform2f', $location, $values); }

function glUniform3f(int $location, float ...$values): void { appearanceUpload('glUniform3f', $location, $values); }

function glUniform4f(int $location, float ...$values): void { appearanceUpload('glUniform4f', $location, $values); }

function glUniform3fv(int $location, int $count, array $values): void
{
	appearanceUpload('glUniform3fv', $location, [$count, $values]);
}

$checks = 0;

$expect = static function(bool $condition, string $message) use (&$checks): void {
	$checks++;

	if(!$condition) throw new RuntimeException($message);
};

$rejects = static function(array $options, string $name) use ($expect): void {
	try
	{
		new TerrainAppearance($options);
	}
	catch(InvalidArgumentException $error)
	{
		$expect(str_contains($error->getMessage(), $name), "rejection names $name: {$error->getMessage()}");

		return;
	}

	$expect(false, 'rejects ' . var_export($options, true));
};

$srgb = static fn(float $linear): float => $linear <= .0031308 ? 12.92 * $linear : 1.055 * $linear ** (1 / 2.4) - .055;
$close = static function(array $a, array $b, float $tolerance = 1e-12): bool {
	foreach($a as $i => $value) if(abs($value - $b[$i]) > $tolerance) return false;

	return count($a) === count($b);
};

// Literal colours and limits from terrain.frag before the appearance existed.
$old = [
	'grassColor' => [.055, .105, .026], 'soilColor' => [.22, .115, .052], 'rockColor' => [.24, .20, .16],
	'rockBandColor' => [.43, .30, .20], 'sandColor' => [.52, .43, .26], 'snowColor' => [.82, .88, .94],
	'roadColor' => [.13, .12, .105], 'canyonRustColor' => [.190, .067, .057], 'canyonShaleColor' => [.115, .064, .072],
	'canyonSandstoneColor' => [.280, .125, .092], 'canyonCapColor' => [.365, .244, .179],
];
$oldCanyon = [
	[.190, .067, .057], [.115, .064, .072], [.285, .196, .148], [.280, .125, .092], [.235, .082, .067],
	[.365, .244, .179], [.275, .163, .104], [.246, .133, .106],
];

$defaults = new TerrainAppearance;
$uniforms = $defaults->uniforms();

foreach($old as $name => $linear)
{
	$hex = TerrainAppearance::DEFAULTS[$name];

	foreach(str_split(substr($hex, 1), 2) as $i => $channel)
	$expect(
		abs(hexdec($channel) - 255 * $srgb($linear[$i])) <= .5
		, "$name $hex is the nearest 8-bit sRGB colour to the old linear constant"
	);

	$expect($close(TerrainAppearance::linear($hex), $linear, .0105), "$name converts back near the old constant");
}

$expect($uniforms['uGrassColor'] === TerrainAppearance::linear('#425b2d'), 'grass uploads the converted hex colour');

// The four chosen canyon colours carry their hex rounding. The shades derived from them reproduce the old ones.
foreach([2, 4, 6, 7] as $i)
$expect(
	$close(array_slice($uniforms['uCanyonColors[0]'], 3 * $i, 3), $oldCanyon[$i])
	, "derived canyon colour $i equals the old constant"
);

$chosen = [0 => 'canyonRustColor', 1 => 'canyonShaleColor', 3 => 'canyonSandstoneColor', 5 => 'canyonCapColor'];

foreach($chosen as $i => $name)
$expect(
	array_slice($uniforms['uCanyonColors[0]'], 3 * $i, 3)
		=== TerrainAppearance::linear(TerrainAppearance::DEFAULTS[$name])
	, "canyon colour $i comes from $name"
);

$expect($close($uniforms['uCanyonBounce'], [.11, .066, .048]), 'canyon bounce light equals the old constant');
$expect($uniforms['uBanding'] === [.12, 1.0, 1.0], 'default bands have the old phase per metre and profile');
$expect($uniforms['uSlopeBlend'] === [.08, .38, .25, .65], 'default slope blends are the old constants');
$expect($uniforms['uHeightBlend'] === [3.0, 18.0, -170.0, -30.0], 'default sand and alpine heights are the old ones');
$expect($uniforms['uSnowline'] === [340.0, 65.0], 'default snowline and transition are the old constants');
$expect($uniforms['uMacroVariation'] === [1.0], 'default variation keeps the old strength');

// The mountain palette uploads its six colours in the shader's order.
foreach(['forest', 'meadow', 'tundra', 'granite', 'stain', 'talus'] as $i => $name)
$expect(
	array_slice($uniforms['uMountainColors[0]'], 3 * $i, 3)
		=== TerrainAppearance::linear(TerrainAppearance::DEFAULTS[$name . 'Color'])
	, "mountain colour $i comes from {$name}Color"
);

$expect($uniforms['uMountainZones'] === [-190.0, -100.0], 'default treeline and tundra lie below the snowline');
$expect($uniforms['uMountainAmounts'] === [.5, .3, .5], 'default talus, stain and lichen amounts upload in order');
$expect($defaults->settings === TerrainAppearance::DEFAULTS, 'no options give the defaults');

// Every setting belongs to exactly one panel group, and every numeric setting has a range holding its default.
$grouped = array_merge(...array_values(TerrainAppearance::GROUPS));
sort($grouped);
$keys = array_keys(TerrainAppearance::DEFAULTS);
sort($keys);
$expect($grouped === $keys, 'groups list every setting once');

foreach(TerrainAppearance::DEFAULTS as $name => $value)
{
	if(is_string($value))
	{
		$expect(str_ends_with($name, 'Color') && !isset(TerrainAppearance::RANGES[$name]), "$name is a colour");
		continue;
	}

	[$min, $max] = TerrainAppearance::RANGES[$name];
	$expect(is_float($value) && $min <= $value && $value <= $max, "$name default lies inside its range");
}

// Each uniform is declared in the terrain shader with the type its values fill.
$source = file_get_contents(dirname(__DIR__) . '/shaders/terrain.frag');
preg_match_all('/^uniform (float|vec2|vec3|vec4) ([^;]+);/m', $source, $declarations, PREG_SET_ORDER);
$declared = [];

foreach($declarations as [, $type, $names])
foreach(explode(',', $names) as $declaration)
{
	preg_match('/^\s*(\w+)(?:\[(\d+)\])?/', $declaration, $match);
	$size = ['float' => 1, 'vec2' => 2, 'vec3' => 3, 'vec4' => 4][$type];
	$declared[isset($match[2]) ? $match[1] . '[0]' : $match[1]] = $size * (int)($match[2] ?? 1);
}

foreach($uniforms as $name => $values)
$expect(($declared[$name] ?? null) === count($values), "terrain.frag declares $name with the size it receives");

// Validation: colours must be six-digit hex strings and numbers must be finite integers or floats.
foreach([null, 4365869, '#fff', 'red', '#12345g', "#425b2d\n", ' #425b2d', ['#425b2d'], true] as $value)
$rejects(['snowColor' => $value], 'snowColor');

foreach(['5', '5.0', null, true, NAN, INF, -INF, [], new stdClass] as $value)
$rejects(['bandSpacing' => $value], 'bandSpacing');

$rejects(['grassColor' => '#425b2d', 'snowline' => '340'], 'snowline');
$upper = new TerrainAppearance(['sandColor' => '#C0FFEE', 'beachHeight' => 4]);
$expect($upper->settings['sandColor'] === '#c0ffee', 'hex colours are stored in lower case');
$expect($upper->settings['beachHeight'] === 4.0, 'integer settings are stored as floats');

$ignored = new TerrainAppearance(['waterColor' => 'not a colour', 'tool' => 'raise', 'quality' => '2']);
$expect($ignored->settings === TerrainAppearance::DEFAULTS, 'settings that belong to other systems are ignored');
$reordered = new TerrainAppearance(['snowline' => 200, 'grassColor' => '#000000']);
$expect(array_keys($reordered->settings) === array_keys($defaults->settings), 'settings keep the DEFAULTS order');

// Hex conversion follows the sRGB transfer function, including its linear toe.
$expect(TerrainAppearance::linear('#000000') === [0.0, 0.0, 0.0], 'black is zero');
$expect(TerrainAppearance::linear('#ffffff') === [1.0, 1.0, 1.0], 'white is one');
$expect($close(TerrainAppearance::linear('#808080'), array_fill(0, 3, .21586050011389926)), 'mid grey');
$expect($close(TerrainAppearance::linear('#0a0000'), [10 / 255 / 12.92, 0.0, 0.0]), 'dark values use the linear toe');
$expect(
	$close(TerrainAppearance::linear('#ff8000'), [1.0, .21586050011389926, 0.0])
	, 'channels are red, green, blue'
);

// linear() is public, so it checks its argument like the constructor does instead of returning a short or zero list.
$expect(TerrainAppearance::linear('#C0FFEE') === TerrainAppearance::linear('#c0ffee'), 'linear() accepts upper case');

foreach(['#abc', 'c0ffee', '#gggggg', '', "#c0ffee\n"] as $hex)
{
	try
	{
		TerrainAppearance::linear($hex);
	}
	catch(InvalidArgumentException $error)
	{
		$expect(str_contains($error->getMessage(), 'six-digit hex'), 'linear() explains ' . json_encode($hex));

		continue;
	}

	$expect(false, 'linear() rejects ' . json_encode($hex));
}

// Out-of-range values clamp to the documented range.
$clamped = [
	['bandSpacing', 1, 5.0], ['bandSpacing', 1e9, 1000.0], ['bandContrast', -1, 0.0], ['bandContrast', 2, 1.0],
	['bandSharpness', 0, 1.0], ['bandSharpness', 100, 8.0], ['rockSlopeStart', -1, 0.0], ['rockSlopeEnd', 5, 1.0],
	['beachHeight', -1e6, -100.0], ['beachHeight', 1e6, 500.0], ['beachWidth', 0, .5], ['beachWidth', 1e6, 500.0],
	['snowline', -1e6, -1000.0], ['snowline', 1e6, 5000.0], ['snowTransition', 0, 1.0],
	['snowTransition', 1e6, 1000.0], ['alpineStart', -1e6, -2000.0], ['alpineEnd', 10, 0.0],
	['snowSlopeStart', -1, 0.0], ['snowSlopeEnd', 2, 1.0], ['macroVariation', -1, 0.0], ['macroVariation', 9, 3.0],

	['treeline', -1e6, -2000.0], ['treeline', 5, -1.0], ['tundraStart', 5, 0.0], ['talusAmount', -1, 0.0],
	['talusAmount', 2, 1.0], ['stainAmount', -1, 0.0], ['stainAmount', 2, 1.0], ['lichenAmount', -1, 0.0],
	['lichenAmount', 2, 1.0],
];

foreach($clamped as [$name, $value, $result])
$expect((new TerrainAppearance([$name => $value]))->settings[$name] === $result, "$name $value clamps to $result");

// Each blend keeps its start below its end.
$order = static fn(array $options): array => array_intersect_key(
	(new TerrainAppearance($options))->settings
	, array_flip(array_keys($options))
);

$expect(
	$order(['rockSlopeStart' => .5, 'rockSlopeEnd' => .2]) === ['rockSlopeStart' => .5, 'rockSlopeEnd' => .51]
	, 'a start above its end pushes the end up'
);

$expect(
	$order(['rockSlopeStart' => .08, 'rockSlopeEnd' => .02]) === ['rockSlopeStart' => .08, 'rockSlopeEnd' => .09]
	, 'an end below its start stops just above it'
);

$expect(
	$order(['snowSlopeStart' => 1, 'snowSlopeEnd' => 1]) === ['snowSlopeStart' => .99, 'snowSlopeEnd' => 1.0]
	, 'a start at the top of the range moves down below the end'
);

$expect(
	$order(['alpineStart' => -30, 'alpineEnd' => -30]) === ['alpineStart' => -30.0, 'alpineEnd' => -29.0]
	, 'equal alpine heights separate by a metre'
);

$expect(
	$order(['alpineStart' => 0, 'alpineEnd' => -500]) === ['alpineStart' => -1.0, 'alpineEnd' => 0.0]
	, 'an alpine band at the snowline ends there'
);

$expect(
	$order(['treeline' => -80, 'tundraStart' => -150]) === ['treeline' => -80.0, 'tundraStart' => -79.0]
	, 'tundra starting below the treeline moves up to a metre above it'
);

// A slope slider moves in steps of the gap. In binary, 18 of the 99 pairs one step apart differ by a little less than
// .01, and 15 of the sums start + .01 miss the next step by one unit in the last place.
foreach(range(0, 98) as $step)
{
	$start = $step / 100.0;
	$end = ($step + 1) / 100.0;

	$expect(
		$order(['rockSlopeStart' => $start, 'rockSlopeEnd' => $end])
			=== ['rockSlopeStart' => $start, 'rockSlopeEnd' => $end]
		, "slopes $start and $end one step apart stay as set"
	);

	$expect(
		$order(['snowSlopeStart' => $start, 'snowSlopeEnd' => 0])
			=== ['snowSlopeStart' => $start, 'snowSlopeEnd' => $end]
		, "an end pushed up by a start of $start lands on $end"
	);
}

mt_srand(17);

for($trial = 0; $trial < 300; $trial++)
{
	$options = [];

	foreach(TerrainAppearance::RANGES as $name => [$min, $max])
	if(mt_rand(0, 1)) $options[$name] = $min - 50 + ($max - $min + 100) * mt_rand() / mt_getrandmax();

	$settings = (new TerrainAppearance($options))->settings;

	foreach(TerrainAppearance::RANGES as $name => [$min, $max])
	$expect($settings[$name] >= $min && $settings[$name] <= $max, "random $name stays in range");

	foreach(TerrainAppearance::ORDER as [$start, $end, $gap])
	$expect($settings[$end] - $settings[$start] >= $gap - 1e-9, "random $start stays below $end");

	// An application saves settings as JSON and loads them back. Loading must not move them again.
	$saved = json_decode(json_encode($settings, JSON_THROW_ON_ERROR), true, 4, JSON_THROW_ON_ERROR);
	$expect((new TerrainAppearance($saved))->settings === $settings, 'random settings load back unchanged');
}

// bind() uploads every uniform with the function for its type.
$program = new Program('', '');
$defaults->bind($program);
$uploads = $GLOBALS['appearanceGl']['uploads'];
$expect(array_keys($uploads) === array_keys($uniforms), 'bind uploads every appearance uniform');

foreach($uniforms as $name => $values)
{
	$function = [1 => 'glUniform1f', 2 => 'glUniform2f', 3 => 'glUniform3f', 4 => 'glUniform4f'][count($values)]
		?? 'glUniform3fv';
	$sent = $function === 'glUniform3fv' ? [count($values) / 3, $values] : $values;
	$expect($uploads[$name] === [$function, $sent], "bind uploads $name with $function");
}

$expect($uploads['uCanyonColors[0]'][1][0] === 8, 'the canyon palette uploads as eight colours');
$expect($uploads['uMountainColors[0]'][1][0] === 6, 'the mountain palette uploads as six colours');

// A changed appearance changes what bind() uploads, and the derived canyon shades follow their sources.
$changed = new TerrainAppearance([
	'snowColor' => '#ff0000', 'bandSpacing' => 100, 'bandContrast' => .5, 'bandSharpness' => 4,
	'rockSlopeStart' => .2, 'rockSlopeEnd' => .25, 'beachHeight' => -2, 'beachWidth' => 6, 'snowline' => 200,
	'snowTransition' => 20, 'alpineStart' => -60, 'alpineEnd' => -10, 'macroVariation' => 2,
	'canyonCapColor' => '#ffffff', 'canyonRustColor' => '#000000', 'stainColor' => '#00ff00', 'treeline' => -300,
	'tundraStart' => -20, 'talusAmount' => .25, 'stainAmount' => 1, 'lichenAmount' => 0,
]);
$changed->bind($program);
$after = $GLOBALS['appearanceGl']['uploads'];

// The grass colour did not change, so it uploads the same values as before.
$expected = [
	'uSnowColor' => ['glUniform3f', [1.0, 0.0, 0.0]], 'uBanding' => ['glUniform3f', [2 * M_PI / 100, .5, 4.0]],
	'uSlopeBlend' => ['glUniform4f', [.2, .25, .25, .65]], 'uHeightBlend' => ['glUniform4f', [-2.0, 4.0, -60.0, -10.0]],
	'uSnowline' => ['glUniform2f', [200.0, 20.0]], 'uMacroVariation' => ['glUniform1f', [2.0]],
	'uMountainZones' => ['glUniform2f', [-300.0, -20.0]], 'uMountainAmounts' => ['glUniform3f', [.25, 1.0, 0.0]],
	'uGrassColor' => $uploads['uGrassColor'],
];

foreach($expected as $name => $upload) $expect($after[$name] === $upload, "the changed appearance uploads $name");

$mountain = $after['uMountainColors[0]'][1];
$expect($mountain[0] === 6 && array_slice($mountain[1], 12, 3) === [0.0, 1.0, 0.0], 'a changed stain colour uploads');
$expect(
	array_slice($mountain[1], 0, 12) === array_slice($uploads['uMountainColors[0]'][1][1], 0, 12)
	, 'the other mountain colours stay as they were'
);

$palette = $after['uCanyonColors[0]'][1][1];
$cap = TerrainAppearance::linear(TerrainAppearance::DEFAULTS['canyonCapColor']);
$expect(
	$close(array_slice($palette, 6, 3), [.285 / $cap[0], .196 / $cap[1], .148 / $cap[2]])
	, 'ledges keep their ratio to a white cap rock'
);

$expect(array_slice($palette, 0, 3) === [0.0, 0.0, 0.0], 'a black rust uploads');
$expect(array_slice($palette, 12, 3) === [0.0, 0.0, 0.0], 'red beds follow a black rust');
$expect(
	array_slice($palette, 18, 6) !== array_slice($uniforms['uCanyonColors[0]'], 18, 6)
	&& $after['uCanyonBounce'] !== $uploads['uCanyonBounce']
	, 'dust, distant beds and bounce light follow the bed average'
);

// Derived shades are albedos like the hex colours. Red beds are 1.16 to 1.23 times the rust, channel by channel, and
// dust up to 1.19 times the bed average, so a light rust or palette must not push them past 1.
$white = ['canyonRustColor' => '#ffffff', 'canyonShaleColor' => '#ffffff', 'canyonSandstoneColor' => '#ffffff'];

foreach([['canyonRustColor' => '#ffffff'], $white + ['canyonCapColor' => '#ffffff']] as $options)
{
	$light = (new TerrainAppearance($options))->uniforms();

	$expect(
		max($light['uCanyonColors[0]']) <= 1.0 && min($light['uCanyonColors[0]']) >= 0.0
		, 'canyon shades stay between 0 and 1 for ' . json_encode($options)
	);
}

$expect(
	array_slice((new TerrainAppearance(['canyonRustColor' => '#ffffff']))->uniforms()['uCanyonColors[0]'], 12, 3)
		=== [1.0, 1.0, 1.0]
	, 'red beds under a white rust are white'
);

echo "Terrain appearance: $checks checks passed.\n";
