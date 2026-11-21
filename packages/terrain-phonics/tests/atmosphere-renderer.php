<?php
declare(strict_types=1);

namespace TerrainPhonicsTests
{

	/** Minimal GL/resource spies for cache scheduling and lifecycle, not shader validation. */
	final class SkyCalls
	{
		public static int $draws = 0, $framebuffer = 73, $handle = 0, $programCloses = 0, $targetCloses = 0;
		public static array $locations = [], $uniforms = [], $arrays = [];
		public static bool $failDraw = false;
	}
}

namespace Phonics\Render
{
	use TerrainPhonicsTests\SkyCalls;

	final class Program
	{

		public function __construct(string $vertex, string $fragment) {}

		public function use(): void {}

		public function restore(): void {}

		public function close(): void { SkyCalls::$programCloses++; }

		public function location(string $name): int
		{
			return SkyCalls::$locations[$name] ??= count(SkyCalls::$locations);
		}
	}

	final class RenderTarget
	{
		private int $id, $parent = 0;

		public function __construct(int $width, int $height, int $filter, bool $depth, string $label)
		{
			$this->id = ++SkyCalls::$handle;
		}

		public function texture(): int { return $this->id; }

		public function bind(): void
		{
			$this->parent = SkyCalls::$framebuffer;
			SkyCalls::$framebuffer = $this->id;
		}

		public function unbind(): void { SkyCalls::$framebuffer = $this->parent; }

		public function restore(): void { $this->id = ++SkyCalls::$handle; }

		public function close(): void { SkyCalls::$targetCloses++; }
	}
}

namespace TerrainPhonics
{
	use TerrainPhonicsTests\SkyCalls;

	function glGenVertexArrays(int $count, array &$arrays): void
	{
		$arrays = [++SkyCalls::$handle];
		SkyCalls::$arrays[$arrays[0]] = true;
	}

	function glDeleteVertexArrays(int $count, array $arrays): void { unset(SkyCalls::$arrays[$arrays[0]]); }

	function glDisable(int $flag): void {}

	function glDepthMask(bool $mask): void {}

	function glBindVertexArray(int $handle): void {}

	function glActiveTexture(int $unit): void {}

	function glBindTexture(int $target, int $texture): void {}

	function glUniform1i(int $location, int $value): void { SkyCalls::$uniforms[$location] = $value; }

	function glUniform1f(int $location, float $value): void { SkyCalls::$uniforms[$location] = $value; }

	function glUniform2f(int $location, float $x, float $y): void { SkyCalls::$uniforms[$location] = [$x,$y]; }

	function glUniform3f(int $location, float $x, float $y, float $z): void
	{
		SkyCalls::$uniforms[$location] = [$x,$y,$z];
	}

	function glDrawBuffers(int $count, array $buffers): void {}

	function glFramebufferTexture2D(...$args): void {}

	function glDrawArrays(int $mode, int $first, int $count): void
	{
		if(SkyCalls::$failDraw) throw new \RuntimeException('Injected cache draw failure');

		SkyCalls::$draws++;
	}
}

namespace
{
	require dirname(__DIR__) . '/autoload.php';

	use TerrainPhonics\Atmosphere;
	use TerrainPhonics\AtmosphereRenderer;
	use TerrainPhonicsTests\SkyCalls;
	use Phonics\Render\Program;

	foreach([
		'GL_NEAREST'
		, 'GL_DEPTH_TEST'
		, 'GL_CULL_FACE'
		, 'GL_BLEND'
		, 'GL_SCISSOR_TEST'
		, 'GL_TEXTURE0'
		, 'GL_TEXTURE_2D','GL_TRIANGLES','GL_FRAMEBUFFER','GL_COLOR_ATTACHMENT0'
	] as $index => $constant)
	if(!defined($constant)) define($constant, $index + 1);

	$checks = 0;

	$expect = static function(bool $condition, string $message) use (&$checks): void {
		$checks++;

		if(!$condition) throw new RuntimeException($message);
	};

	$uniform = static fn(string $name) => SkyCalls::$uniforms[SkyCalls::$locations[$name]];
	$model = new Atmosphere;
	$cache = new AtmosphereRenderer($model);
	$consumer = new Program('', '');
	$cache->bind($consumer);
	$expect($uniform('uSkyCacheReady') === 0, 'unpopulated cache selects the numerical fallback');
	$expect($uniform('uSkyCacheEye') === 2 && $uniform('uSkyCacheWater') === 3, 'cache reserves the requested units');

	try
	{
		$cache->update(10, 99);
		$expect(false, 'missing transmittance data is rejected');
	}
	catch(LogicException $error)
	{
		$expect(SkyCalls::$draws === 0, 'missing LUT never draws invalid data');
	}

	$model->transmittanceLut(8, 4, 8);
	$expect($cache->update(10, 99), 'first update populates both maps');
	$expect($cache->stats()['generations'] === [1,1] && SkyCalls::$draws === 3, 'two sky passes and one joint aerial integration');
	$expect($cache->stats()['aerialGeneration'] === 1, 'first update also builds the eye aerial atlas');
	$expect(SkyCalls::$framebuffer === 73, 'cache update restores the caller framebuffer');
	$cache->bind($consumer);
	$expect($uniform('uSkyCacheReady') === 1, 'populated cache enables cached sampling');
	$expect(!$cache->update(10, 99) && SkyCalls::$draws === 3, 'unchanged frames do no integration work');
	$model->setNightGlow(.9);
	$expect(!$cache->update(10, 99), 'adjusting night glow reuses the physical caches');
	$model->setExposure(3);
	$expect(!$cache->update(10, 99), 'display exposure does not invalidate physical radiance');
	$expect(!$cache->update(10.1, 99), 'small vertical movement reuses half-meter altitude buckets');
	$cache->update(10.3, 99);
	$expect($cache->stats()['generations'] === [2,1], 'camera altitude only rebuilds the eye map');
	$expect($cache->stats()['aerialGeneration'] === 2, 'camera altitude also rebuilds eye aerial perspective');
	$expect($cache->stats()['altitudes'] === [10.5,0.0], 'reported altitudes match the rendered heights');
	$cache->update(10.3, 99, 2);
	$expect($cache->stats()['generations'] === [2,2], 'water level only rebuilds the reflected sky map');
	$expect($cache->stats()['aerialGeneration'] === 2, 'water level preserves the eye aerial atlas');
	$model->setSun([1,.1,0]);
	$cache->update(10.3, 99, 2);
	$expect($cache->stats()['generations'] === [3,3], 'sun motion rebuilds both lighting maps');
	$model->setHaze(2);
	$model->transmittanceLut(8, 4, 8);
	$cache->update(10.3, 99, 2);
	$expect($cache->stats()['generations'] === [4,4], 'new scattering parameters rebuild both maps');
	$cache->restore();
	$cache->bind($consumer);
	$expect(!$cache->stats()['ready'] && $uniform('uSkyCacheReady') === 0, 'restoration invalidates lost contents');
	$cache->update(10.3, 100, 2);
	$expect($cache->stats()['generations'] === [5,5], 'restored context receives fresh maps');
	SkyCalls::$failDraw = true;

	try
	{
		$cache->update(30, 100, 2);
		$expect(false, 'injected draw error propagates');
	}
	catch(RuntimeException $error)
	{
		$expect(SkyCalls::$framebuffer === 73, 'failed integration also restores the caller framebuffer');
		$expect($cache->stats()['generations'] === [5,5], 'failed integration does not publish an updated cache');
	}

	SkyCalls::$failDraw = false;
	$cache->update(30, 100, 2);
	$expect($cache->stats()['generations'] === [6,5], 'failed altitude is regenerated on the next explicit update');
	$cache->close();
	$cache->close();
	$cache->restore();
	$expect(SkyCalls::$programCloses === 2 && SkyCalls::$targetCloses === 4, 'close releases each owned object once');
	$expect(SkyCalls::$arrays === [] && !$cache->stats()['ready'], 'close releases vertex arrays and readiness');
	echo "Atmosphere renderer: {$checks} checks passed\n";
}
