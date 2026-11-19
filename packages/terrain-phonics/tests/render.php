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

function glUseProgram(...$args): void{}

function glGetUniformLocation(...$args): int{return 1;}

function glUniform1i(...$args): void{}

function glUniform1f(...$args): void{}

function glUniform2f(...$args): void{}

function glUniform3f(...$args): void{}

function glUniform4f(...$args): void{}
function glUniform2fv(...$args): void{}
function glUniform4fv(...$args): void{}

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
function glDrawElements(...$args): void{$GLOBALS['terrainGl']['draws']++;}
function glDrawArrays(...$args): void{$GLOBALS['terrainGl']['draws']++;}

use TerrainPhonics\Atmosphere;
use TerrainPhonics\BrowserPatchBuilder;
use TerrainPhonics\Camera;
use TerrainPhonics\GpuPatch;
use TerrainPhonics\Landscape;
use TerrainPhonics\Lod;
use TerrainPhonics\Scene;
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

$surface = new Landscape(['worldSize' => 256,'heightScale' => 0,'detailAmplitude' => 0,'maxTiles' => 32]);
$water = new Water;
$scene = new Scene($surface, $water, new Atmosphere);
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

echo "Terrain renderer: $checks checks passed.\n";
