<?php
declare(strict_types=1);

use PhpWasmEngine\Engine;
use PhpWasmEngine\Gameplay\Badnik;
use PhpWasmEngine\Gameplay\Badniks;
use PhpWasmEngine\Gameplay\BossRegions;
use PhpWasmEngine\Gameplay\BreakableBlocks;
use PhpWasmEngine\Gameplay\CrabMeats;
use PhpWasmEngine\Gameplay\MiniBosses;
use PhpWasmEngine\Gameplay\LightDashes;
use PhpWasmEngine\Gameplay\Monitors;
use PhpWasmEngine\Gameplay\Platforms;
use PhpWasmEngine\Gameplay\Rings;
use PhpWasmEngine\Gameplay\Shields;
use PhpWasmEngine\Gameplay\WaterFeatures;
use PhpWasmEngine\Gameplay\WaterParticles;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\Hit;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Physics\WaterRegions;
use PhpWasmEngine\Render\BackdropTwistRenderer;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\MapRenderer;
use PhpWasmEngine\Render\SpriteRenderer;
use PhpWasmEngine\Render\WaterRenderer;
use PhpWasmEngine\Render\WorldRenderer;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Tiled\World;
use PhpWasmEngine\Tiled\WorldStream;

/** Assets, graphics, and collision owned by one demo level, independent of the game session. */
final class DemoLevel
{
	public ?WorldStream $stream = null;
	public MapRenderer|WorldRenderer|null $renderer = null;
	public ?SpriteRenderer $sprites = null;
	public ?Terrain $terrain = null;
	public ?Platformer $movement = null;
	public ?WaterRegions $waters = null;
	public ?WaterRenderer $waterRenderer = null;
	public ?BackdropTwistRenderer $backdropTwist = null;
	public ?BreakableBlocks $blocks = null;
	public ?Rings $rings = null;
	public ?LightDashes $lightDashes = null;
	public ?Badniks $badniks = null;
	public ?BossRegions $bossRegions = null;
	public ?CrabMeats $crabs = null;
	public ?MiniBosses $miniBosses = null;
	public ?Monitors $monitors = null;
	public ?Shields $shields = null;
	public ?WaterFeatures $waterFeatures = null;
	public ?Platforms $platforms = null;
	public ?WaterParticles $waterParticles = null;
	private string $systemPrefix;
	private bool $closed = false;

	/**
	 * Create a staging level without touching the active scene or engine.
	 *
	 * @param string $id Validated demo level identifier.
	 * @param object $assets Host asset scope with request, release, and close methods.
	 * @return void Records ownership without requesting files or allocating graphics.
	 */
	public function __construct(public string $id, private object $assets)
	{
		$this->systemPrefix = 'level:' . spl_object_id($this) . ':';
	}

	/**
	 * Poll dependencies and construct the destination before retiring the current level.
	 *
	 * @param Engine $engine Running session supplying the registry and simulation clock.
	 * @param Camera $camera Current viewport; its zoom is retained for the new scene.
	 * @return bool True when the scene can be committed; false while transfers are pending.
	 */
	public function prepare(Engine $engine, Camera $camera): bool
	{
		if($this->stream === null)
		{
			$loader = new Loader;
			$path = __DIR__ . '/assets/stream.world';

			if($this->id === 'world')
			{
				$state = $this->assets->request($path);

				if(is_string($state))
				{
					throw new RuntimeException($state);
				}

				if(!$state)
				{
					return false;
				}

				$world = $loader->world($path);
			}
			else
			{
				// Single-map scenes use the same dependency discovery as world streaming.
				$world = new World(
					$loader
					, $path
					, [
						'maps' => [
							[
								'fileName' => $this->id . '.tmj'
								, 'x' => 0
								, 'y' => 0
								, 'width' => 1
								, 'height' => 1
							]
						]
					]
				);
			}

			$this->stream = new WorldStream(
				$world
				, /**
				 * Poll a file using the shared host transport.
				 *
				 * @param string $path Resolved dependency path in the mounted engine directory.
				 * @param bool $retry Whether to retry a failed transfer.
				 * @return bool|string True when mounted, false while pending, or an error message.
				 */
				fn(string $path, bool $retry): bool|string => $this->assets->request($path, $retry)
				, /**
				 * Release this level's ownership when its last map drops a file.
				 *
				 * @param string $path Resolved dependency path no longer needed by this stream.
				 * @return void Keeps files referenced by other live level scopes mounted.
				 */
				function(string $path): void { $this->assets->release($path); }
			);
		}

		$view = clone $camera;
		[$view->x, $view->y] = $this->origin();

		if($this->id === 'world')
		{
			$this->stream->update($view->x, $view->y, $view->width / $view->zoom, $view->height / $view->zoom);
		}
		else
		{
			$this->stream->update(0, 0, 1, 1);
		}

		$maps = $this->stream->loaded();

		if(!$maps)
		{
			$pending = array_diff($this->stream->pending(), array_keys($this->stream->errors));

			if(!$pending)
			{
				throw new RuntimeException(implode('; ', $this->stream->errors) ?: 'No starting map');
			}

			return false;
		}

		if($this->id === 'world')
		{
			$this->renderer = new WorldRenderer($this->stream);
			// Warm visible GPU resources before the old scene is released.
			$this->renderer->render($view, $engine->time * 1000, 0);

			return true;
		}

		$map = reset($maps);
		$this->terrain = MapTerrain::build($map);
		$this->waters = WaterRegions::fromMaps($map);
		$this->lightDashes = new LightDashes($engine->entities);

		$this->rings = new Rings(
			$engine->entities
			, $map
			, terrain: $this->terrain
			, onCollect: [$this->lightDashes, 'collected']
		);

		$this->renderer = new MapRenderer($map);
		$this->sprites = new SpriteRenderer($this->renderer->atlas);
		$this->waterFeatures = new WaterFeatures($engine->entities, $this->terrain, $map, $this->waters);
		$this->platforms = new Platforms($engine->entities, $this->terrain, $map);

		$engine->systems->add($this->systemPrefix . 'platforms', [$this->platforms, 'update'], after: ['movement']);

		if($this->id !== 'isometric')
		{
			$this->backdropTwist = new BackdropTwistRenderer($engine->device->width, $engine->device->height);
		}

		if($this->waterFeatures->isEmpty())
		{
			$this->waterFeatures->close();
			$this->waterFeatures = null;
		}
		else
		{
			$engine->systems->add(
				$this->systemPrefix . 'water-features'
				, [$this->waterFeatures, 'update']
				, after: ['movement']
			);
		}

		if($this->waters->regions)
		{
			$this->waterRenderer = new WaterRenderer($engine->device->width, $engine->device->height);

			$this->waterParticles = new WaterParticles(
				$engine->entities
				, $map
				, $this->waters
				, milliseconds: $engine->time * 1000
			);

			$engine->systems->add(
				$this->systemPrefix . 'water-particles'
				, [$this->waterParticles, 'update']
				, after: [
					$this->waterFeatures === null
						? 'movement'
						: $this->systemPrefix . 'water-features'
				]
			);
		}

		$this->renderer->render($view, $engine->time * 1000);
		$engine->systems->add($this->systemPrefix . 'rings', [$this->rings, 'update'], after: ['movement']);

		if($this->id === 'badniks')
		{
			$this->badniks = new Badniks($engine->entities, $this->terrain, $this->rings);
			$this->crabs = new CrabMeats($engine->entities, $this->terrain, $map, $this->badniks);

			$this->miniBosses = new MiniBosses(
				$engine->entities
				, $this->terrain
				, $map
				, $this->rings
				, advanceCombat: false
			);

			$this->shields = new Shields($engine->entities, $map);
			$this->monitors = new Monitors($engine->entities, $this->terrain, $map, shields: $this->shields);
			$engine->systems->add($this->systemPrefix . 'crabs', [$this->crabs, 'update'], after: ['movement']);
			$engine->systems->add($this->systemPrefix . 'monitors', [$this->monitors, 'update'], after: ['movement']);

			$engine->systems->add(
				$this->systemPrefix . 'badniks'
				, [$this->badniks, 'update']
				, after: [$this->systemPrefix . 'crabs']
			);

			$engine->systems->add(
				$this->systemPrefix . 'mini-bosses'
				, [$this->miniBosses, 'update']
				, after: [$this->systemPrefix . 'badniks']
			);

			$engine->systems->add(
				$this->systemPrefix . 'shields'
				, [$this->shields, 'update']
				, after: [$this->systemPrefix . 'mini-bosses']
			);
		}

		if($this->id === 'breakables')
		{
			$this->blocks = configureBreakables($engine, $this->terrain, $map, $this->systemPrefix);
		}

		$bossRegions = BossRegions::fromMaps(
			/**
			 * Resolve a Tiled boss reference through the demo's spawned encounter entities.
			 *
			 * @param int $sourceId Referenced Tiled object ID.
			 * @return bool True while a referenced multi-hit boss or legacy badnik remains live.
			 */
			function(int $sourceId) use ($engine): bool {
				if($this->miniBosses?->aliveSource($sourceId))
				{
					return true;
				}

				$entity = $this->crabs?->entityForSourceId($sourceId);

				return $entity !== null && $engine->entities->has($entity, Badnik::class);
			}
			, $map
		);

		if($bossRegions->regions)
		{
			$this->bossRegions = $bossRegions;
		}

		/**
		 * Remove breakable blocks or monitors contacted in an eligible movement pose.
		 *
		 * @param Body $body Actor at the beginning of the collision sweep.
		 * @param Hit $hit First impact in the continuous terrain cast.
		 * @return bool True when the contact removed an owned collision shape.
		 */
		$impact = function(Body $body, Hit $hit) use ($engine): bool {
			return ($this->blocks?->impact($body, $hit, $engine->time * 1000) ?? false)
				|| ($this->monitors?->impact($body, $hit, $engine->time * 1000) ?? false);
		};

		$this->movement = new Platformer($this->terrain, $impact, $this->waters);

		return true;
	}

	/**
	 * Select the initial camera position for the destination demo.
	 *
	 * @return array{float,float} Top-left camera coordinates in world pixels.
	 */
	public function origin(): array
	{
		return match($this->id)
		{
			'world' => [32.0, 0.0],
			'isometric' => [-160.0, -80.0],
			default => [0.0, 0.0]
		};
	}

	/**
	 * Count the dependencies discovered so far for the staged level.
	 *
	 * @return array{ready:int,total:int,failed:int} Counts owned by this level's asset scope.
	 */
	public function progress(): array
	{
		$counts = $this->assets->progress();

		return ['ready' => $counts->ready, 'total' => $counts->total, 'failed' => $counts->failed];
	}

	/**
	 * Rebuild active graphics while preserving downloaded files and collision state.
	 *
	 * @return void Restores map and sprite GPU objects in their original ownership order.
	 */
	public function restore(): void
	{
		$this->renderer?->restore();
		$this->backdropTwist?->restore();
		$this->sprites?->restore();
		$this->waterRenderer?->restore();
	}

	/**
	 * Retire only this level's systems, entities, GPU resources, and file references.
	 *
	 * @param Engine $engine Persistent session from which owned systems and entities are removed.
	 * @return void Cancels pending transfers when no other scene needs them; safe to repeat.
	 */
	public function close(Engine $engine): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$engine->systems->remove($this->systemPrefix . 'particles');
		$engine->systems->remove($this->systemPrefix . 'breakables');
		$engine->systems->remove($this->systemPrefix . 'rings');
		$engine->systems->remove($this->systemPrefix . 'badniks');
		$engine->systems->remove($this->systemPrefix . 'crabs');
		$engine->systems->remove($this->systemPrefix . 'mini-bosses');
		$engine->systems->remove($this->systemPrefix . 'monitors');
		$engine->systems->remove($this->systemPrefix . 'shields');
		$engine->systems->remove($this->systemPrefix . 'water-features');
		$engine->systems->remove($this->systemPrefix . 'platforms');
		$engine->systems->remove($this->systemPrefix . 'water-particles');
		$this->blocks?->close();
		$this->rings?->close();
		$this->badniks?->close();
		$this->miniBosses?->close();
		$this->monitors?->close();
		$this->shields?->close();
		$this->waterFeatures?->close();
		$this->platforms?->close();
		$this->waterParticles?->close();
		$this->waterRenderer?->close();
		$this->backdropTwist?->close();
		$this->sprites?->close();
		$this->renderer?->close();
		$this->stream?->close();
		$this->assets->close();
	}
}
