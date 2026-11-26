<?php
declare(strict_types=1);

namespace TerrainPhonicsTests
{

	/** Minimal GL/resource spies for cache scheduling and lifecycle, not shader validation. */
	final class ShadowCalls
	{
		public static int $draws = 0, $framebuffer = 73, $handle = 0, $programCloses = 0, $targetCloses = 0;
		public static array $locations = [], $uniforms = [], $arrays = [];
		public static bool $failDraw = false;
	}
}

namespace Phonics\Render
{
	use TerrainPhonicsTests\ShadowCalls;

	final class Program
	{

		public function __construct(string $vertex, string $fragment) {}

		public function use(): void {}

		public function restore(): void {}

		public function close(): void { ShadowCalls::$programCloses++; }

		public function location(string $name): int
		{
			return ShadowCalls::$locations[$name] ??= count(ShadowCalls::$locations);
		}
	}

	final class RenderTarget
	{
		private int $id, $parent = 0;

		public function __construct(int $width, int $height, int $filter, bool $depth, string $label)
		{
			$this->id = ++ShadowCalls::$handle;
		}

		public function texture(): int { return $this->id; }

		public function bind(): void
		{
			$this->parent = ShadowCalls::$framebuffer;
			ShadowCalls::$framebuffer = $this->id;
		}

		public function unbind(): void { ShadowCalls::$framebuffer = $this->parent; }

		public function restore(): void { $this->id = ++ShadowCalls::$handle; }

		public function close(): void { ShadowCalls::$targetCloses++; }
	}
}

namespace TerrainPhonics
{
	use TerrainPhonicsTests\ShadowCalls;

	function glGenVertexArrays(int $count, array &$arrays): void
	{
		$arrays = [++ShadowCalls::$handle];
		ShadowCalls::$arrays[$arrays[0]] = true;
	}

	function glDeleteVertexArrays(int $count, array $arrays): void { unset(ShadowCalls::$arrays[$arrays[0]]); }

	function glDisable(int $flag): void {}

	function glDepthMask(bool $mask): void {}

	function glBindVertexArray(int $handle): void {}

	function glActiveTexture(int $unit): void {}

	function glBindTexture(int $target, int $texture): void {}

	function glGenerateMipmap(int $target): void {}

	function glTexParameteri(int $target, int $name, int $value): void {}

	function glUniform1i(int $location, int $value): void { ShadowCalls::$uniforms[$location] = $value; }

	function glUniform1f(int $location, float $value): void { ShadowCalls::$uniforms[$location] = $value; }

	function glUniform2f(int $location, float $x, float $y): void { ShadowCalls::$uniforms[$location] = [$x,$y]; }

	function glUniform3f(int $location, float $x, float $y, float $z): void
	{
		ShadowCalls::$uniforms[$location] = [$x,$y,$z];
	}

	function glDrawArrays(int $mode, int $first, int $count): void
	{
		if(ShadowCalls::$failDraw) throw new \RuntimeException('Injected cache draw failure');

		ShadowCalls::$draws++;
	}
}

namespace
{
	require dirname(__DIR__) . '/autoload.php';
	use TerrainPhonics\TerrainShadows;
	use TerrainPhonics\TriangleShadows;
	use TerrainPhonicsTests\ShadowCalls;
	use Phonics\Render\Program;

	foreach([
		'GL_LINEAR', 'GL_TEXTURE_MIN_FILTER', 'GL_LINEAR_MIPMAP_LINEAR'
		, 'GL_NEAREST', 'GL_DITHER'
		, 'GL_DEPTH_TEST'
		, 'GL_CULL_FACE'
		, 'GL_BLEND'
		, 'GL_SCISSOR_TEST'
		, 'GL_TEXTURE0'
		, 'GL_TEXTURE_2D','GL_TRIANGLES'
	] as $index => $constant)
	if(!defined($constant)) define($constant, $index + 1);

	$checks = 0;

	$expect = static function(bool $condition, string $message) use (&$checks): void {
		$checks++;

		if(!$condition) throw new RuntimeException($message);
	};

	$uniform = static fn(string $name) => ShadowCalls::$uniforms[ShadowCalls::$locations[$name]];
	$cache = new TerrainShadows;
	$consumer = new Program('', '');
	$cache->bind($consumer);
	$expect($uniform('uTerrainShadowReady') === 0, 'new map cannot be sampled before a successful render');
	$expect($uniform('uTerrainShadowMap') === 6, 'broad shadows reserve texture unit six');
	$expect(!$cache->update(0, 32768, [0,1,0], 1) && ShadowCalls::$draws === 0, 'missing root height map skips work');
	$expect($cache->update(99, 32768, [0,1,0], 1), 'first root height map generates visibility');
	$expect(ShadowCalls::$draws === 1 && $cache->stats()['generation'] === 1, 'one bounded pass populates the map');
	$expect(ShadowCalls::$framebuffer === 73, 'generation restores the caller framebuffer');
	$expect(!$cache->update(99, 32768, [0,2,0], 1), 'normalized identical sunlight reuses visibility');
	$expect(!$cache->update(99, 32768, [.001,1,0], 1), 'a small clock step reuses the broad shadow map');
	$expect(!$cache->update(99, 32768, [0,1,0], 1), 'unchanged frames never repeat the coarse march');
	$cache->bind($consumer);
	$expect($uniform('uTerrainShadowReady') === 1, 'valid visibility enables filtered sampling');
	$cache->update(99, 32768, [0,1,0], 2);
	$expect($cache->stats()['generation'] === 2, 'an uploaded terrain edit invalidates the cache');
	$cache->update(99, 32768, [1,.1,0], 2);
	$expect($cache->stats()['generation'] === 3, 'sun direction changes invalidate broad shadows');
	$cache->update(99, 16384, [1,.1,0], 2);

	$expect(
		$cache->stats()['generation'] === 4 && $cache->stats()['extent'] === 16384.0
		, 'extent changes regenerate mapping'
	);

	$cache->update(100, 16384, [1,.1,0], 2);
	$expect($cache->stats()['generation'] === 5, 'replacement height textures invalidate the cache');
	$cache->update(0, 16384, [1,.1,0], 2);
	$expect(!$cache->stats()['ready'], 'removing the root height field disables stale shadows');
	$cache->update(100, 16384, [1,.1,0], 2);
	$cache->restore();
	$expect(!$cache->stats()['ready'], 'context restoration invalidates lost visibility contents');
	$cache->update(100, 16384, [1,.1,0], 2);
	$expect($cache->stats()['generation'] === 7, 'restored resources are regenerated explicitly');
	ShadowCalls::$failDraw = true;

	try
	{
		$cache->update(100, 16384, [1,.1,0], 3);
		$expect(false, 'draw failure must propagate');
	}
	catch(RuntimeException $error)
	{
		$expect(
			ShadowCalls::$framebuffer === 73 && !$cache->stats()['ready']
			, 'failed generation restores the target and invalidates data'
		);
	}

	ShadowCalls::$failDraw = false;
	$cache->update(100, 16384, [1,.1,0], 3);
	$expect($cache->stats()['generation'] === 8, 'the next explicit update can rebuild failed content');

	$expect(
		$cache->update(100, 16384, [1,.1,0], 3, false, -300., 500.)
		, 'changing the encoded height range refreshes the map'
	);

	$cache->bind($consumer);
	$expect($uniform('uShadowHeightRange') === [-300.,800.], 'producer and receiver use the same height scale');

	$expect(
		!$cache->update(100, 16384, [1,.101,0], 3, false, -300., 500.)
		, 'small changes accumulate from the last rendered direction'
	);

	$expect(
		$cache->update(100, 16384, [1,.102,0], 3, false, -300., 500.)
		, 'accumulated sun drift crosses the threshold'
	);

	$cache->update(100, 16384, [1,.00001,0], 3);

	$expect(
		$cache->update(100, 16384, [1,-.00001,0], 3)
		, 'sunset crosses the horizon even within the angular tolerance'
	);

	$expect(!$cache->update(100, 16384, [1,-.5,0], 3), 'moving the sun at night reuses the dark map');
	$expect($cache->update(100, 16384, [1,.00001,0], 3), 'sunrise refreshes the map');

	try
	{
		$cache->update(100, 16384, [0,0,0], 3);
		$expect(false, 'zero sun direction must be rejected');
	}
	catch(InvalidArgumentException $error)
	{
		$expect(true, 'zero sun direction is rejected');
	}

	$cache->close();
	$cache->close();
	$cache->restore();

	$expect(
		ShadowCalls::$programCloses === 1 && ShadowCalls::$targetCloses === 1
		, 'each owned GPU wrapper closes once'
	);

	$expect(ShadowCalls::$arrays === [] && !$cache->stats()['ready'], 'vertex arrays and visibility are released');
	$triangles = new TriangleShadows;
	$level = ['step' => 8.,'textures' => [17,18]];
	$eye = [0.,0.,0.];
	$sun = [0.,1.,0.];

	$update = static fn(int $revision) => $triangles->update(
		$level
		, 184
		, $eye
		, $sun
		, 99
		, 32768.
		, false
		, 0.
		, $revision
		, 12
	);

	$draws = ShadowCalls::$draws;
	$update(1);
	$triangles->bind($consumer);

	$expect(
		ShadowCalls::$draws === $draws + 2 && $uniform('uShadowGrid')[2] > 0
		, 'One vertex pass and one refinement pass populate a shared triangle cache'
	);

	$update(1);
	$triangles->update($level, 184, [15.,70.,-20.], [0.,cos(.001),sin(.001)], 99, 32768., false, 0., 1, 12);

	$expect(
		$triangles->generation === 1 && ShadowCalls::$draws === $draws + 2
		, 'Small camera moves and sub-tolerance sun drift reuse both passes'
	);

	$triangles->update($level, 184, [70.,0.,0.], $sun, 99, 32768., false, 0., 1, 12);
	$expect($triangles->generation === 2, 'Crossing a grid block regenerates the cache');
	$triangles->update($level, 184, [70.,0.,0.], [1.,0.,0.], 99, 32768., false, 0., 1, 12);
	$expect($triangles->generation === 3, 'Turning the sun regenerates the cache at the same position');
	$triangles->update($level, 184, [70.,0.,0.], [1.,0.,0.], 99, 32768., false, 0., 2, 12);
	$expect($triangles->generation === 4, 'Changing the terrain revision refreshes stationary shadows');
	$triangles->invalidate();
	$triangles->bind($consumer);
	$expect($uniform('uShadowGrid')[2] === 0., 'Night or a missing resident level disables stale local shadows');
	ShadowCalls::$failDraw = true;

	try
	{
		$update(3);
		$expect(false, 'Failed local draws must propagate');
	}
	catch(RuntimeException $error)
	{
		$triangles->bind($consumer);

		$expect(
			ShadowCalls::$framebuffer === 73 && $uniform('uShadowGrid')[2] === 0.
			, 'A failed pass restores its parent and disables partial results'
		);
	}

	ShadowCalls::$failDraw = false;
	$update(3);
	$expect($triangles->generation === 5, 'Local shadows recover after an interrupted rebuild');
	$eye = [31.9,0.,-31.9];
	$triangles->update($level, 24, $eye, $sun, 99, 32768., false, 0., 3, 12);
	$triangles->bind($consumer);
	[$left,$front,$cell] = $uniform('uShadowGrid');

	$expect(
		max(abs(($eye[0] - $left) / $cell - 32), abs(($eye[2] - $front) / $cell - 32)) <= 4
		, 'Even the smallest caster level leaves 28 probe cells around the eye after snapping'
	);

	$triangles->close();
	echo "Terrain shadows: {$checks} checks passed\n";
}
