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
	, 'GL_TRIANGLES'
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
use TerrainPhonics\Camera;
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
$flightCamera = new Camera;
$flightCamera->position = [-4000,20,20];

for($frame = 0; $frame < 100; $frame++) $flightScene->update(.1, $flightCamera);
$flightLevels = [];
$prefetchPeak = 0;

for($frame = 0; $frame < 120; $frame++)
{
	$flightCamera->position[0] += 5;
	$flightScene->update(1 / 60, $flightCamera);
	$flightStats = $flightScene->stats();
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
renderCheck($settled === [[0.,0.,0.,0.],[0.,0.,0.,0.]], 'settled siblings have unblended boundaries');
renderSet($scene, 'retiring', [$root['key'] => ['start' => 1.8]]);
$coarsening = renderCall($scene, 'boundaryMorph', $a);
renderCheck($coarsening[0][1] > 0.5, 'coarsening resumes boundary blending on a settled cover');
renderSet($scene, 'retiring', []);

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
