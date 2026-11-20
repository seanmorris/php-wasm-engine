<?php
declare(strict_types=1);
/** Headless lifecycle/geometry tests; real shader compilation belongs to the browser checks. */
require dirname(__DIR__, 3) . '/autoload.php';
require dirname(__DIR__) . '/autoload.php';

foreach([
	'GL_VERTEX_SHADER'
	, 'GL_FRAGMENT_SHADER'
	, 'GL_COMPILE_STATUS'
	, 'GL_LINK_STATUS'
	, 'GL_TEXTURE_2D'
	, 'GL_TEXTURE_MIN_FILTER'
	, 'GL_TEXTURE_MAG_FILTER'
	, 'GL_NEAREST'
	, 'GL_TEXTURE_WRAP_S'
	, 'GL_TEXTURE_WRAP_T'
	, 'GL_CLAMP_TO_EDGE'
	, 'GL_RGBA8'
	, 'GL_RGBA'
	, 'GL_UNSIGNED_BYTE','GL_UNSIGNED_SHORT'
	, 'GL_R32F'
	, 'GL_RED'
	, 'GL_FLOAT'
	, 'GL_ARRAY_BUFFER'
	, 'GL_ELEMENT_ARRAY_BUFFER'
	, 'GL_STATIC_DRAW'
	, 'GL_TRIANGLES', 'GL_POINTS', 'GL_ZERO'
	, 'GL_BLEND', 'GL_SRC_ALPHA', 'GL_ONE_MINUS_SRC_ALPHA', 'GL_ONE'
	, 'GL_CULL_FACE'
	, 'GL_DEPTH_TEST', 'GL_LEQUAL', 'GL_LESS'
	, 'GL_TEXTURE0'
	, 'GL_TEXTURE1'
	, 'GL_FRAMEBUFFER_BINDING'
	, 'GL_FRAMEBUFFER'
	, 'GL_COLOR_ATTACHMENT0'
	, 'GL_FRAMEBUFFER_COMPLETE'
	, 'GL_SCISSOR_TEST'
	, 'GL_LINEAR'
] as $i => $name) if(!defined($name)) define($name, $i + 1);
$GLOBALS['terrainGl'] = [
	'next' => 1
	, 'buffers' => []
	, 'vaos' => []
	, 'textures' => []
	, 'programs' => []
	, 'framebuffers' => []
	, 'renderbuffers' => []
	, 'framebuffer' => 0
	, 'bound' => []
	, 'draws' => 0
	, 'program' => 0
	, 'uniformNames' => []
	, 'uniforms' => []
	, 'uploads' => []
];

function terrainGlNames(string $kind, int $count, mixed &$names): void
{
	$names = [];

	for($i = 0; $i < $count; $i++)
	{
		$id = $GLOBALS['terrainGl']['next']++;
		$GLOBALS['terrainGl'][$kind][$id] = true;
		$names[] = $id;
	} }

function terrainGlDelete(string $kind, array $names): void
{
	foreach($names as $id) unset($GLOBALS['terrainGl'][$kind][$id]);
}

function glGenBuffers(int $n, array &$ids): void{terrainGlNames('buffers', $n, $ids);}

function glDeleteBuffers(int $n, array $ids): void{terrainGlDelete('buffers', $ids);}

function glGenVertexArrays(int $n, array &$ids): void{terrainGlNames('vaos', $n, $ids);}

function glDeleteVertexArrays(int $n, array $ids): void{terrainGlDelete('vaos', $ids);}

function glGenTextures(int $n, mixed &$ids): void{terrainGlNames('textures', $n, $ids);}

function glDeleteTextures(int $n, array $ids): void{terrainGlDelete('textures', $ids);}

function glGenFramebuffers(int $n, mixed &$ids): void{terrainGlNames('framebuffers', $n, $ids);}

function glDeleteFramebuffers(int $n, array $ids): void{terrainGlDelete('framebuffers', $ids);}

function glGenRenderbuffers(int $n, mixed &$ids): void{terrainGlNames('renderbuffers', $n, $ids);}

function glDeleteRenderbuffers(int $n, array $ids): void{terrainGlDelete('renderbuffers', $ids);}

function glGetIntegerv(int $name): int{return $GLOBALS['terrainGl']['framebuffer'];}

function glBindFramebuffer(int $target, int $id): void{$GLOBALS['terrainGl']['framebuffer'] = $id;}

function glFramebufferTexture2D(...$args): void{}

function glCheckFramebufferStatus(...$args): int{return GL_FRAMEBUFFER_COMPLETE;}

function glBindRenderbuffer(...$args): void{}

function glRenderbufferStorage(...$args): void{}

function glFramebufferRenderbuffer(...$args): void{}

function glViewport(...$args): void{}

function glCreateProgram():int
{
	$ids = [];
	terrainGlNames('programs', 1, $ids);

	return $ids[0];
}

function glDeleteProgram(int $id): void{terrainGlDelete('programs', [$id]);}

function glCreateShader(int $type): int{return $GLOBALS['terrainGl']['next']++;}

function glShaderSource(...$args): void{}

function glCompileShader(...$args): void{}

function glGetShaderiv(...$args): bool{return true;}

function glAttachShader(...$args): void{}

function glDeleteShader(...$args): void{}

function glLinkProgram(...$args): void{}

function glGetProgramiv(...$args): bool{return true;}

function glUseProgram(int $id): void{$GLOBALS['terrainGl']['program'] = $id;}

function glGetUniformLocation(int $program, string $name): int
{
	$GLOBALS['terrainGl']['uniformNames'][] = $name;

	return count($GLOBALS['terrainGl']['uniformNames']) - 1;
}

/**
 * Record a float uniform upload by program and name, with the number of uploads so far.
 * @param int $location Location returned by glGetUniformLocation().
 * @param array $values Arguments after the location.
 * @return void Keeps the latest values for the program in use.
 */
function terrainGlUniform(int $location, array $values): void
{
	$gl = &$GLOBALS['terrainGl'];
	$name = $gl['uniformNames'][$location];
	$gl['uniforms'][$gl['program']][$name] = $values;
	$gl['uploads'][$gl['program']][$name] = ($gl['uploads'][$gl['program']][$name] ?? 0) + 1;
}

function glUniform1i(...$args): void{if(isset($GLOBALS['terrainGlCalls'])) $GLOBALS['terrainGlCalls'][] = $args;}

function glUniform1f(int $location, float ...$values): void{terrainGlUniform($location, $values);}

function glUniform2f(int $location, float ...$values): void{terrainGlUniform($location, $values);}

function glUniform3f(int $location, float ...$values): void{terrainGlUniform($location, $values);}

function glUniform4f(int $location, float ...$values): void{terrainGlUniform($location, $values);}

function glUniform3fv(int $location, int $count, array $values): void{terrainGlUniform($location, [$count, $values]);}
function glUniform2fv(...$args): void{}
function glUniform4fv(...$args): void{if(isset($GLOBALS['terrainGlCalls'])) $GLOBALS['terrainGlCalls'][] = $args;}

function glUniformMatrix4fv(...$args): void{}

function glActiveTexture(...$args): void{}

function glBindVertexArray(...$args): void{}

function glBindBuffer(...$args): void{}

function glBufferData(...$args): void{}

function glEnableVertexAttribArray(...$args): void{}

function glVertexAttribPointer(...$args): void{}

function glBindTexture(...$args): void{}

function glTexParameteri(...$args): void{}

function glTexImage2D(...$args): void{}

function glDisable(...$args): void{}

function glEnable(...$args): void{}

function glBlendFuncSeparate(...$args): void{}

function glDepthFunc(int $function): void {}

function glDepthMask(...$args): void{}

function glDrawBuffers(...$args): void{}
/**
 * Count a draw, and record the program in use while a test collects them.
 * @return void Updates the draw count and terrainGlDraws.
 */
function terrainGlDraw(): void
{
	$GLOBALS['terrainGl']['draws']++;

	if(isset($GLOBALS['terrainGlDraws'])) $GLOBALS['terrainGlDraws'][] = $GLOBALS['terrainGl']['program'];
}

function glDrawElements(...$args): void{terrainGlDraw();}
function glDrawArrays(...$args): void{terrainGlDraw();}

use TerrainPhonics\Atmosphere;
use TerrainPhonics\BrowserPatchBuilder;
use TerrainPhonics\Camera;
use TerrainPhonics\GpuPatch;
use TerrainPhonics\Landscape;
use TerrainPhonics\Lod;
use TerrainPhonics\Scene;
use TerrainPhonics\TerrainAppearance;
use TerrainPhonics\Water;

$checks = 0;

function renderCheck(bool $condition, string $message): void
{
	global $checks;
	$checks++;

	if(!$condition)throw new RuntimeException($message);
}

function renderGet(object $object, string $name): mixed
{
	return (new ReflectionProperty($object, $name))->getValue($object);
}

function renderSet(object $object, string $name, mixed $value): void
{
	(new ReflectionProperty($object, $name))->setValue($object, $value);
}

function renderCall(object $object, string $name, mixed ...$args): mixed
{
	return (new ReflectionMethod($object, $name))->invokeArgs($object, $args);
}

function renderCover(array $nodes, float $extent, bool $balanced = true): void
{
	$area = 0;

	foreach($nodes as $i => $a)
	{
		$area += $a['size'] ** 2;

		renderCheck(
			min($a['x'], $a['z']) >= -$extent / 2 && max($a['x'], $a['z']) + $a['size'] <= $extent / 2
			, 'patch stays inside bounded world'
		);

		foreach(array_slice($nodes, $i + 1) as $b)
		{
			$dx = min($a['x'] + $a['size'], $b['x'] + $b['size']) - max($a['x'], $b['x']);
			$dz = min($a['z'] + $a['size'], $b['z'] + $b['size']) - max($a['z'], $b['z']);
			renderCheck(!($dx > 0 && $dz > 0), 'visible coverage has no overlap');

			if($balanced && $dx >= 0 && $dz >= 0)
			renderCheck(abs($a['level'] - $b['level']) <= 1, 'edges and corners retain 2:1 balance');
		}
	}

	renderCheck(abs($area - $extent * $extent) < 1e-6, 'visible coverage contains no holes');
}

/**
 * Advance a scene and count the selections that update ran.
 * @param Scene $scene Scene to advance.
 * @param float $seconds Elapsed display seconds.
 * @param Camera $camera Viewing camera.
 * @return int Selections run by this update.
 */
function renderSelections(Scene $scene, float $seconds, Camera $camera): int
{
	$before = renderGet($scene, 'selections');
	$scene->update($seconds, $camera);

	return renderGet($scene, 'selections') - $before;
}

/**
 * List a scene's drawn cover in a stable order.
 * @param Scene $scene Scene to inspect.
 * @return array Sorted quadtree keys of the drawn patches.
 */
function renderKeys(Scene $scene): array
{
	$keys = array_column(renderGet($scene, 'draw'), 'key');
	sort($keys);

	return $keys;
}

/**
 * Find the quadtree depth of the drawn patch below the camera.
 * @param Scene $scene Scene to inspect.
 * @param Camera $camera Viewing camera.
 * @return int Depth of the patch under the eye, or minus one when none is drawn there.
 */
function renderLevelUnder(Scene $scene, Camera $camera): int
{
	[$x, , $z] = $camera->position;

	foreach(renderGet($scene, 'draw') as $node)
	if($x >= $node['x'] && $x < $node['x'] + $node['size'] && $z >= $node['z'] && $z < $node['z'] + $node['size'])
	return $node['level'];

	return -1;
}

// Every topology must cover the whole square once, with exactly the endpoints
// the neighboring patch draws. A collinear triangle can still cut a vertical
// sliver into a folded surface, so only repeated-index degenerates are allowed.
$cells = 4; $stride = $cells + 1;
$regular = GpuPatch::indices($cells);
$patterns = GpuPatch::stitchPatterns($cells, $regular);
renderCheck(strlen($patterns) === 16*strlen($regular), 'each edge mask addresses one complete index stream');
for($mask = 0; $mask < 16; $mask++)
{
	$indices = array_values(unpack('v*', substr($patterns, $mask*strlen($regular), strlen($regular))));
	$edges = []; $area = 0;
	foreach(array_chunk($indices, 3) as [$a,$b,$c])
	{
		if($a === $b || $a === $c || $b === $c) continue;
		$twiceArea = (intdiv($b,$stride)-intdiv($a,$stride))*($c%$stride-$a%$stride)
			- ($b%$stride-$a%$stride)*(intdiv($c,$stride)-intdiv($a,$stride));
		renderCheck($twiceArea > 0, 'stitched triangles retain winding at corners');
		$area += $twiceArea;
		foreach([[$a,$b],[$b,$c],[$c,$a]] as [$i,$j])
		{
			$key = min($i,$j) . ':' . max($i,$j);
			$edges[$key] = ($edges[$key] ?? 0) + 1;
		}
	}
	renderCheck($area === 2*$cells*$cells, 'stitched grid covers its entire footprint');
	foreach([[0,$stride],[$cells,$stride],[0,1],[$cells*$stride,1]] as $side => [$start,$along])
	{
		$step = ($mask & (1 << $side)) ? 2 : 1;
		for($pos = 0; $pos < $cells; $pos += $step)
		{
			$a = $start + $along*$pos;
			$key = $a . ':' . ($a+$along*$step);
			renderCheck(($edges[$key] ?? 0) === 1, 'perimeter matches neighboring edge tessellation');
			unset($edges[$key]);
		}
	}
	foreach($edges as $count) renderCheck($count === 2, 'interior grid edges have exactly two incident triangles');
}

$lod = new Lod(256, 1);
$root = $lod->node();
$selection = $lod->select([0,10,0], []);

renderCheck(
	$selection['draw'] === [] && count($selection['request']) === 1 && $selection['request'][0]['key'] === '0:0:0'
	, 'missing world requests only root'
);

$resident = ['0:0:0' => $root];
$selection = $lod->select([0,10,0], $resident);
renderCover($selection['draw'], 256);
renderCheck(count($selection['request']) === 4, 'parent remains until all siblings are ready');

foreach(array_slice($selection['request'], 0, 3) as $node)$resident[$node['key']] = $node;
$selection = $lod->select([0,10,0], $resident);

renderCheck(
	count($selection['draw']) === 1 && count($selection['request']) === 1
	, 'three children cannot replace complete parent'
);

$resident[$selection['request'][0]['key']] = $selection['request'][0];
$selection = $lod->select([0,10,0], $resident);
renderCover($selection['draw'], 256);
$flatError = ['0:0:0' => $root + ['geometricError' => .01]];
$curvedError = ['0:0:0' => $root + ['geometricError' => 20.0]];

renderCheck(
	$lod->select([0,1000,0], $flatError)['request'] === []
	, 'small distant projected error does not force refinement'
);

renderCheck(
	count($lod->select([0,1000,0], $curvedError)['request']) === 4
	, 'large projected geometric error refines beyond the distance-only threshold'
);

$view = new Camera;
$view->position = [30,24,15];
$view->pitch = 0;
foreach([[1920,1080],[640,900]] as [$width,$height])
{
	$planes = $view->clipPlanes($width,$height);
	$front = ['x' => 27,'z' => -30,'size' => 8];
	renderCheck(Camera::inView($planes,$view->position,$front,20,30), 'box in front of the camera survives culling');
	$front['z'] = 40;
	renderCheck(!Camera::inView($planes,$view->position,$front,20,30), 'box behind the camera is culled');
	$front['z'] = 12;
	renderCheck(Camera::inView($planes,$view->position,$front,20,30), 'box crossing the near plane remains visible');
}

// Given clip planes, the selector tests padded bounds itself. It must refine exactly the nodes a predicate
// allows when that predicate pads the node by its own size and asks Camera::inView().
$detailLod = new Lod(1024, 1);
$detailPatches = [];
$growing = [$detailLod->node()];

while($growing !== [])
{
	$node = array_pop($growing);
	$detailPatches[$node['key']] = $node + [
		'centerHeight' => 3.0 * $node['ix'] - 2.0 * $node['iz']
		, 'geometricError' => .25 * (1 + ($node['ix'] + $node['iz']) % 3)
		, 'minY' => -6.0 - $node['level']
		, 'maxY' => 9.0 + $node['ix'] % 4
	];

	if($node['level'] < 4) array_push($growing, ...$detailLod->children($node));
}

/**
 * Build the padded-view predicate for one set of planes and one extra height range.
 * @param array $planes Clip planes relative to the eye.
 * @param array $eye Camera world position.
 * @param float $low Lowest height the padded bounds must reach.
 * @param float $high Highest height the padded bounds must reach.
 * @param int $refused Counts the nodes the predicate keeps coarse.
 * @return Closure Predicate for Lod::select().
 */
$paddedView = static function(array $planes, array $eye, float $low, float $high, int &$refused): Closure {

	/**
	 * Test one node's padded bounds against the planes.
	 * @param array $node Quadtree node to consider refining.
	 * @param array $patch Resident mesh carrying its vertical bounds.
	 * @return bool Whether the padded bounds can intersect the view.
	 */
	$predicate = static function(array $node, array $patch) use ($planes, $eye, $low, $high, &$refused): bool {
		$margin = $node['size'];
		$node['x'] -= $margin;
		$node['z'] -= $margin;
		$node['size'] += 2 * $margin;

		$visible = Camera::inView(
			$planes
			, $eye
			, $node
			, min($patch['minY'], $low) - $margin
			, max($patch['maxY'], $high) + $margin
		);

		if(!$visible) $refused++;

		return $visible;
	};

	return $predicate;
};

/**
 * Select the plain way: a depth-first walk that asks importance() about every resident node.
 * @param Lod $lod Selector whose rules the walk follows.
 * @param array $patches Resident patches keyed by node key.
 * @param array $eye Camera world position.
 * @param float $quality Detail multiplier.
 * @param float $focal Vertical focal length in pixels.
 * @return array Lists named draw, request and refine, as Lod::select() returns them.
 */
$plainWalk = static function(Lod $lod, array $patches, array $eye, float $quality, float $focal): array {
	$lists = ['draw' => [], 'request' => [], 'refine' => []];
	$depth = renderGet($lod, 'depth');

	/**
	 * Visit one node, then its children from last to first as the selector's stack does.
	 * @param array $node Quadtree node.
	 * @return void Appends to the three lists.
	 */
	$visit = static function(array $node) use (&$visit, &$lists, $lod, $patches, $eye, $quality, $focal, $depth): void {
		if(!isset($patches[$node['key']]))
		{
			$lists['request'][] = $node + ['priority' => PHP_FLOAT_MAX];

			return;
		}

		$importance = renderCall($lod, 'importance', $node, $eye, $patches, $quality, $focal);

		if($node['level'] >= $depth || !($importance > 1))
		{
			$lists['draw'][] = $node;

			return;
		}

		$children = $lod->children($node);
		$lists['refine'][] = $node + ['priority' => $importance];
		$missing = 0;

		foreach($children as $child)
		if(!isset($patches[$child['key']]))
		{
			$lists['request'][] = $child + ['priority' => $importance];
			$missing++;
		}

		if($missing > 0) $lists['draw'][] = $node;
		else foreach(array_reverse($children) as $child) $visit($child);
	};

	$visit($lod->node());

	return $lists;
};

$view->position = [-300.0, 40.0, 200.0];
$eye = $view->position;

// The selector works importance out inline, with comparisons and a shortcut for coarse nodes, and walks
// with a counted stack. Its lists must equal those of the plain walk, priorities to the last bit.
$plain = $detailLod->select($eye, $detailPatches, 1, 600);

renderCheck(
	count($plain['refine']) > 40 && count($plain['draw']) > count($plain['refine']) && count($plain['request']) > 40
	, 'plain-walk fixture refines some nodes, keeps more coarse and requests children'
);

renderCheck($plain === $plainWalk($detailLod, $detailPatches, $eye, 1.0, 600.0), 'selection equals the plain walk');

// A world one level shallower stops at the finest resident level, and two absent patches leave their
// sibling groups incomplete with other siblings resident. Its sizes and errors are not powers of two, so
// a product taken in another order would round differently.
$shallow = new Lod(1000.3, 1);
$sparse = $detailPatches;
unset($sparse['3:1:6'], $sparse['4:3:11']);

foreach($sparse as $key => $patch)
$sparse[$key]['geometricError'] = .9 + .037 * (($patch['ix'] * 7 + $patch['iz']) % 23);

$eyes = [[-200.0, 12.0, 150.0], [-300, 40, 200], [90.5, 3.0, -410.25], [-700.3, 31.7, -650.9], [0.0, 2500.0, 0.0]];

foreach($eyes as $from)
{
	$stopped = $shallow->select($from, $sparse, 1.37, 733.5);

	renderCheck(
		$stopped === $plainWalk($shallow, $sparse, $from, 1.37, 733.5)
		, 'selection at the leaf depth equals the plain walk'
	);
}

renderCheck(
	count($stopped['draw']) === 1
		&& $detailLod->select($eye, [], 1, 600) === $plainWalk($detailLod, [], $eye, 1.0, 600.0)
	, 'a distant eye draws the root alone and an empty cache requests it'
);

$headings = [[.4, -.2], [2.6, -.6], [-1.9, .1], [0.0, -1.5], [1.3, -.35], [-.8, .4], [3.6, -.05]];

foreach($headings as [$view->yaw, $view->pitch])
{
	$planes = $view->clipPlanes(1280, 720);
	$seven = [...$planes, [0.0, 0.0, 1.0, -20.0]];

	// The narrow range leaves the height of each box to its ground, and the wide range sets it.
	foreach([[-1.5, 1.5], [-40.0, 60.0]] as [$low, $high])
	{
		$refused = 0;
		$inView = $paddedView($planes, $eye, $low, $high, $refused);
		$byPredicate = $detailLod->select($eye, $detailPatches, 1, 600, $inView);

		renderCheck(
			$refused > 0 && count($byPredicate['refine']) > 4
			, 'padded-view fixture refines some nodes and keeps others coarse'
		);

		renderCheck(
			$detailLod->select($eye, $detailPatches, 1, 600, null, $planes, $low, $high) === $byPredicate
			, 'clip planes select the same patches as the padded-view predicate'
		);

		// A seventh plane leaves the unrolled test of six, and here it hides nodes the six planes show.
		$hiddenBySix = $refused;
		$bySeven = $detailLod->select($eye, $detailPatches, 1, 600, $paddedView($seven, $eye, $low, $high, $refused));

		renderCheck(
			$bySeven !== $byPredicate && $refused - $hiddenBySix > $hiddenBySix
				&& $detailLod->select($eye, $detailPatches, 1, 600, null, $seven, $low, $high) === $bySeven
			, 'any number of clip planes selects as the predicate does'
		);
	}

	// A NAN bound and a patch with a NAN bound also leave the unrolled test.
	renderCheck(
		$detailLod->select($eye, $detailPatches, 1, 600, null, $planes, -1.5, NAN)
			=== $detailLod->select($eye, $detailPatches, 1, 600, $paddedView($planes, $eye, -1.5, NAN, $refused))
		, 'a NAN height bound selects as the predicate does'
	);

	$odd = $detailPatches;
	$odd['3:3:6']['minY'] = NAN;

	renderCheck(
		$detailLod->select($eye, $odd, 1, 600, null, $planes, -1.5, 1.5)
			=== $detailLod->select($eye, $odd, 1, 600, $paddedView($planes, $eye, -1.5, 1.5, $refused))
		, 'a patch with a NAN height bound selects as the predicate does'
	);
}

try
{
	$detailLod->select($eye, $detailPatches, 1, 600, $paddedView($planes, $eye, 0, 0, $refused), $planes);
	renderCheck(false, 'a predicate and clip planes together must be refused');
}catch(InvalidArgumentException)
{
	renderCheck(true, 'a predicate and clip planes together are refused');
}

$bootstrapSurface = new Landscape;
$bootstrapScene = new Scene($bootstrapSurface, new Water, new Atmosphere);
// The fixtures from here to the staged ones check what whole selections do from one update to the next. A
// clock-driven slice would make their results depend on the speed of the machine, so each sets it to zero.
$bootstrapScene->selectionSliceMs = 0;
$bootstrapScene->budgetMs = 1000;
$bootstrapScene->update(.1, new Camera);
renderCheck($bootstrapScene->stats()['patches'] === 1, 'one completed bootstrap provides whole-world coverage');

renderCheck(
	$bootstrapSurface->stats()['generated'] === 1089
	, 'first root generates one authoritative node per vertex, not five normal neighbors'
);

$bootstrapScene->render(new Camera, 800, 600, 0);

renderCheck(
	$bootstrapScene->stats()['shadows']['generation'] === 1
	, 'first revision-zero root arrival populates broad terrain shadows'
);

$bootstrapScene->render(new Camera, 800, 600, 0);

renderCheck(
	$bootstrapScene->stats()['shadows']['generation'] === 1
	, 'unchanged frames reuse cached broad terrain shadows'
);

$bootstrapScene->update(.1, new Camera);
$invisibleChanged = $bootstrapScene->update(.1, new Camera);

renderCheck(
	!$invisibleChanged && $bootstrapScene->stats()['patches'] === 3 && $bootstrapScene->stats()['visible'] === 1
		&& $bootstrapScene->dirty()
	, 'completed invisible siblings keep generation active without redrawing the old coverage'
);

$bootstrapScene->close();
$bootstrapSurface->close();

$loadingSurface = new Landscape;
$loadingScene = new Scene($loadingSurface, new Water, new Atmosphere);
$loadingScene->selectionSliceMs = 0;
$loadingScene->budgetMs = .25;
$loadingCamera = new Camera;
$partialUpdates = 0;

for($frame = 0; $frame < 30; $frame++)
{
	$before = $loadingScene->stats();
	$loadingScene->update(.1, $loadingCamera);
	$after = $loadingScene->stats();

	if($frame > 0 && $before['patches'] === 0 && $after['patches'] === 0)
	{
		$partialUpdates++;

		renderCheck(
			$after['selections'] === $before['selections']
			, 'unchanged partial generation does not repeat the expensive LOD selection'
		);
	}
}

renderCheck($partialUpdates > 2, 'stationary generation fixture exercises multiple selection intervals');
$before = $loadingScene->stats()['selections'];
$loadingCamera->yaw += .4;
$loadingScene->update(.1, $loadingCamera);
renderCheck($loadingScene->stats()['selections'] > $before, 'turning the camera reprioritizes pending visible work');
$loadingScene->close();
$loadingSurface->close();

// Exact sandbox canyon pose: visible walls must not wait behind unrelated whole-world refinement.
$canyonSurface = new Landscape([
	'preset' => 'canyon'
	, 'heightScale' => 650
	, 'baseFrequency' => 1 / 4000
	, 'terraceSpacing' => 80
	, 'terraceStrength' => .7
]);

$canyonScene = new Scene($canyonSurface, new Water, new Atmosphere);
$canyonScene->selectionSliceMs = 0;
$canyonScene->quality = 2;
$canyonScene->budgetMs = 1000;
renderSet($canyonScene, 'viewportHeight', 360);
$canyonCamera = new Camera;
$canyonCamera->position = [-340,50,1600];
$canyonCamera->yaw = 2.68;
$canyonCamera->pitch = -.17;
$wallSpacing = INF;

for($frame = 0; $frame < 190 && $wallSpacing > 8; $frame++)
{
	$canyonScene->update(1 / 60, $canyonCamera);
	$canyonStats = $canyonScene->stats();

	renderCheck(
		$canyonStats['reservedPatches'] <= $canyonStats['maximumPatches']
		, 'visible-wall prioritization preserves the complete shared cache bound'
	);

	foreach($canyonStats['visibleCoverage'] as [$x,$z,$size,$spacing])
	if(122 >= $x && 122 < $x + $size && 2000 >= $z && 2000 < $z + $size) $wallSpacing = $spacing;

	if($frame % 30 === 0) renderCover(renderGet($canyonScene, 'draw'), 32768);
}

renderCheck(
	$wallSpacing <= 8 && $canyonScene->stats()['patches'] <= 170
	, 'camera weighting and balancing prerequisites refine the real canyon wall within170 completed patches'
);

renderCover(renderGet($canyonScene, 'draw'), 32768);
$canyonScene->close();
$canyonSurface->close();

// A downward flight predicts future detail while the current high-altitude view needs only root.
$predictedSurface = new Landscape(['worldSize' => 256, 'heightScale' => 0, 'detailAmplitude' => 0]);
$predictedScene = new Scene($predictedSurface, new Water, new Atmosphere);
$predictedScene->selectionSliceMs = 0;
$predictedScene->budgetMs = 1000;
// These checks follow speculative jobs from one 60 Hz update to the next, so each changed view must select at once.
$predictedScene->selectionInterval = 0;
$predictedCamera = new Camera;
$predictedCamera->position = [0,700,0];
$predictedScene->update(.1, $predictedCamera);
$predictedScene->update(.1, $predictedCamera);
$predictedCamera->position[1] -= 5;
$predictedScene->update(1 / 60, $predictedCamera);
renderCheck($predictedScene->stats()['lookahead'] === [0.0,245.0,0.0], '300m/s motion predicts 1.5 seconds ahead');

renderCheck(
	$predictedScene->stats()['prefetchJobs'] === 3 && $predictedScene->stats()['patches'] === 2
	, 'spare generation actually prepares future siblings before the current view requests them'
);

renderCheck($predictedScene->stats()['visible'] === 1, 'speculative detail never changes current-view coverage');
$predictedCamera->position = [-100,695,-100];
$predictedScene->update(1 / 60, $predictedCamera);

renderCheck(
	$predictedScene->stats()['lookahead'] === null && $predictedScene->stats()['prefetchJobs'] === 0
	, 'teleport cancels the old flight corridor and speculative jobs'
);

$predictedCamera->position = [0,700,0];
$predictedScene->update(1 / 60, $predictedCamera);
$predictedCamera->position[1] -= 5;
$predictedScene->update(1 / 60, $predictedCamera);
$predictedScene->update(1 / 60, $predictedCamera);

renderCheck(
	$predictedScene->stats()['lookahead'] === null && $predictedScene->stats()['prefetchJobs'] === 0
	, 'a stopped camera immediately drops speculative demand'
);

for($i = 0; $i < 10; $i++) $predictedScene->update(.1, $predictedCamera);
$stoppedSelections = $predictedScene->stats()['selections'];

for($i = 0; $i < 30; $i++) $predictedScene->update(.1, $predictedCamera);

renderCheck(
	$predictedScene->stats()['selections'] === $stoppedSelections && !$predictedScene->dirty()
	, 'stopped flight returns to an idle scheduler without repeated selection'
);

$predictedScene->maximumPatches = 2;
$predictedCamera->position[1] -= 5;
$predictedScene->update(1 / 60, $predictedCamera);

renderCheck(
	$predictedScene->stats()['prefetchJobs'] === 0 && $predictedScene->stats()['reservedPatches'] <= 2
	, 'lookahead cannot evict current resources or exceed a tight cache budget'
);

$predictedScene->close();
$predictedSurface->close();

// One completed patch per frame makes this flight regression independent of CPU timing.
// The old quadrant-ordered scheduler left this camera over level-three patches.
$flightSurface = new Landscape(['heightScale' => 0, 'detailAmplitude' => 0]);
$flightScene = new Scene($flightSurface, new Water, new Atmosphere);
$flightScene->selectionSliceMs = 0;
$flightScene->budgetMs = 1000;
// This flight checks how motion and finished siblings share a selection on every frame. The limited flight follows it.
$flightScene->selectionInterval = 0;
$flightCamera = new Camera;
$flightCamera->position = [-4000,20,20];

for($frame = 0; $frame < 100; $frame++) $flightScene->update(.1, $flightCamera);
$flightLevels = [];
$prefetchPeak = 0;
$flightSelections = renderGet($flightScene, 'selections');

for($frame = 0; $frame < 120; $frame++)
{
	$flightCamera->position[0] += 5;
	$previousSelections = renderGet($flightScene, 'selections');
	$flightScene->update(1 / 60, $flightCamera);
	$flightStats = $flightScene->stats();
	renderCheck($flightStats['selections'] - $previousSelections <= 1,
		'motion and newly resident siblings share one LOD selection per frame after bootstrap');
	$prefetchPeak = max($prefetchPeak, $flightStats['prefetchJobs']);

	renderCheck(
		$flightStats['reservedPatches'] <= 384 && $flightStats['prefetchJobs'] <= 32
		, 'flight and speculative jobs stay within their shared and speculative budgets'
	);

	$seenSpeculative = false;
	$previousPriority = INF;
	$ordered = true;

	foreach(renderGet($flightScene, 'jobs') as $job)
	{
		if($job['speculative']) $seenSpeculative = true;
		else
		{
			if($seenSpeculative || $job['priority'] > $previousPriority) $ordered = false;

			$previousPriority = $job['priority'];
		}
	}

	renderCheck($ordered, 'current-view sibling importance precedes every speculative request');
	$flightDraw = renderGet($flightScene, 'draw');

	if($frame % 30 === 0) renderCover($flightDraw, 32768);

	$underCamera = -1;

	foreach($flightDraw as $node)
	if($flightCamera->position[0] >= $node['x'] && $flightCamera->position[0] < $node['x'] + $node['size']
		&& $flightCamera->position[2] >= $node['z'] && $flightCamera->position[2] < $node['z'] + $node['size']
	)
	$underCamera = $node['level'];
	$flightLevels[] = $underCamera;
}

renderCheck(
	min($flightLevels) >= 6 && max($flightLevels) >= 7
	, '300m/s flight maintains substantially finer nearby coverage than the old level-three fallback'
);

renderCheck($prefetchPeak > 0, 'moving flight reserves bounded future refinement');

// The flight crosses 75 position buckets of 8 m, and each crossing is a changed view.
renderCheck(
	renderGet($flightScene, 'selections') - $flightSelections >= 75
	, 'an interval of zero selects on every changed view of the flight'
);

for($frame = 0; $frame < 500 && $flightScene->dirty(); $frame++) $flightScene->update(.1, $flightCamera);

renderCheck(
	!$flightScene->dirty() && $flightScene->stats()['pending'] === 0
	, 'completed sibling reservations prevent endless rebuilds after flight stops'
);

renderCheck(
	$flightScene->stats()['lookahead'] === null && $flightScene->stats()['prefetchJobs'] === 0
	, 'stationary flight discards its prediction and speculative queue'
);

$stoppedSelections = $flightScene->stats()['selections'];

for($frame = 0; $frame < 30; $frame++) $flightScene->update(.1, $flightCamera);

renderCheck(
	$flightScene->stats()['selections'] === $stoppedSelections
	, 'a fully settled large landscape performs no idle reselection'
);

$flightCamera->position = [-15000,20,-15000];
$flightScene->update(1 / 60, $flightCamera);

renderCheck(
	$flightScene->stats()['lookahead'] === null && $flightScene->stats()['prefetchJobs'] === 0
	, 'large-world teleport restarts current-view demand without the previous corridor'
);

renderCover(renderGet($flightScene, 'draw'), 32768);
$flightScene->close();
$flightSurface->close();

// Selection limiter. The fixtures above that follow selection from one update to the next set
// selectionInterval to zero. The fixtures below set it themselves, so none depends on its default.

// The same 300 m/s flight as above with the limiter on.
$limitedSurface = new Landscape(['heightScale' => 0, 'detailAmplitude' => 0]);
$limitedScene = new Scene($limitedSurface, new Water, new Atmosphere);
$limitedScene->selectionSliceMs = 0;
$limitedScene->budgetMs = 1000;
$limitedScene->selectionInterval = .1;
$limitedCamera = new Camera;
$limitedCamera->position = [-4000,20,20];

for($frame = 0; $frame < 100; $frame++) $limitedScene->update(.1, $limitedCamera);
$limitedMarks = $limitedLevels = [];

// One frame more than the flight above, so the last frame selects and the stop below has to wait.
for($frame = 0; $frame < 121; $frame++)
{
	$limitedCamera->position[0] += 5;
	if(renderSelections($limitedScene, 1 / 60, $limitedCamera)) $limitedMarks[] = $frame;
	$limitedLevels[] = renderLevelUnder($limitedScene, $limitedCamera);
}

renderCheck(
	$limitedMarks === range(0, 120, 6)
	, 'a limited flight selects ten times a second at 60 Hz, not on nearly every frame'
);

renderCheck(
	min($limitedLevels) >= 6 && max($limitedLevels) >= 7
	, 'ten selections a second keep the nearby coverage that the unlimited flight is held to'
);

renderCover(renderGet($limitedScene, 'draw'), 32768);
$limitedScene->update(1 / 60, $limitedCamera);
$corridor = $limitedScene->stats()['prefetchJobs'];

for($frame = 0; $frame < 5; $frame++) $limitedScene->update(1 / 60, $limitedCamera);

renderCheck(
	$corridor > 0 && $limitedScene->stats()['lookahead'] === null && $limitedScene->stats()['prefetchJobs'] === 0
	, 'a limited flight that stops keeps its speculative jobs only until the interval ends'
);

$limitedScene->close();
$limitedSurface->close();

// At this height the root alone satisfies the view. No patch is ever requested, so each selection
// below answers the camera and nothing else.
$travelSurface = new Landscape(['heightScale' => 0, 'detailAmplitude' => 0]);
$travelWater = new Water;
$travelScene = new Scene($travelSurface, $travelWater, new Atmosphere);
$travelScene->selectionSliceMs = 0;
$travelScene->budgetMs = 1000;
$travelScene->selectionInterval = .1;
$travelCamera = new Camera;
$travelCamera->position = [0,60000,0];

for($frame = 0; $frame < 10; $frame++) $travelScene->update(.1, $travelCamera);

renderCheck(
	!$travelScene->dirty() && $travelScene->stats()['visible'] === 1
	, 'the limiter fixture rests on a settled root'
);

$marks = [];

// Nine metres a frame crosses an 8 m position bucket on every frame.
for($frame = 0; $frame < 32; $frame++)
{
	$travelCamera->position[0] += 9;
	if(renderSelections($travelScene, 1 / 60, $travelCamera)) $marks[] = $frame;
}

renderCheck($marks === [0,6,12,18,24,30], 'a moving view selects once per interval, every sixth frame at 60 Hz');

// The camera now stops one frame after a selection, with a newer view still waiting.
renderCheck(
	$travelScene->dirty() && renderGet($travelScene, 'selectionDeferred') && renderGet($travelScene, 'jobs') === []
		&& !renderGet($travelScene, 'changed') && !renderGet($travelScene, 'selectionPending')
	, 'a postponed selection keeps a stopped scene dirty with nothing else left to do'
);

renderCheck($travelScene->stats()['selectionPending'], 'diagnostics report a postponed selection as pending');
$updates = $selected = $selectedAt = 0;

// The sandbox updates a resting scene only while it reports dirty, so this loop is all a stopped view gets.
while($travelScene->dirty() && $updates < 20)
{
	$updates++;

	if(renderSelections($travelScene, 1 / 60, $travelCamera))
	{
		$selected++;
		$selectedAt = $updates;
	}
}

renderCheck(
	$selected === 1 && $selectedAt === 5
	, 'a stopped view gets its last selection when the interval ends, with no new input'
);

// The scene clock stands still once the host stops updating. The scene therefore asks for one more interval of
// updates, or the limiter would take the selection before a long rest for a recent one.
renderCheck($updates === 11 && !$travelScene->dirty(), 'the scene goes idle one interval after its last selection');

renderCheck(
	renderSelections($travelScene, 1 / 60, $travelCamera) === 0 && !$travelScene->dirty()
	, 'the last selection used the resting pose, so the scene stays idle'
);

$travelCamera->position[0] += 9;

renderCheck(
	renderSelections($travelScene, 1 / 60, $travelCamera) === 1
	, 'the first changed view after a rest selects at once'
);

// Each row is one frame, one or two frames after a selection. Only a relocation may select that soon.
$steps = [
	['position', 9, 0, 'ordinary travel waits for the interval']
	, ['position', 100, 1, 'a jump of more than 64 m selects at once']
	, ['position', 60, 0, 'a hop of less than 64 m counts as travel']
	, ['yaw', .2, 0, 'an ordinary turn waits for the interval']
	, ['yaw', 1, 1, 'a turn of more than half the field of view selects at once']
	, ['yaw', 2 * M_PI, 0, 'a yaw that wraps a whole turn is not a jump']
	, ['pitch', -.2, 0, 'an ordinary tilt waits for the interval']
	, ['pitch', 1, 1, 'a tilt of more than half the field of view selects at once']
];

foreach($steps as [$axis,$change,$expected,$message])
{
	if($axis === 'position') $travelCamera->position[0] += $change;
	else $travelCamera->$axis += $change;

	renderCheck(renderSelections($travelScene, 1 / 60, $travelCamera) === $expected, $message);
}

// A changed setting selects on a frame of travel that would otherwise wait.
$travelCamera->position[0] += 9;
$travelScene->quality = .5;
$ran = renderSelections($travelScene, 1 / 60, $travelCamera);
renderCheck($ran === 1, 'a new detail setting selects at once');
$travelCamera->position[0] += 9;
$travelWater->setLevel(5);
$ran = renderSelections($travelScene, 1 / 60, $travelCamera);
renderCheck($ran === 1, 'a new water level selects at once');

$travelCamera->position[0] += 9;
$travelWater->setWaveScale(.5);
$ran = renderSelections($travelScene, 1 / 60, $travelCamera);
renderCheck($ran === 1, 'a new wave height selects at once');
$travelCamera->position[0] += 9;
$travelCamera->fov = .9;
$ran = renderSelections($travelScene, 1 / 60, $travelCamera);
renderCheck($ran === 1, 'a new field of view selects at once');

$travelCamera->position[0] += 9;
$travelScene->render($travelCamera, 640, 360, 0);
$ran = renderSelections($travelScene, 1 / 60, $travelCamera);
renderCheck($ran === 1, 'a new viewport selects at once');
$travelCamera->position[0] += 9;
$ran = renderSelections($travelScene, 1 / 60, $travelCamera);
renderCheck($ran === 0, 'the same travel waits once the settings stop changing');

$travelScene->selectionInterval = .2;
$marks = [];

for($frame = 0; $frame < 36; $frame++)
{
	$travelCamera->position[0] += 9;
	if(renderSelections($travelScene, 1 / 60, $travelCamera)) $marks[] = $frame;
}

// The last selection ran two frames before this loop began.
renderCheck($marks === [10,22,34], 'a longer interval spaces selections twelve frames apart');
$travelScene->selectionInterval = 0;
$ran = renderSelections($travelScene, 1 / 60, $travelCamera);
renderCheck($ran === 1, 'turning the limiter off runs the postponed selection');
$marks = [];

for($frame = 0; $frame < 12; $frame++)
{
	$travelCamera->position[0] += 9;
	if(renderSelections($travelScene, 1 / 60, $travelCamera)) $marks[] = $frame;
}

renderCheck($marks === range(0, 11), 'an interval of zero selects on every changed view');

// The limiter last saw the camera 13 frames of travel ago, so the first limited update counts as a relocation.
$travelScene->selectionInterval = .1;
$travelCamera->position[0] += 9;
$travelScene->update(1 / 60, $travelCamera);
$travelCamera->position[0] += 9;
$ran = renderSelections($travelScene, 1 / 60, $travelCamera);
renderCheck($ran === 0 && $travelScene->dirty(), 'the limiter postpones travel again once it is back on');
$ran = 0;

// A paused host may keep updating with no elapsed time. The scene clock stands still then, so no wait could end.
for($frame = 0; $frame < 3; $frame++) $ran += renderSelections($travelScene, 0, $travelCamera);

renderCheck(
	$ran === 1 && !$travelScene->dirty()
	, 'updates of zero seconds run a postponed selection at once and leave the scene idle'
);

$travelScene->close();
$travelSurface->close();

/**
 * Stand in for a patch worker that accepts every request and never finishes one.
 * @param string $action Bridge command.
 * @param string $path Transfer file path.
 * @param string $key Patch key.
 * @param string $payload Command payload.
 * @return string The empty reply, which means accepted and still pending.
 */
$stalled = static fn(string $action, string $path, string $key, string $payload): string => '';

// The root never arrives from this worker, so nothing is drawn and the limiter must stay out of the way.
$emptySurface = new Landscape(['heightScale' => 0, 'detailAmplitude' => 0]);
$emptyScene = new Scene($emptySurface, new Water, new Atmosphere, new BrowserPatchBuilder($stalled));
$emptyScene->selectionSliceMs = 0;
$emptyScene->selectionInterval = .1;
$emptyCamera = new Camera;
$selected = 0;

for($frame = 0; $frame < 6; $frame++)
{
	$emptyCamera->position[0] += 9;
	$selected += renderSelections($emptyScene, 1 / 60, $emptyCamera);
}

renderCheck(
	$selected === 6 && $emptyScene->stats()['visible'] === 0
	, 'with nothing drawn every changed view selects at once'
);

$emptyScene->close();
$emptySurface->close();

// Two scenes share every input and differ only in the limiter.
$twins = [];

foreach([0.0,.1] as $interval)
{
	$twinSurface = new Landscape(['worldSize' => 256,'heightScale' => 0,'detailAmplitude' => 0]);
	$twinScene = new Scene($twinSurface, new Water, new Atmosphere);
	$twinScene->selectionSliceMs = 0;
	$twinScene->budgetMs = 1000;
	$twinScene->selectionInterval = $interval;
	$twins[] = [$twinSurface,$twinScene];
}

[[,$free],[,$limited]] = $twins;
$twinCamera = new Camera;
$twinCamera->position = [1,10,0];
$restSelections = 0;

// A camera at rest is not limited. A sibling group finishes every fourth frame here, and each one selects at once.
for($frame = 0; $frame < 24; $frame++)
{
	$restSelections += renderSelections($free, 1 / 60, $twinCamera);
	$limited->update(1 / 60, $twinCamera);

	renderCheck(
		renderKeys($free) === renderKeys($limited)
			&& renderGet($free, 'selections') === renderGet($limited, 'selections')
		, 'a resting view selects and refines exactly as it does without the limiter'
	);
}

renderCheck($restSelections > 4, 'the resting fixture selects more often than once per interval');
$freeMarks = $limitedMarks = $served = [];
$waitingSince = null;
$refined = $limited->stats()['visible'];

// Now the eye trembles by a millimetre inside one position bucket. The view key never changes, so only
// finished sibling groups and transitions ask for selections while the view counts as moving.
for($frame = 0; $frame < 60; $frame++)
{
	$twinCamera->position[0] = 1 + (($frame + 1) % 2) / 1000;
	if(renderSelections($free, 1 / 60, $twinCamera)) $freeMarks[] = $frame;

	if(renderSelections($limited, 1 / 60, $twinCamera))
	{
		$limitedMarks[] = $frame;
		if($waitingSince !== null) $served[] = $frame - $waitingSince;
		$waitingSince = null;
	}

	if($waitingSince === null && renderGet($limited, 'selectionDeferred')) $waitingSince = $frame;
}

$gaps = [];

foreach($limitedMarks as $i => $frame) if($i > 0) $gaps[] = $frame - $limitedMarks[$i - 1];

renderCheck(
	count($gaps) >= 8 && min($gaps) === 6
	, 'finished groups in a moving view select once per interval and no sooner'
);

// A group that finishes during a selection's own update waits longest: one whole interval.
renderCheck(count($served) >= 8 && max($served) <= 6, 'a finished group waits at most one interval for its selection');
renderCheck($limited->stats()['visible'] > $refined, 'finished groups are drawn while the view keeps moving');
renderCheck(count($freeMarks) > count($limitedMarks), 'without the limiter the same groups select as they finish');

for($frame = 0; $frame < 300 && ($free->dirty() || $limited->dirty()); $frame++)
{
	$free->update(.1, $twinCamera);
	$limited->update(.1, $twinCamera);
}

renderCheck(
	!$free->dirty() && !$limited->dirty() && renderKeys($free) === renderKeys($limited)
	, 'once the view rests, the limited scene settles on the cover of the unlimited one'
);

foreach($twins as [$twinSurface,$twinScene])
{
	$twinScene->close();
	$twinSurface->close();
}

// Spread selections. These fixtures slice selections by steps with selectionSliceSteps, so what they check does
// not depend on the clock or the speed of the machine. The fixtures above run every selection whole with
// selectionSliceMs at zero, which is the behaviour from before selections were spread.

// A walk that pauses returns the lists of one that does not, and a walk without credit never pauses.
$spreadView = new Camera;
$spreadView->position = [-300.0, 40.0, 200.0];
$spreadView->yaw = 2.6;
$spreadView->pitch = -.6;
$spreadPlanes = $spreadView->clipPlanes(1280, 720);
$pausedWalk = $detailLod->walk($spreadView->position, $detailPatches, 1, 600, null, $spreadPlanes, -1.5, 1.5, 3);
$pauses = 0;

for($pausedWalk->current(); $pausedWalk->valid(); $pausedWalk->send(3)) $pauses++;

renderCheck(
	$pauses > 5 && $pausedWalk->getReturn()
		=== $detailLod->select($spreadView->position, $detailPatches, 1, 600, null, $spreadPlanes, -1.5, 1.5)
	, 'a walk that pauses every three refined nodes returns the lists of a whole walk'
);

$wholeWalk = $detailLod->walk($spreadView->position, $detailPatches, 1, 600);
$wholeWalk->current();
renderCheck(!$wholeWalk->valid(), 'a walk without credit never pauses');

/**
 * Make a scene for the spread-selection checks: a flat 256 m world whose patches finish one per update.
 * @param int $steps Selection steps per update; zero runs every selection whole.
 * @param float $interval Selection limiter spacing in seconds.
 * @param array $recipe Recipe entries that replace the fixture's own.
 * @return array The landscape, its water and the scene.
 */
function renderSpreadScene(int $steps, float $interval = 0, array $recipe = []): array
{
	$surface = new Landscape($recipe + ['worldSize' => 256, 'heightScale' => 0, 'detailAmplitude' => 0]);
	$water = new Water;
	$scene = new Scene($surface, $water, new Atmosphere);
	$scene->budgetMs = 1000;
	$scene->selectionInterval = $interval;
	$scene->selectionSliceMs = 0;
	$scene->selectionSliceSteps = $steps;

	return [$surface, $water, $scene];
}

/**
 * Check that every drawn patch and every ancestor of one is resident.
 * @param Scene $scene Scene to inspect.
 * @param string $message Failure message.
 * @return void Records one check.
 */
function renderResident(Scene $scene, string $message): void
{
	$patches = renderGet($scene, 'patches');
	$resident = true;

	foreach(renderGet($scene, 'draw') as $node)
	for($level = $node['level'], $x = $node['ix'], $z = $node['iz']; $level >= 0; $level--, $x >>= 1, $z >>= 1)
	$resident = $resident && isset($patches["$level:$x:$z"]);

	renderCheck($resident, $message);
}

/**
 * Advance a scene as a host does after moving the camera: once, then until it reports no more work.
 * @param Scene $scene Scene to advance.
 * @param Camera $camera Viewing camera.
 * @param int $limit Most updates to run.
 * @return int Updates run.
 */
function renderSettle(Scene $scene, Camera $camera, int $limit = 3000): int
{
	$updates = 0;

	do $scene->update(1 / 60, $camera);
	while(++$updates < $limit && $scene->dirty());

	return $updates;
}

$spreadCamera = new Camera;
$spreadCamera->position = [0.0, 12.0, 0.0];
$spreadCamera->pitch = -.3;

// A slice wide enough for every selection runs the spread machinery to the end inside each update. It must then
// do exactly what whole selections do, with and without the limiter, while patches finish and the view moves.
foreach([0.0, .1] as $interval)
{
	$pair = [renderSpreadScene(0, $interval), renderSpreadScene(1000000000, $interval)];
	[[, , $whole], [, , $wide]] = $pair;
	$pairCamera = clone $spreadCamera;
	$same = true;

	for($frame = 0; $frame < 260; $frame++)
	{
		if($frame >= 90 && $frame < 200)
		{
			$pairCamera->position[0] += .9;
			$pairCamera->yaw += .01;
		}

		$whole->update(1 / 60, $pairCamera);
		$wide->update(1 / 60, $pairCamera);

		$same = $same && renderGet($whole, 'draw') === renderGet($wide, 'draw')
			&& array_keys(renderGet($whole, 'jobs')) === array_keys(renderGet($wide, 'jobs'))
			&& renderGet($whole, 'reserved') === renderGet($wide, 'reserved')
			&& renderGet($whole, 'selections') === renderGet($wide, 'selections')
			&& renderGet($whole, 'retiring') === renderGet($wide, 'retiring')
			&& !$wide->stats()['selectionInFlight'] && !$whole->stats()['selectionInFlight']
			&& $wide->dirty() === $whole->dirty();
	}

	renderCheck(
		$same && renderGet($wide, 'selections') > 10 && $wide->stats()['selectionUpdates'] === 1
			&& $whole->stats()['selectionUpdates'] === 1
		, 'a slice that holds every selection draws, reserves and queues exactly what whole selections do'
	);

	foreach($pair as [$pairSurface, , $pairScene])
	{
		$pairScene->close();
		$pairSurface->close();
	}
}

// One step per update. Warm up with a wider slice, then follow single steps while the camera moves.
[$spreadSurface, $spreadWater, $spread] = renderSpreadScene(40);
// The first update selects twice: once with nothing resident, which requests the root, and once when the root
// arrives in the same update. Neither may wait for a slice.
renderCheck(
	$spread->stats()['selections'] === 0 && renderSelections($spread, 1 / 60, $spreadCamera) === 2
		&& !$spread->stats()['selectionInFlight'] && $spread->stats()['visible'] === 1
	, 'with nothing drawn every selection runs whole and the root draws in its first update'
);
renderSettle($spread, $spreadCamera);
$low = $spreadCamera->position;

// Rising until the cover is coarse leaves the fine patches cached, so the first selection after coming back down
// splits into them. Then the camera travels at 36 m/s, which keeps the lookahead walks running.
$spreadCamera->position = [0.0, 60000.0, 0.0];
renderSettle($spread, $spreadCamera);
renderCheck($spread->stats()['visible'] === 1, 'the risen view settles on the root alone');
$spread->selectionSliceSteps = 1;
$spreadCamera->position = $low;
$applied = $phases = $seen = [];

// One step per update is slow, over a hundred updates for each selection here, so the loop runs long enough
// for several selections that start while the camera travels.
for($frame = 0; $frame < 1400; $frame++)
{
	if($frame >= 300)
	{
		$spreadCamera->position[0] += $frame % 600 < 300 ? .6 : -.6;
		$spreadCamera->yaw += .002;
	}

	$phase = $spread->stats()['selectionPhase'];
	$before = renderGet($spread, 'draw');
	$spread->update(1 / 60, $spreadCamera);
	$after = $spread->stats()['selectionPhase'];

	if(renderGet($spread, 'draw') !== $before) $applied[] = $phase;

	if($phase !== null && $phase === $after) $phases[$phase] = ($phases[$phase] ?? 0) + 1;

	if($phase !== null) $seen[$phase] = true;

	renderResident($spread, 'every drawn patch is resident while selections are spread one step at a time');

	if($frame % 10 === 0) $spread->render($spreadCamera, 1280, 720, $frame / 60);

	if($frame % 50 === 0) renderCover(renderGet($spread, 'draw'), 256);
}

renderCheck(
	count($applied) >= 2 && array_unique($applied) === ['apply']
	, 'the drawn cover changes only in the update that applies a selection, never part way through one'
);

// Everything is resident in this small world once the view has refined, so the corridor asks for nothing more
// and speculate has nothing to pause in. It still runs after every pair of lookahead walks.
renderCheck(
	($phases['walk'] ?? 0) > 20 && ($phases['groups'] ?? 0) > 20 && ($phases['midpoint'] ?? 0) > 5
		&& ($phases['endpoint'] ?? 0) > 5 && isset($seen['speculate'])
	, 'the view walk, the grouping and the lookahead walks resume where they paused'
);

renderCheck(
	$spread->dirty() && $spread->stats()['selectionInFlight'] && $spread->stats()['selectionPending']
	, 'a selection in flight keeps the scene dirty and is reported as pending'
);

// Turning the slice off finishes the selection in flight in the next update.
$spread->selectionSliceSteps = 0;
$spread->update(1 / 60, $spreadCamera);
renderCheck(!$spread->stats()['selectionInFlight'], 'a slice turned off finishes the selection in flight at once');

// Patches that the view walk read can leave the cache before the selection applies. Refine close to the
// ground, rise until the cover is coarse and the fine patches are only cached, then come back down. While the
// selection for the low view is in flight, a smaller cache evicts the fine patches it was about to draw.
$spread->selectionSliceSteps = 40;
$low = [-60.0, 12.0, -60.0];
$spreadCamera->position = $low;
renderSettle($spread, $spreadCamera);
$spreadCamera->position = [-60.0, 60000.0, -60.0];
renderSettle($spread, $spreadCamera);
$spread->selectionSliceSteps = 1;
$spreadCamera->position = $low;
$spread->update(1 / 60, $spreadCamera);
renderCheck($spread->stats()['selectionPhase'] === 'walk', 'the low view starts a selection that pauses in its walk');

while($spread->stats()['selectionPhase'] !== 'balance') $spread->update(1 / 60, $spreadCamera);

// The walk is complete and every patch it chose was resident when it read them. The update that balances its
// choice also evicts down to the smaller cache, before the next update applies it.
$chosen = array_column(renderGet($spread, 'flight')['view']['draw'], 'key');
$spread->maximumPatches = 40;
$spread->update(1 / 60, $spreadCamera);
$resident = renderGet($spread, 'patches');
$gone = array_diff($chosen, array_keys($resident));

renderCheck(
	$spread->stats()['selectionPhase'] === 'apply' && count($gone) > 10
		&& array_diff(array_column(renderGet($spread, 'flight')['desired'], 'key'), array_keys($resident)) !== []
	, 'eviction removes patches that the selection in flight read and still targets'
);

$spread->update(1 / 60, $spreadCamera);
renderCheck($spread->stats()['selectionPhase'] === 'groups', 'the selection applies with part of its target gone');
renderResident($spread, 'a selection whose target lost patches to eviction draws only resident patches');
renderCover(renderGet($spread, 'draw'), 256);
$spread->maximumPatches = 21;
$spread->selectionSliceSteps = 2;

for($frame = 0; $frame < 400; $frame++)
{
	$spreadCamera->position[2] += $frame % 2 ? 3 : -2;
	$spreadCamera->yaw += .02;
	$spread->update(1 / 60, $spreadCamera);
	$spread->render($spreadCamera, 1280, 720, $frame / 60);
	renderResident($spread, 'a tight cache keeps every drawn patch resident while selections are spread');
	$stats = $spread->stats();
	renderCheck($stats['reservedPatches'] <= $stats['maximumPatches'], 'spread selections keep the hard cache bound');
}

$spread->close();
$spreadSurface->close();

// From its grouping phase to publish, a spread selection has its own reservation while the jobs of the last
// selection stay queued, and some of those grow from parents that only the older reservation holds. buildVertex()
// reads a job's parent for the morph heights, so a cache that shrinks then must not evict those parents. A flight
// at 3 m a frame over a flat 512 m world queues such jobs within a few dozen frames.
[$orphanSurface, , $orphan] = renderSpreadScene(30, 0, ['worldSize' => 512]);
$orphanCamera = new Camera;
$orphanCamera->position = [-236.0, 12.0, 0.0];
$orphanCamera->yaw = -M_PI / 2;
$orphanCamera->pitch = -.3;

/**
 * List the parents of queued jobs that a set of patch keys lacks.
 * @param Scene $scene Scene whose jobs to read.
 * @param array $keys Patch keys to look the parents up in.
 * @return array Missing parent keys, as keys.
 */
function renderJobParentsMissing(Scene $scene, array $keys): array
{
	$missing = [];

	foreach(renderGet($scene, 'jobs') as $job)
	{
		$parent = ($job['level'] - 1) . ':' . ($job['ix'] >> 1) . ':' . ($job['iz'] >> 1);

		if($job['level'] > 0 && !isset($keys[$parent])) $missing[$parent] = true;
	}

	return $missing;
}

$exposed = [];

for($frame = 0; $frame < 150 && $exposed === []; $frame++)
{
	$orphanCamera->position[0] += 3;
	$orphan->update(1 / 60, $orphanCamera);
	$flight = renderGet($orphan, 'flight');

	if(isset($flight['reservation'])) $exposed = renderJobParentsMissing($orphan, $flight['reservation']);
}

renderCheck($exposed !== [], 'a spread selection reserves apart from the parents of jobs still queued');
$orphan->maximumPatches = 13;
$orphaned = [];

for($frame = 0; $frame < 40; $frame++)
{
	$orphan->update(1 / 60, $orphanCamera);
	$orphaned += renderJobParentsMissing($orphan, renderGet($orphan, 'patches'));
	renderResident($orphan, 'a cache that shrinks during a spread selection keeps every drawn patch resident');
}

renderCheck($orphaned === [], 'a cache that shrinks during a spread selection keeps the parent of every queued job');
$orphan->close();
$orphanSurface->close();

// Every event that makes the last selection wrong, not merely late, drops a selection in flight. Its replacement
// starts in the same update and is spread like any other, because the cover drawn meanwhile stays complete and
// resident. Only with nothing drawn does it run whole: the picture would stay empty until it completed.
[$forcedSurface, $forcedWater, $forced] = renderSpreadScene(40);
$forcedCamera = new Camera;
$forcedCamera->position = [0.0, 12.0, 0.0];
$forcedCamera->pitch = -.3;
renderSettle($forced, $forcedCamera);
$forced->selectionSliceSteps = 2;

/**
 * Put a selection in flight, apply one event while it is in flight and report what the next update made of it.
 *
 * The selection in flight from the event before runs to its end first, so that every event meets a selection
 * that follows a completed one. After two drops in a row the third selection runs whole, which the checks after
 * the events cover.
 * @param Scene $scene Scene with a cover drawn.
 * @param Water $water The scene's water.
 * @param Camera $camera Viewing camera, moved by 20 m to start the selection.
 * @param string $event detail, fov, water, waves, viewport, jump, turn, travel or restore.
 * @return array Whether the event alone dropped the selection, whether the update started a replacement, and
 *     whether a selection is in flight after the update.
 */
function renderInterrupt(Scene $scene, Water $water, Camera $camera, string $event): array
{
	for($updates = 0; $updates < 3000 && renderGet($scene, 'flight') !== null; $updates++)
	$scene->update(1 / 60, $camera);

	$camera->position[2] += $camera->position[2] > 0 ? -20 : 20;
	$scene->update(1 / 60, $camera);
	$first = renderGet($scene, 'flight')['first'] ?? null;
	renderCheck($first !== null, 'a move of 20 m starts a selection that stays in flight');

	// Each event changes the view or a setting between two updates, as the host would.
	switch($event)
	{
		case 'detail': $scene->quality = 2; break;
		case 'fov': $camera->fov = 1.2; break;
		case 'water': $water->setLevel(3); break;
		case 'waves': $water->setWaveScale(.5); break;
		case 'viewport': $scene->render($camera, 900, 700, 0); break;
		case 'jump': $camera->position[0] += 90; break;
		case 'turn': $camera->yaw += 1.2; break;
		case 'travel': $camera->position[0] += 2; $camera->yaw += .05; break;

		case 'restore':
			// A lost context takes every name with it. This scene owns all that are left at this point.
			foreach(['buffers','vaos','textures','programs','framebuffers','renderbuffers'] as $kind)
			$GLOBALS['terrainGl'][$kind] = [];
			$scene->restore();
	}

	$dropped = renderGet($scene, 'flight') === null;
	$selections = renderGet($scene, 'selections');
	$scene->update(1 / 60, $camera);
	$flight = renderGet($scene, 'flight');
	$restarted = renderGet($scene, 'selections') === $selections + 1
		&& ($flight === null || $flight['first'] !== $first);

	return [$dropped, $restarted, $flight !== null];
}

$events = [
	'detail' => 'a new detail setting', 'fov' => 'a new field of view', 'water' => 'a new water level'
	, 'waves' => 'a new wave height', 'viewport' => 'a new viewport', 'jump' => 'a jump of more than 64 m'
	, 'turn' => 'a turn of more than half the field of view'
];

foreach($events as $event => $name)
{
	[$dropped, $restarted, $inFlight] = renderInterrupt($forced, $forcedWater, $forcedCamera, $event);

	renderCheck(
		!$dropped && $restarted && $inFlight
		, "$name drops the selection in flight and starts a spread replacement at once"
	);

	renderResident($forced, "$name leaves every drawn patch resident");
}

[$dropped, $restarted, $inFlight] = renderInterrupt($forced, $forcedWater, $forcedCamera, 'travel');
renderCheck(!$dropped && !$restarted && $inFlight, 'ordinary travel and turns keep the selection in flight');
[$dropped, $restarted, $inFlight] = renderInterrupt($forced, $forcedWater, $forcedCamera, 'restore');

renderCheck(
	$dropped && $restarted && $inFlight
	, 'restore() drops the selection in flight and the next update spreads its replacement'
);

// A setting that changes on every update drops each spread selection before it applies. After two such drops in a
// row the next selection runs whole, so the cover follows a dragged slider or a resized window every third update.
renderSettle($forced, $forcedCamera);
$forcedCamera->position[2] += $forcedCamera->position[2] > 0 ? -20 : 20;
$forced->update(1 / 60, $forcedCamera);
$streak = [];

foreach([1.0, 2.0] as $detail)
{
	$forced->quality = $detail;
	$completed = $forced->stats()['selectionsCompleted'];
	$forced->update(1 / 60, $forcedCamera);
	$streak[] = [$forced->stats()['selectionInFlight'], $forced->stats()['selectionsCompleted'] - $completed];
}

renderCheck(
	$streak === [[true, 0], [false, 1]]
	, 'a second drop in a row runs the replacement whole in the same update'
);

$completed = $forced->stats()['selectionsCompleted'];

for($frame = 0; $frame < 60; $frame++)
{
	$forcedCamera->position[0] += .3;
	$forced->update(1 / 60, $forcedCamera);
	$forced->render($forcedCamera, 900 + $frame, 700, 0);
	renderResident($forced, 'every drawn patch stays resident while the viewport changes on every update');
}

renderCheck(
	$forced->stats()['selectionsCompleted'] - $completed >= 19
	, 'a viewport that changes on every update still gets a selection every third update'
);

// prepareLandscape() drops the selection at once and no selection runs while the edit is prepared.
$forcedCamera->position[2] += 9;
$forced->update(1 / 60, $forcedCamera);
$edited = Landscape::load($forcedSurface->save());
$edited->queue(['type' => 'raise', 'x' => 0, 'z' => 0, 'radius' => 30, 'amount' => 4]);
$edited->commit();
$forced->prepareLandscape($edited);
$selections = $forced->stats()['selections'];
renderCheck(!$forced->stats()['selectionInFlight'], 'prepareLandscape() drops the selection in flight');

for($frame = 0; $frame < 400 && $forced->stats()['pendingEdits'] > 0; $frame++)
$forced->update(1 / 60, $forcedCamera);

$published = $forced->takePreparedLandscape();

renderCheck(
	$published === $edited && $forced->stats()['selections'] === $selections && !$forced->stats()['selectionInFlight']
	, 'no selection starts while an edit is prepared'
);

$forcedSurface->close();
$forcedSurface = $published;
$forced->update(1 / 60, $forcedCamera);
renderCheck(
	$forced->stats()['selections'] === $selections + 1 && $forced->stats()['selectionInFlight']
	, 'the published edit starts a spread selection in the next update'
);

// A republished landscape and a replaced one empty the cache, so their first selections run whole.
$forcedCamera->position[2] += 9;
$forced->update(1 / 60, $forcedCamera);
renderCheck($forced->stats()['selectionInFlight'], 'a selection is in flight before the landscape is republished');
$forcedSurface->queue(['type' => 'raise', 'x' => 40, 'z' => 40, 'radius' => 30, 'amount' => 2]);
$forcedSurface->commit();
$forced->update(1 / 60, $forcedCamera);
$stats = $forced->stats();

renderCheck(
	!$stats['selectionInFlight'] && $stats['visible'] === 1 && $stats['selectionUpdates'] === 1
	, 'a republished landscape drops the selection in flight and selects whole until it draws'
);

renderSettle($forced, $forcedCamera);
$forcedCamera->position[2] += 9;
$forced->update(1 / 60, $forcedCamera);
$replacement = new Landscape(['worldSize' => 256, 'heightScale' => 0, 'detailAmplitude' => 0]);
$forced->setLandscape($replacement);
renderCheck(!$forced->stats()['selectionInFlight'], 'a replaced landscape drops the selection in flight');
$forced->update(1 / 60, $forcedCamera);
$stats = $forced->stats();

renderCheck(
	!$stats['selectionInFlight'] && $stats['visible'] === 1 && $stats['selectionUpdates'] === 1
	, 'the replaced landscape selects whole until it draws'
);
$forced->close();
$forcedSurface->close();
$replacement->close();

// While the view keeps moving, the limiter counts its spacing from the start of a selection. A selection that
// lasts longer than one interval is followed in the next update by another, and a short one waits as before.
$spacings = [
	[3, 'a long selection is followed at once by the next'], [1000000000, 'short selections start six frames apart'],
];

foreach($spacings as [$steps, $message])
{
	[$limitSurface, , $limit] = renderSpreadScene(40, .1);
	$limitCamera = new Camera;
	$limitCamera->position = [0.0, 12.0, 0.0];
	renderSettle($limit, $limitCamera);
	$limit->selectionSliceSteps = $steps;
	$starts = $ends = [];

	for($frame = 0; $frame < 360; $frame++)
	{
		// Mostly more than one 8 m position bucket a frame, without leaving the world.
		$limitCamera->position[0] = 110 * sin($frame * .09);
		$before = $limit->stats();
		$limit->update(1 / 60, $limitCamera);
		$after = $limit->stats();

		if($after['selections'] > $before['selections']) $starts[] = $frame;

		if($after['selectionsCompleted'] > $before['selectionsCompleted']) $ends[] = $frame;
	}

	$gaps = [];

	foreach($ends as $end)
	foreach($starts as $start)
	if($start >= $end)
	{
		$gaps[] = $start - $end;
		break;
	}

	$spacing = [];

	foreach($starts as $i => $start) if($i > 0) $spacing[] = $start - $starts[$i - 1];

	renderCheck(
		count($starts) > 4
			&& ($steps === 3 ? max($gaps) <= 1 && min($spacing) > 6 : min($spacing) === 6 && max($spacing) === 6)
		, $message
	);
	$limit->close();
	$limitSurface->close();
}

// The host slices by time, not by steps, and there a phase that cannot pause also waits for an update of its own
// when its last cost does not fit. A time slice wider than any selection must give what whole selections give.
// One far below any step leaves each update a single step, which the clock decides as surely as a step count.
[$timedSurface, , $timedWhole] = renderSpreadScene(0);
[$timedWideSurface, , $timedWide] = renderSpreadScene(0);
[$timedTinySurface, , $timedTiny] = renderSpreadScene(0);
$timedWide->selectionSliceMs = 1e6;
$timedTiny->selectionSliceMs = 1e-9;
$timedCamera = new Camera;
$timedCamera->position = [0.0, 12.0, 0.0];
$timedCamera->pitch = -.3;
$same = $applyOnly = true;
$longest = $tinyCompleted = 0;

for($frame = 0; $frame < 700; $frame++)
{
	if($frame >= 120 && $frame < 400)
	{
		$timedCamera->position[0] += .5;
		$timedCamera->yaw += .004;
	}

	$timedWhole->update(1 / 60, $timedCamera);
	$timedWide->update(1 / 60, $timedCamera);
	$same = $same && renderGet($timedWhole, 'draw') === renderGet($timedWide, 'draw')
		&& array_keys(renderGet($timedWhole, 'jobs')) === array_keys(renderGet($timedWide, 'jobs'))
		&& renderGet($timedWhole, 'reserved') === renderGet($timedWide, 'reserved')
		&& !$timedWide->stats()['selectionInFlight'];

	$phase = $timedTiny->stats()['selectionPhase'];
	$completed = $timedTiny->stats()['selectionsCompleted'];
	$before = renderGet($timedTiny, 'draw');
	$timedTiny->update(1 / 60, $timedCamera);
	$applyOnly = $applyOnly && (renderGet($timedTiny, 'draw') === $before || $phase === 'apply' || $phase === null);
	renderResident($timedTiny, 'every drawn patch is resident while a tiny time slice spreads selections');

	if($timedTiny->stats()['selectionsCompleted'] > $completed)
	{
		$tinyCompleted++;
		$longest = max($longest, $timedTiny->stats()['selectionUpdates']);
	}
}

renderCheck(
	$same && renderGet($timedWide, 'selections') > 10
	, 'a time slice that holds every selection draws, reserves and queues exactly what whole selections do'
);

renderCheck(
	$applyOnly && $tinyCompleted > 3 && $longest >= 8
	, 'a time slice below any step spreads each selection one step an update and changes the cover only in apply'
);

renderSettle($timedWhole, $timedCamera);
renderSettle($timedTiny, $timedCamera);
renderCheck(
	!$timedWhole->dirty() && !$timedTiny->dirty() && renderKeys($timedWhole) === renderKeys($timedTiny)
	, 'a view at rest settles on the cover of the whole scene under a tiny time slice'
);

foreach([[$timedSurface, $timedWhole], [$timedWideSurface, $timedWide], [$timedTinySurface, $timedTiny]]
	as [$timedLandscape, $timedScene]
){
	$timedScene->close();
	$timedLandscape->close();
}

// Two scenes share every input and differ only in how selections run. While the eye trembles inside one position
// bucket, finished groups ask for every selection; the spread scene must draw them while the view moves, and
// every group must get a selection within one spread selection and one interval of finishing.
foreach([[.1, 7], [0, 3]] as [$interval, $steps])
{
	$twins = [renderSpreadScene(0, $interval), renderSpreadScene($steps, $interval)];
	[[, , $free], [, , $spread]] = $twins;
	$twinCamera = new Camera;
	$twinCamera->position = [1.0, 12.0, 0.0];
	$twinCamera->pitch = -.4;
	$waited = $owed = 0;
	$longest = 0;

	for($frame = 0; $frame < 900; $frame++)
	{
		$twinCamera->position[0] = 1 + (($frame + 1) % 2) / 1000;
		$free->update(1 / 60, $twinCamera);
		$before = $spread->stats()['selections'];
		$spread->update(1 / 60, $twinCamera);
		renderResident($spread, 'a trembling view keeps every drawn patch resident while selections are spread');

		if($spread->stats()['selections'] > $before) $owed = 0;
		elseif(renderGet($spread, 'selectionDeferred')) $longest = max($longest, ++$owed);

		if($spread->stats()['selectionUpdates'] > $waited) $waited = $spread->stats()['selectionUpdates'];
	}

	renderCheck(
		$spread->stats()['visible'] > 16 && $longest <= $waited + ($interval > 0 ? 6 : 0) + 1
		, 'finished groups are drawn while the view moves and none waits longer than a selection and an interval'
	);

	renderSettle($free, $twinCamera);
	renderSettle($spread, $twinCamera);
	renderCheck(
		!$free->dirty() && !$spread->dirty() && renderKeys($free) === renderKeys($spread)
		, 'once the trembling view rests, the spread scene settles on the cover of the whole one'
	);

	foreach($twins as [$twinSurface, , $twinScene])
	{
		$twinScene->close();
		$twinSurface->close();
	}
}

// Rests after travel, a jump and changed settings. Each rest must settle on the cover the whole scene settles on.
// A cover held down by the cache bound depends on the order in which groups were reserved and coarsened, even
// between two whole scenes with different limiters. This world has 85 patches in all, far inside the bound.
foreach([[.1, 9], [0, 25]] as [$interval, $steps])
{
	$twins = [renderSpreadScene(0, $interval), renderSpreadScene($steps, $interval)];
	[[, $freeWater, $free], [, $spreadWater, $spread]] = $twins;
	$restCamera = new Camera;
	$restCamera->position = [0.0, 12.0, 0.0];
	$restCamera->pitch = -.3;

	$legs = [
		[[0, 0, 1], .01, null], [[1, 0, 0], -.02, null], [[0, 0, 0], 0, 'quality'], [[-120, 5, -150], .5, null],
		[[-.5, 0, .5], .03, 'water'], [[0, .3, 0], .0, null],
	];
	$rests = 0;

	foreach($legs as [$step, $turn, $setting])
	{
		if($setting === 'quality') foreach([$free, $spread] as $scene) $scene->quality = .75;

		if($setting === 'water') foreach([$freeWater, $spreadWater] as $water) $water->setLevel(-4);

		// A step longer than travel is a jump, taken once.
		$frames = abs($step[0]) > 64 ? 1 : 90;

		for($frame = 0; $frame < $frames; $frame++)
		{
			for($axis = 0; $axis < 3; $axis++) $restCamera->position[$axis] += $step[$axis];

			$restCamera->yaw += $turn;
			$free->update(1 / 60, $restCamera);
			$spread->update(1 / 60, $restCamera);
			renderResident($spread, 'every drawn patch is resident along the rest fixture');
		}

		renderSettle($free, $restCamera);
		renderSettle($spread, $restCamera);
		$rests++;
		renderCheck(
			!$free->dirty() && !$spread->dirty() && renderKeys($free) === renderKeys($spread)
			, "rest $rests settles on the cover of the whole scene"
		);
	}

	foreach($twins as [$twinSurface, , $twinScene])
	{
		$twinScene->close();
		$twinSurface->close();
	}
}

$surface = new Landscape(['worldSize' => 256,'heightScale' => 0,'detailAmplitude' => 0,'maxTiles' => 32]);
$water = new Water;
$scene = new Scene($surface, $water, new Atmosphere);
$scene->selectionSliceMs = 0;
$scene->budgetMs = 30;
$scene->maximumPatches = 21;
$camera = new Camera;
$camera->position = [0,10,0];

for($frame = 0; $frame < 90; $frame++)
{
	$scene->update(.1, $camera);
	$stats = $scene->stats();

	renderCheck(
		$stats['reservedPatches'] <= $stats['maximumPatches']
		, 'live, pending and ancestor patches share one hard capacity'
	);

	$draw = renderGet($scene, 'draw');

	if($draw)renderCover($draw, 256);
}

renderCheck($scene->stats()['visible'] > 1, 'resident siblings eventually refine');
$scene->render($camera, 800, 600, 1);
renderCheck($GLOBALS['terrainGl']['draws'] > 0, 'scene submits owned meshes');

// The flat bed is visible here. A water patch can disappear only when its entire height range is below it.
$bed = $surface->height(0, 0);
$water->setLevel($bed - 1);
$scene->render($camera, 800, 600, 1);
renderCheck($scene->stats()['groundDraws'] > 0 && !$scene->showsWater(), 'dry ground submits no water');
$water->setLevel($bed);
$scene->render($camera, 800, 600, 1);
renderCheck($scene->showsWater(), 'the water pass keeps a shoreline at the height bound');
$water->setLevel(0);

// Spot lights validate as a whole list, keep the previous list on a failure, and pack twelve floats each.
$spotLight = [
	'position' => [3,2,-4], 'direction' => [0,-3,4], 'color' => [1,.5,.25]
	, 'intensity' => 20000, 'range' => 50, 'inner' => .1, 'outer' => .3
];
$spotLights = new TerrainPhonics\SpotLights;
$spotLights->set([$spotLight, ['position' => [0,0,0]] + $spotLight]);
$invalidSpotLights = [
	// The shape of the list and of each light.
	'three lights' => [$spotLight, $spotLight, $spotLight], 'a keyed list' => ['a' => $spotLight]
	, 'a light that is not an array' => [1], 'a missing key' => [array_diff_key($spotLight, ['range' => 0])]
	, 'an unknown key' => [['colour' => [1,1,1]] + $spotLight]

	// Values outside their ranges.
	, 'a zero direction' => [['direction' => [0,0,0]] + $spotLight]
	, 'a negative intensity' => [['intensity' => -1] + $spotLight]
	, 'a negative colour' => [['color' => [1,-.1,1]] + $spotLight]
	, 'a range under 0.01 metres' => [['range' => .009] + $spotLight]

	// The cone.
	, 'an inner cone wider than the outer' => [['inner' => .4] + $spotLight]
	, 'an outer cone over a right angle' => [['outer' => M_PI / 2 + .001] + $spotLight]
	, 'a zero outer cone' => [['inner' => 0, 'outer' => 0] + $spotLight]

	// Values that are not finite numbers, and vectors of the wrong shape.
	, 'an infinite position' => [['position' => [INF,0,0]] + $spotLight]
	, 'a NAN range' => [['range' => NAN] + $spotLight]
	, 'a numeric string' => [['intensity' => '20000'] + $spotLight]
	, 'a two-component colour' => [['color' => [1,1]] + $spotLight]
	, 'a keyed position' => [['position' => ['x' => 0,'y' => 0,'z' => 0]] + $spotLight]
];

foreach($invalidSpotLights as $case => $lights)
{
	try
	{
		$spotLights->set($lights);

		renderCheck(false, "spot lights refuse $case");
	}catch(InvalidArgumentException)
	{
		renderCheck($spotLights->count() === 2, "spot lights refuse $case and keep the previous lights");
	}
}

$spotLights->set([$spotLight]);
['uSpotLightCount' => $spotCount, 'uSpotLights[0]' => $packed] = $spotLights->uniforms(0);
$spotScale = 1 / (cos(.1) - cos(.3));
$spotExpected = [3.,2.,-4.,1 / 2500, 0.,-.6,.8,$spotScale];

foreach([1,.5,.25] as $channel) $spotExpected[] = $channel * 20000 * TerrainPhonics\SpotLights::LUX;

$spotExpected[] = -cos(.3) * $spotScale;
renderCheck($spotCount === 1 && count($packed) === 12, 'one spot light packs into three vec4s');

renderCheck(
	max(array_map(static fn($a, $b) => abs($a - $b) / max(1, abs($b)), $packed, $spotExpected)) < 1e-12
	, 'a spot light packs its position, inverse squared range, unit axis, cone and irradiance in scene units'
);

$nightPacked = $spotLights->uniforms(1)['uSpotLights[0]'];
$duskPacked = $spotLights->uniforms(.5)['uSpotLights[0]'];

renderCheck(
	$nightPacked[8] === $packed[8] * TerrainPhonics\SpotLights::NIGHT_GAIN
	&& $duskPacked[10] === $packed[10] * (1 + (TerrainPhonics\SpotLights::NIGHT_GAIN - 1) / 2)
	&& $spotLights->uniforms(2) === $spotLights->uniforms(1) && $spotLights->uniforms(-1) === $spotLights->uniforms(0)
	, 'lamp light gains NIGHT_GAIN along the night fill curve and only the colour changes'
);

renderCheck(
	array_diff_key($nightPacked, [8 => 0, 9 => 0, 10 => 0]) === array_diff_key($packed, [8 => 0, 9 => 0, 10 => 0])
	, 'the night gain leaves position, axis and cone alone'
);

$spotLights->set([['inner' => .3] + $spotLight]);
$hard = $spotLights->uniforms(0)['uSpotLights[0]'];
renderCheck(is_finite($hard[7]) && is_finite($hard[11]), 'a hard-edged cone packs finite numbers');
$spotLights->set([]);
renderCheck($spotLights->count() === 0 && $spotLights->uniforms(1)['uSpotLights[0]'] === [], 'an empty list clears');

preg_match('/#define TERRAIN_MAX_SPOT_LIGHTS (\d+)/', TerrainPhonics\SpotLights::shaderSource(), $spotDefine);
renderCheck((int)$spotDefine[1] === TerrainPhonics\SpotLights::MAX, 'the shader holds as many lights as PHP');
preg_match('/#define TERRAIN_SPOT_KNEE (\S+)/', TerrainPhonics\SpotLights::shaderSource(), $spotKnee);
renderCheck((float)$spotKnee[1] === TerrainPhonics\SpotLights::KNEE, 'the shader knee matches SpotLights::KNEE');

renderCheck(
	TerrainPhonics\SpotLights::shaderSource(false)
		=== "#define TERRAIN_NO_SPOT_LIGHTS\n" . TerrainPhonics\SpotLights::shaderSource()
	&& !str_contains(TerrainPhonics\SpotLights::shaderSource(), '#define TERRAIN_NO_SPOT_LIGHTS')
	, 'the lamp-free include defines TERRAIN_NO_SPOT_LIGHTS ahead of the lit one'
);

// A scene draws the lamp-free ground and water programs while no light is set and the lit pair while one is, and
// uploads lights only when a lit program's copy differs. The spot uniforms get their own locations, so the recorded
// calls name them.
$spotSky = new Atmosphere;
$spotScene = new Scene(new Landscape(['worldSize' => 256,'heightScale' => 0]), new Water, $spotSky);
$spotScene->selectionSliceMs = 0;
$spotScene->budgetMs = 1000;
$spotCamera = new Camera;
$spotCamera->position = [0,10,0];
$spotScene->update(.1, $spotCamera);
$spotNames = [9001 => 'uSpotLightCount', 9002 => 'uSpotLights[0]'];
$spotPrograms = ['groundProgram' => false,'litGroundProgram' => true,'waterProgram' => false,'litWaterProgram' => true];

foreach($spotPrograms as $name => $lit)
{
	$fragment = renderGet(renderGet($spotScene, $name), 'fragment');

	$include = TerrainPhonics\SpotLights::shaderSource($lit);

	renderCheck(
		str_contains($fragment, $include) && !str_contains($fragment, '// SPOT LIGHTS')
		&& substr_count($fragment, 'terrainSpotLight(') === substr_count($include, 'terrainSpotLight(') + 1
		&& str_contains($fragment, '#define TERRAIN_NO_SPOT_LIGHTS') === !$lit
		, "the $name fragment shader includes the " . ($lit ? 'lit' : 'lamp-free') . ' spot lights and calls them once'
	);
}

foreach(['groundProgram' => 'litGroundProgram','waterProgram' => 'litWaterProgram'] as $dark => $lit)
renderCheck(
	str_replace("#define TERRAIN_NO_SPOT_LIGHTS\n", '', renderGet(renderGet($spotScene, $dark), 'fragment'))
		=== renderGet(renderGet($spotScene, $lit), 'fragment')
	&& renderGet(renderGet($spotScene, $dark), 'vertex') === renderGet(renderGet($spotScene, $lit), 'vertex')
	, "the $dark and the $lit differ only in the lamp-free define"
);

$spotDrawn = [];

/**
 * Render one frame of the spot-light scene and list its spot-light uploads.
 * @return array Name and arguments after the location of each upload, in order. $spotDrawn receives the names of
 * the ground and water programs the frame drew with, in order, and the number of draws.
 */
$spotFrame = static function() use ($spotScene, $spotCamera, $spotNames, $spotPrograms, &$spotDrawn): array {
	$ids = [];

	foreach($spotPrograms as $name => $lit)
	{
		$program = renderGet($spotScene, $name);
		$ids[$program->id] = $name;
		renderSet($program, 'locations', array_flip($spotNames) + renderGet($program, 'locations'));
	}

	$GLOBALS['terrainGlCalls'] = $GLOBALS['terrainGlDraws'] = [];
	$spotScene->render($spotCamera, 320, 200, 0);
	$uploads = [];

	foreach($GLOBALS['terrainGlCalls'] as $call)
	if(isset($spotNames[$call[0]])) $uploads[] = [$spotNames[$call[0]], array_slice($call, 1)];

	$spotDrawn = [
		array_values(array_unique(array_map(static fn($id) => $ids[$id], array_filter(
			$GLOBALS['terrainGlDraws'], static fn($id) => isset($ids[$id])
		))))
		, count($GLOBALS['terrainGlDraws'])
	];

	unset($GLOBALS['terrainGlCalls'], $GLOBALS['terrainGlDraws']);

	return $uploads;
};

$spotLights->set([$spotLight, ['position' => [1,2,3]] + $spotLight]);
$spotDay = $spotLights->uniforms(0)['uSpotLights[0]'];
renderCheck($spotFrame() === [], 'a scene without spot lights uploads none');
$spotScene->setSpotLights([]);
renderCheck($spotFrame() === [], 'clearing lights that were never set uploads nothing');
// The first frame also filled the sky and shadow caches, so the second gives the draws of a settled frame.
[$darkDrawn, $darkDraws] = $spotDrawn;

renderCheck(
	$darkDrawn === ['groundProgram','waterProgram'] && $spotScene->stats()['visible'] > 0
	, 'without spot lights the ground and the water draw with the lamp-free programs: ' . implode(', ', $darkDrawn)
);

$spotScene->setSpotLights([$spotLight, ['position' => [1,2,3]] + $spotLight]);
$both = [['uSpotLightCount', [2]], ['uSpotLights[0]', [6, $spotDay]]];
renderCheck($spotFrame() === [...$both, ...$both], 'the ground and the water programs each receive both lights');

renderCheck(
	$spotDrawn === [['litGroundProgram','litWaterProgram'], $darkDraws]
	, 'with spot lights set the same draws use the lit programs: ' . implode(', ', $spotDrawn[0])
);

renderCheck($spotFrame() === [], 'unchanged lights upload nothing');
$spotScene->setSpotLights([['position' => [3,2,-5]] + $spotLight, ['position' => [1,2,3]] + $spotLight]);
$moved = $spotFrame();

renderCheck(
	array_column($moved, 0) === ['uSpotLights[0]','uSpotLights[0]'] && $moved[0][1][1][2] === -5.
	, 'moved lights upload their data and keep the count'
);

$spotSky->setSun([0,-1,0]);
$night = $spotFrame();

renderCheck(
	count($night) === 2 && $night[0][1][1][8] === $moved[0][1][1][8] * TerrainPhonics\SpotLights::NIGHT_GAIN
	, 'at night both programs receive the lamps with the night gain'
);

$spotScene->setSpotLights([$spotLight]);
$spotLights->set([$spotLight]);
$one = [['uSpotLightCount', [1]], ['uSpotLights[0]', [3, $spotLights->uniforms(1)['uSpotLights[0]']]]];
renderCheck($spotFrame() === [...$one, ...$one], 'a shorter list uploads its count and its data');
$spotScene->setSpotLights([]);
renderCheck($spotFrame() === [], 'clearing uploads nothing');

renderCheck(
	$spotDrawn[0] === ['groundProgram','waterProgram']
	, 'clearing returns the ground and the water to the lamp-free programs'
);

renderCheck($spotFrame() === [], 'cleared lights cost no uploads');
$spotScene->setSpotLights([$spotLight]);

renderCheck(
	$spotFrame() === [] && $spotDrawn[0] === ['litGroundProgram','litWaterProgram']
	, 'the lit programs keep their lights while the lamp-free ones draw, so the same lights again upload nothing'
);

/**
 * Restore the spot-light scene as after a lost context, which takes the scene's texture names with it.
 * @return void Leaves the stub's ownership as a real restoration would.
 */
$spotRestore = static function() use ($spotScene): void {
	$expired = [...renderGet($spotScene, 'textures'), ...renderGet($spotScene, 'heightTexture')];
	$spotScene->restore();
	terrainGlDelete('textures', $expired);
};

$spotScene->setSpotLights([]);
$spotRestore();
renderCheck($spotFrame() === [], 'a relinked program without lights needs no uploads');
renderCheck($spotDrawn[0] === ['groundProgram','waterProgram'], 'a restored scene without lights draws lamp-free');
$spotScene->setSpotLights([$spotLight]);
$spotFrame();
$spotRestore();
renderCheck(count($spotFrame()) === 4, 'restoration relinks the programs and the next frame uploads the lights again');
renderCheck($spotDrawn[0] === ['litGroundProgram','litWaterProgram'], 'a restored scene with lights draws lit');
$spotScene->close();

// More than twelve independent imbalance repairs are required by this complete cover.
$fixture = [];
$large = new Lod(1024, 1);
$refined = 0;

for($z = 0; $z < 8; $z++)
for($x = 0; $x < 8; $x++)
{
	$base = $large->node(3, $x, $z);

	if($x % 2 === 0 && $z % 2 === 0 && $refined++ < 13)
	{
		for($cz = 0; $cz < 2; $cz++)
		for($cx = 0; $cx < 2; $cx++)
		{
			$child = $large->node(4, $x * 2 + $cx, $z * 2 + $cz);

			if($cx === 1 && $cz === 1)
			for($dz = 0; $dz < 2; $dz++)
			for($dx = 0; $dx < 2; $dx++)
			$fixture[] = $large->node(5, $child['ix'] * 2 + $dx, $child['iz'] * 2 + $dz);
			else $fixture[] = $child;
		}
	}
	else $fixture[] = $base;
}

$originalLod = renderGet($scene, 'lod');
renderSet($scene, 'lod', $large);
$balanced = renderCall($scene, 'balance', $fixture);
renderCover($balanced, 1024);
renderSet($scene, 'lod', $originalLod);

// Shared edge/corner weights must agree even when adjacent sibling groups began on different frames.
$oldDraw = renderGet($scene, 'draw');
$oldClock = renderGet($scene, 'clock');
$oldActivated = renderGet($scene, 'activated');
$a = $lod->node(1, 0, 0);
$b = $lod->node(1, 1, 0);
$c = $lod->node(1, 0, 1);
$d = $lod->node(1, 1, 1);
renderSet($scene, 'draw', [$a,$b,$c,$d]);
renderSet($scene, 'neighbors', []);
renderSet($scene, 'settledBoundaries', []);
renderSet($scene, 'drawIndex', null);
renderSet($scene, 'clock', 1.0);
renderSet($scene, 'activated', [$a['key'] => .8,$b['key'] => .9,$c['key'] => .75,$d['key'] => .95]);
renderSet($scene, 'retiring', []);
$am = renderCall($scene, 'boundaryMorph', $a);
$bm = renderCall($scene, 'boundaryMorph', $b);
$cm = renderCall($scene, 'boundaryMorph', $c);
$dm = renderCall($scene, 'boundaryMorph', $d);
renderCheck($am[0][1] === $bm[0][0], 'same-level shared edge uses identical morph weight');

renderCheck(
	$am[1][3] === $bm[1][2] && $am[1][3] === $cm[1][1] && $am[1][3] === $dm[1][0]
	, 'all four touching corner vertices share one morph weight'
);

renderSet($scene, 'clock', 2.0);
$settled = renderCall($scene, 'boundaryMorph', $a);
renderCheck($settled === [[0.,0.,0.,0.],[0.,0.,0.,0.],0], 'settled siblings have unblended boundaries');
renderSet($scene, 'retiring', [$root['key'] => ['start' => 1.8]]);
$coarsening = renderCall($scene, 'boundaryMorph', $a);
renderCheck($coarsening[0][1] > 0.5, 'coarsening resumes boundary blending on a settled cover');
renderSet($scene, 'retiring', []);

// Both positive-length neighbors stitch; a coarser neighbor across a corner alone does not.
$fractional = new Lod(1000.3, 1);
$corner = $fractional->node(2, 1, 1);
renderSet($scene, 'draw', [$corner,$fractional->node(1,1,0),$fractional->node(1,0,1),$fractional->node(1,1,1)]);
renderSet($scene, 'neighbors', []);
renderSet($scene, 'settledBoundaries', []);
renderSet($scene, 'drawIndex', null);
renderCheck(renderCall($scene,'boundaryMorph',$corner)[2] === 10, 'stitch right and bottom on a fractional world extent');
renderSet($scene, 'draw', $oldDraw);
renderSet($scene, 'neighbors', []);
renderSet($scene, 'settledBoundaries', []);
renderSet($scene, 'drawIndex', null);
renderSet($scene, 'clock', $oldClock);
renderSet($scene, 'activated', $oldActivated);

// Reconstructed parent normals are retained compactly and use the same triangle basis as height.
$patches = renderGet($scene, 'patches');

foreach($patches as $patch)
renderCheck(strlen($patch['normals']) === 1089 * 12, 'patch retains bounded packed parent-normal samples');

// Match the actual packed vertex data across a fine/coarse edge on curved terrain.
$curved = new Landscape(['worldSize' => 256,'heightScale' => 0,'detailAmplitude' => 0]);
$curved->queue(['type' => 'raise','x' => -10,'z' => -75,'radius' => 90,'amount' => 40]);
$curved->commit();
$builder = new ReflectionMethod($scene, 'buildVertex');
$parents = [];

foreach([$lod->node(1, 0, 0),$lod->node(1, 1, 0)] as $node)
{
	$job = $node + ['index' => 0,'bytes' => '','heights' => []];

	while($job['index'] < 1089)
	{
		$args = [&$job,$curved,[]];
		$builder->invokeArgs($scene, $args);
	}

	$parents[$node['key']] = $job;
}

$left = $lod->node(2, 1, 0);
$right = $lod->node(2, 2, 0);
$children = [];

foreach([$left,$right] as $node)
{
	$job = $node + ['index' => 0,'bytes' => '','heights' => []];

	while($job['index'] < 1089)
	{
		$args = [&$job,$curved,$parents];
		$builder->invokeArgs($scene, $args);
	}

	$children[$node['key']] = $job;
}

for($z = 0; $z <= 32; $z++)
{
	$vertices = [];

	foreach([[$left,32],[$right,0]] as [$node,$x])
	{
		$vertices[] = array_values(unpack('f11', substr($children[$node['key']]['bytes'], ($z * 33 + $x) * 44, 44)));
	}

	[$a,$b] = $vertices;

	foreach([1,3,4,5,6,8,9,10] as $field)
	renderCheck(abs($a[$field] - $b[$field]) < 1e-6, 'same-level boundary vertex height and both normal bases match');
	$coarse = $parents['1:1:0'];
	$row = intdiv($z, 2);
	$fraction = ($z % 2) / 2;
	$next = min(32, $row + 1) * 33;
	$expected = $coarse['heights'][$row * 33] * (1 - $fraction) + $coarse['heights'][$next] * $fraction;
	renderCheck(abs($a[6] - $expected) < 1e-5, 'fine boundary parent height lies on the coarse edge segment');
	$n0 = array_values(unpack('f3', substr($coarse['normals'], $row * 33 * 12, 12)));
	$n1 = array_values(unpack('f3', substr($coarse['normals'], min(32, $row + 1) * 33 * 12, 12)));

	for($axis = 0; $axis < 3; $axis++)
	renderCheck(
		abs($a[8 + $axis] - ($n0[$axis] * (1 - $fraction) + $n1[$axis] * $fraction)) < 1e-6
		, 'fine collapsed normal matches coarse edge interpolation'
	);

	foreach([0.0,.25,.7,1.0] as $weight)
	renderCheck(
		abs(($a[1] * (1 - $weight) + $a[6] * $weight) - ($b[1] * (1 - $weight) + $b[6] * $weight)) < 1e-6
		, 'coordinated boundary morph remains position-continuous throughout transition'
	);
}

$candidate = Landscape::load($surface->save());
$candidate->queue(['type' => 'raise','x' => 0,'z' => 0,'radius' => 6,'amount' => 10]);
$candidate->commit();
$oldRevision = $scene->stats()['revision'];
$scene->prepareLandscape($candidate);
renderCheck($scene->takePreparedLandscape() === null, 'partially prepared edit cannot publish');

renderCheck(
	$scene->stats()['revision'] === $oldRevision && $surface->height(0, 0) === 0.0
	, 'old physical and visible revision persists during preparation'
);

for($i = 0; $i < 100 && $scene->stats()['pendingEdits']; $i++)
{
	$scene->update(.1, $camera);

	renderCheck(
		$scene->stats()['reservedPatches'] <= $scene->stats()['maximumPatches']
		, 'staging shares the hard patch budget'
	);

	renderCover(renderGet($scene, 'draw'), 256);
}

renderCheck($scene->takePreparedLandscape() === $candidate, 'completed replacement publishes candidate exactly once');

renderCheck(
	$scene->takePreparedLandscape() === null && $scene->stats()['revision'] === $candidate->revision()
	, 'publication advances scene revision atomically'
);

renderCheck(renderGet($scene, 'landscape') === $candidate, 'rendering now samples the published physics surface');
$surface = $candidate;

$stale = Landscape::load($surface->save());
$stale->queue(['type' => 'raise','x' => 4,'z' => 4,'radius' => 3,'amount' => 2]);
$stale->commit();
$scene->prepareLandscape($stale);
$stale->queue(['type' => 'raise','x' => 4,'z' => 4,'radius' => 3,'amount' => 3]);
$stale->commit();

try
{
	$scene->update(.1, $camera);
	renderCheck(false, 'stale candidate must be rejected');
}catch(LogicException)
{
	renderCheck(true, 'stale candidate aborts before publishing mixed meshes');
}

renderCheck($scene->takePreparedLandscape() === null, 'stale candidate is no longer publishable');
$identical = Landscape::load($surface->save());
$scene->prepareLandscape($identical);

renderCheck(
	$scene->stats()['pendingEdits'] === 0 && $scene->takePreparedLandscape() === $identical
	, 'identical committed edits need no geometry work'
);

$surface = $identical;

$camera->position = [0,50000,0];

for($i = 0; $i < 100; $i++)
{
	$scene->update(.1, $camera);
	renderCover(renderGet($scene, 'draw'), 256);
}

renderCheck($scene->stats()['visible'] === 1, 'reverse sibling morphs eventually coarsen to root: ' . json_encode([
	'stats' => $scene->stats()
	, 'draw' => array_column(renderGet($scene, 'draw'), 'key')
	, 'retiring' => renderGet($scene, 'retiring')
]));

$idleSelections = $scene->stats()['selections'];

for($i = 0; $i < 30; $i++)$scene->update(.1, $camera);
renderCheck($scene->stats()['selections'] === $idleSelections, 'settled unchanged frames do not repeat LOD selection');
$scene->maximumPatches = 5;
$camera->position = [0,10,0];

for($i = 0; $i < 40; $i++)
{
	$scene->update(.1, $camera);
	renderCheck($scene->stats()['reservedPatches'] <= 5, 'lower capacity coarsens within the protected cache');
	renderCover(renderGet($scene, 'draw'), 256);
}

$resources = fn() => array_map(
	'count'
	, array_intersect_key($GLOBALS['terrainGl'], array_flip([
		'buffers'
		, 'vaos'
		, 'textures'
		, 'programs'
		, 'framebuffers'
		, 'renderbuffers'
	]))
);

$before = $resources();

foreach(['buffers','vaos','textures','programs','framebuffers','renderbuffers'] as $kind)
$GLOBALS['terrainGl'][$kind] = [];
$scene->restore();
renderCheck($resources() === $before, 'context restoration recreates ownership after old context names expire');
$cancelled = Landscape::load($surface->save());
$cancelled->queue(['type' => 'raise','x' => 10,'z' => 10,'radius' => 3,'amount' => 1]);
$cancelled->commit();
$scene->prepareLandscape($cancelled);
$scene->update(.1, $camera);

renderCheck(
	$scene->cancelPreparedLandscape() === $cancelled && !$cancelled->stats()['closed']
	, 'cancelling preparation preserves caller ownership of candidate'
);

renderCheck($scene->takePreparedLandscape() === null, 'cancelled candidate cannot publish');
$scene->prepareLandscape($cancelled);
$scene->close();
$scene->close();
renderCheck(array_sum($resources()) === 0, 'close releases all scene GL ownership exactly once');

// The ground program keeps the appearance between frames. A replacement or a relinked program uploads it again.
$appearanceScene = new Scene(new Landscape(['preset' => 'mountain', 'worldSize' => 256]), new Water(0), new Atmosphere);
$appearanceCamera = new Camera;

/**
 * Read what the appearance scene's ground program last received.
 * @param string $name Uniform name.
 * @return array|null Values of the last upload, or null before one.
 */
$sent = static function(string $name) use (&$appearanceScene): ?array {
	return $GLOBALS['terrainGl']['uniforms'][renderGet($appearanceScene, 'groundProgram')->id][$name] ?? null;
};

/**
 * Count the appearance scene's uploads of one uniform to its current ground program.
 * @param string $name Uniform name.
 * @return int Uploads since the program was linked.
 */
$uploads = static function(string $name) use (&$appearanceScene): int {
	return $GLOBALS['terrainGl']['uploads'][renderGet($appearanceScene, 'groundProgram')->id][$name] ?? 0;
};

/**
 * Check that the ground program holds every uniform of an appearance.
 * @param TerrainAppearance $appearance Appearance the program should hold.
 * @param string $message Description of the expectation.
 * @return void Records one check per uniform.
 */
$holds = static function(TerrainAppearance $appearance, string $message) use ($sent): void {
	foreach($appearance->uniforms() as $name => $values)
	renderCheck($sent($name) === (count($values) > 4 ? [count($values) / 3, $values] : $values), "$message: $name");
};

renderCheck(
	$appearanceScene->appearance->settings === TerrainAppearance::DEFAULTS
	, 'a scene starts with the default terrain appearance'
);

$appearanceScene->render($appearanceCamera, 320, 200, 0);
$holds($appearanceScene->appearance, 'the first frame uploads the default appearance');
$appearanceScene->render($appearanceCamera, 320, 200, 0);
renderCheck($uploads('uSnowColor') === 1 && $uploads('uCanyonColors[0]') === 1, 'an unchanged appearance stays put');
renderCheck($uploads('uEye') === 2, 'per-frame uniforms still upload every frame');

$replacement = ['snowColor' => '#ff0000', 'snowline' => 150, 'bandSpacing' => 20];
$appearanceScene->appearance = new TerrainAppearance($replacement);
$appearanceScene->render($appearanceCamera, 320, 200, 0);
$holds($appearanceScene->appearance, 'the next frame uploads a replaced appearance');
renderCheck($sent('uSnowColor') === [1.0, 0.0, 0.0] && $sent('uSnowline') === [150.0, 65.0], 'the new values arrive');
$appearanceScene->render($appearanceCamera, 320, 200, 0);
renderCheck($uploads('uSnowColor') === 2, 'a replaced appearance uploads once');

// The lit ground program receives the appearance the first frame it draws, and each program keeps its own copy.
$appearanceScene->setSpotLights([$spotLight]);
$appearanceScene->render($appearanceCamera, 320, 200, 0);
$litGround = renderGet($appearanceScene, 'litGroundProgram')->id;

renderCheck(
	$GLOBALS['terrainGl']['uniforms'][$litGround]['uSnowColor'] === [1.0, 0.0, 0.0]
	&& $GLOBALS['terrainGl']['uploads'][$litGround]['uSnowColor'] === 1
	, 'the lit ground program receives the appearance when it first draws'
);

$appearanceScene->setSpotLights([]);
$appearanceScene->render($appearanceCamera, 320, 200, 0);
$appearanceScene->setSpotLights([$spotLight]);
$appearanceScene->render($appearanceCamera, 320, 200, 0);

renderCheck(
	$uploads('uSnowColor') === 2 && $GLOBALS['terrainGl']['uploads'][$litGround]['uSnowColor'] === 1
	, 'switching between the lamp-free and the lit ground programs uploads no appearance again'
);

$appearanceScene->setSpotLights([]);

$relinked = renderGet($appearanceScene, 'groundProgram')->id;
$appearanceScene->restore();
renderCheck(renderGet($appearanceScene, 'groundProgram')->id !== $relinked, 'restoration relinks the ground program');
renderCheck($sent('uSnowColor') === null, 'a relinked program starts without the appearance');
$appearanceScene->render($appearanceCamera, 320, 200, 0);
$holds($appearanceScene->appearance, 'the frame after restoration uploads the appearance again');
$appearanceScene->close();

// Every preset's shading style receives the same appearance uniforms.
$styles = [
	[['preset' => 'coast'], 0.0], [['preset' => 'mountain'], 1.0], [['preset' => 'canyon', 'version' => 1], 2.0],
	[['preset' => 'canyon'], 3.0],
];

foreach($styles as [$recipe, $style])
{
	$appearanceScene = new Scene(new Landscape($recipe + ['worldSize' => 256]), new Water(0), new Atmosphere);
	$appearanceScene->appearance = new TerrainAppearance(['rockColor' => '#123456', 'canyonCapColor' => '#abcdef']);
	$appearanceScene->render($appearanceCamera, 320, 200, 0);
	renderCheck($sent('uTerrainStyle') === [$style], "the {$recipe['preset']} recipe draws terrain style $style");
	$holds($appearanceScene->appearance, "terrain style $style receives the appearance");
	$appearanceScene->close();
}

echo "Terrain renderer: $checks checks passed.\n";
