<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
use PhpWasmEngine\Asset\Assets;

/** PHP selects maps and resolves dependencies; the host only transfers files. */
final class WorldStream
{
	private array $states = [], $references = [];
	public array $entered = [], $exited = [], $errors = [];
	private $request, $release;
	private bool $closed = false;

	/**
	 * Configure proximity loading and retention through optional host transfer callbacks.
	 *
	 * @param World $world Indexed world manifest and shared map loader.
	 * @param (callable(string,bool):(bool|string))|null $request Receives resolved path and retry
	 * flag; true means mounted, false pending, and a string reports failure.
	 * @param (callable(string):void)|null $release Receives a resolved path when its last map
	 * reference is released.
	 * @param float $loadMargin Nonnegative preload distance around the requested rectangle in
	 * pixels.
	 * @param float $retainMargin Eviction margin in pixels, at least as large as loadMargin.
	 * @return void Creates an empty streaming state; no files are requested until update.
	 */
	public function __construct(
		public World $world
		, ?callable $request = null
		, ?callable $release = null
		, public float $loadMargin = 256
		, public float $retainMargin = 768
	){
		if(!is_finite($loadMargin) || !is_finite($retainMargin) || $loadMargin < 0
			|| $retainMargin < $loadMargin
		){
			throw new \InvalidArgumentException('Retention margin must be at least the nonnegative load margin');
		}

		$this->request = $request ??
			/**
			 * Treat dependencies as mounted when no asynchronous transport was supplied.
			 *
			 * @param string $path Requested dependency path, already accessible to PHP.
			 * @param bool $retry Retry flag retained for compatibility with the transport callback
			 * contract.
			 * @return bool Always true for the synchronous filesystem fallback.
			 */
			static fn($path, $retry = false) => true;

		$this->release = $release;
	}

	/**
	 * Request nearby map dependencies, activate ready maps, and evict distant states.
	 *
	 * @param float $x Left world coordinate in pixels.
	 * @param float $y Top world coordinate in pixels.
	 * @param float $width Nonnegative query width in pixels.
	 * @param float $height Nonnegative query height in pixels.
	 * @return void Updates entered, exited, and errors while retaining shared file references.
	 */
	public function update(float $x, float $y, float $width, float $height): void
	{
		if($this->closed)
		{
			return;
		}

		$near = $this->world->entries($x, $y, $width, $height, $this->loadMargin);
		$keep = $this->world->entries($x, $y, $width, $height, $this->retainMargin);
		$this->entered = $this->exited = [];

		foreach($near as $order => $entry)
		{
			$path = Assets::relative($this->world->path, $entry['fileName']);
			$this->states[$path] ??= ['entry' => $entry,'order' => $order,'files' => []
			, 'map' =>
				null
				, 'ready' => false,'failed' => null
			];
		}

		$retained = [];

		foreach($keep as $entry)
		{
			$retained[Assets::relative($this->world->path, $entry['fileName'])] = true;
		}

		// Acquire new dependencies before releasing old maps that may share them.
		foreach($this->states as $path => &$state)
		{
			if(!isset($retained[$path]) || $state['ready'] || isset($this->errors[$path]))
			{
				continue;
			}

			try
			{
				if(!$this->prepare($state, $path))
				{
					continue;
				}

				$state['ready'] = true;
				$this->entered[] = $state['map'];
			}
			catch(\Throwable $error)
			{
				$this->errors[$path] = $error->getMessage();
			}
		}

		unset($state);

		foreach(array_keys($this->states) as $path)
		{
			if(!isset($retained[$path]))
			{
				$this->evict($path);
			}
		}
	}

	/**
	 * Acquire a file reference and poll the host transfer state.
	 *
	 * @param array<string,mixed> $state Map lifecycle record updated by reference with files and
	 * failure attribution.
	 * @param string $path Resolved dependency path to request or poll.
	 * @return bool True when mounted or false while pending; host errors raise RuntimeException.
	 */
	private function available(array &$state, string $path): bool
	{
		if(!isset($state['files'][$path]))
		{
			$state['files'][$path] = true;
			$this->references[$path] = ($this->references[$path] ?? 0) + 1;
		}

		$state['failed'] = $path;
		$result = ($this->request)($path, false);

		if($result === true)
		{
			$state['failed'] = null;

			return true;
		}

		if($result === false)
		{
			$state['failed'] = null;

			return false;
		}

		throw new \RuntimeException($path . ': ' . (string)$result);
	}

	/**
	 * Discover and poll a map's tilesets and images before declaring it ready.
	 *
	 * @param array<string,mixed> $state Map lifecycle record updated by reference as dependencies
	 * become available.
	 * @param string $path Resolved map JSON path.
	 * @return bool True only after the map and all discovered dependencies are available.
	 */
	private function prepare(array &$state, string $path): bool
	{
		if(!$this->available($state, $path))
		{
			return false;
		}

		$state['failed'] = $path;
		$data = $this->world->loader->assets->json($path);
		$state['failed'] = null;
		$ready = true;

		foreach($data['tilesets'] ?? [] as $set)
		{
			if(isset($set['source']))
			{
				$source = Assets::relative($path, $set['source']);

				if(!$this->available($state, $source))
				{
					$ready = false;
					continue;
				}

				$state['failed'] = $source;
				$this->world->loader->assets->json($source);
				$state['failed'] = null;
			}
		}

		if(!$ready)
		{
			return false;
		}

		$state['failed'] = $path;
		$state['map'] ??= $this->world->load($state['entry']);
		$state['failed'] = null;
		$images = [];

		foreach($state['map']->tilesets as $set)
		{
			foreach($set['definitions'] as $tile)
			{
				$images[$tile['image']] = true;
			}
		}

		foreach($state['map']->layers as $layer)
		{
			if($layer->image !== null)
			{
				$images[$layer->image] = true;
			}

			foreach($layer->imageFrames as $frame)
			{
				$images[$frame['image']] = true;
			}
		}

		foreach(array_keys($images) as $image)
		{
			$ready = $this->available($state, $image) && $ready;
		}

		return $ready;
	}

	/**
	 * Collect all ready maps, including retained neighbors outside the current view.
	 *
	 * @return array<string,TileMap> Ready map instances keyed by resolved path in manifest order.
	 */
	public function loaded(): array
	{
		$states = $this->states;

		uasort(
			$states
			, /**
			 * Order streaming states by their original world manifest position.
			 *
			 * @param array<string,mixed> $a First map lifecycle record with an order field.
			 * @param array<string,mixed> $b Second map lifecycle record with an order field.
			 * @return int Negative, zero, or positive comparison of manifest indexes.
			 */
			fn($a, $b) => $a['order'] <=> $b['order']
		);

		$maps = [];

		foreach($states as $path => $state)
		{
			if($state['ready'])
			{
				$maps[$path] = $state['map'];
			}
		}

		return $maps;
	}

	/**
	 * List tracked maps that have not finished loading.
	 *
	 * @return list<string> Resolved map paths, including states currently reporting errors.
	 */
	public function pending(): array
	{
		return array_keys(array_filter(
			$this->states
			, /**
			 * Identify a tracked map that is not ready for activation.
			 *
			 * @param array<string,mixed> $state Map lifecycle record containing its ready flag.
			 * @return bool True while loading or reporting an unresolved failure.
			 */
			fn($state) => !$state['ready']
		));
	}

	/**
	 * Select intersecting maps that are already ready without starting new requests.
	 *
	 * @param float $x Left world coordinate in pixels.
	 * @param float $y Top world coordinate in pixels.
	 * @param float $width Nonnegative query width in pixels.
	 * @param float $height Nonnegative query height in pixels.
	 * @param float $margin Nonnegative expansion on every side in pixels.
	 * @return list<TileMap> Ready maps in manifest painter order.
	 */
	public function visible(float $x, float $y, float $width, float $height, float $margin = 0): array
	{
		$maps = [];

		foreach($this->world->entries($x, $y, $width, $height, $margin) as $entry)
		{
			$path = Assets::relative($this->world->path, $entry['fileName']);

			if($this->states[$path]['ready'] ?? false)
			{
				$maps[] = $this->states[$path]['map'];
			}
		}

		return $maps;
	}

	/**
	 * Check that every manifest map touched by a destination or swept body bounds is ready.
	 *
	 * @param float $x Left world coordinate in pixels.
	 * @param float $y Top world coordinate in pixels.
	 * @param float $width Nonnegative query width in pixels.
	 * @param float $height Nonnegative query height in pixels.
	 * @param float $margin Nonnegative expansion on every side in pixels.
	 * @return bool True when all intersecting maps are loaded; space outside the manifest also
	 * counts as ready.
	 */
	public function isReady(float $x, float $y, float $width, float $height, float $margin = 0): bool
	{
		foreach($this->world->entries($x, $y, $width, $height, $margin) as $entry)
		{
			if(!($this->states[Assets::relative($this->world->path, $entry['fileName'])]['ready']
				?? false
			)){
				return false;
			}
		}

		return true;
	}

	/**
	 * Clear a tracked map error and explicitly retry its failed dependency.
	 *
	 * @param string $fileName Resolved error-map key, or a filename relative to the world manifest.
	 * @return void Resets the failed state so later updates can finish loading it.
	 */
	public function retry(string $fileName): void
	{
		$path = isset($this->states[$fileName])?$fileName:Assets::relative($this->world->path, $fileName);

		if(!isset($this->errors[$path]))
		{
			return;
		}

		$failed = $this->states[$path]['failed'];

		if($failed !== null)
		{
			$this->world->loader->assets->forget($failed);
			($this->request)($failed, true);
		}
		else
		{
			foreach(array_keys($this->states[$path]['files']) as $file)
			{
				$this->world->loader->assets->forget($file);
			}
		}

		$this->world->unload($this->states[$path]['entry']['fileName']);
		$this->states[$path]['map'] = null;
		$this->states[$path]['failed'] = null;
		unset($this->errors[$path]);
	}

	/**
	 * Drop a tracked map and release dependencies whose reference counts reach zero.
	 *
	 * @param string $path Resolved map path currently present in streaming state.
	 * @return void Updates lifecycle notifications, loader caches, file references, and host
	 * releases.
	 */
	private function evict(string $path): void
	{
		$state = $this->states[$path];

		if($state['ready'])
		{
			$this->exited[] = $state['map'];
		}

		$this->world->unload($state['entry']['fileName']);

		foreach(array_keys($state['files']) as $file)
		{
			if(--$this->references[$file] > 0)
			{
				continue;
			}

			unset($this->references[$file]);
			$this->world->loader->assets->forget($file);

			if($this->release)
			{
				($this->release)($file);
			}
		}

		unset($this->states[$path], $this->errors[$path]);
	}

	/**
	 * Evict all tracked maps and stop subsequent proximity updates.
	 *
	 * @return void Releases shared dependencies once and clears lifecycle change lists.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->entered = $this->exited = [];

		foreach(array_keys($this->states) as $path)
		{
			$this->evict($path);
		}

		$this->exited = [];
	}
}
