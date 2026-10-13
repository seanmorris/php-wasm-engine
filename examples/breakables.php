<?php
declare(strict_types=1);

use PhpWasmEngine\Engine;
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Effects\ParticleSystem;
use PhpWasmEngine\Gameplay\BreakableBlocks;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Tiled\TileMap;

/**
 * Populate the breakable-block scene and bind reset and particle updates.
 *
 * @param Engine $engine Engine providing the registry, controls, scheduler, and clock.
 * @param Terrain $terrain Terrain shared by the player and stationary blocks.
 * @param TileMap $map Demo map containing editable breakable tile objects.
 * @param string $prefix Unique prefix for systems owned by this level instance.
 * @return BreakableBlocks Block owner to close when the scene stops.
 */
function configureBreakables(Engine $engine, Terrain $terrain, TileMap $map,
	string $prefix = ''
): BreakableBlocks
{
	$blocks = new BreakableBlocks($engine->entities, $terrain, $map);
	$ids = [];

	$reset =
		/**
		 * Recreate the demo's Tiled block objects, retiring intact blocks and remaining debris.
		 *
		 * @return void Restores block poses and per-object shatter grids.
		 */
		function() use ($engine, $map, $blocks, &$ids): void {
			foreach($ids as $id)
			{
				if($engine->entities->alive($id))
				{
					$engine->entities->destroy($id);
				}
			}
			$ids = [];

			foreach($map->layers as $layer)
			{
				foreach($layer->objects as $object)
				{
					if(($object['class'] ?? $object['type'] ?? '') !== 'breakable')
					{
						continue;
					}

					$properties = Loader::properties($object['properties'] ?? []);

					$sprite = new Sprite(
						$object['gid']
						, $object['x'] + $map->x + $layer->offsetX
						, $object['y'] + $map->y + $layer->offsetY
						, 0
						, 1
					);
					$rect = $map->tile($sprite->gid)['rect'];
					$sprite->scaleX = $object['width'] / $rect[2];
					$sprite->scaleY = $object['height'] / $rect[3];
					$sprite->rotation = deg2rad($object['rotation'] ?? 0);

					$ids[] = $blocks->create($sprite, $properties['columns'], $properties['rows']);
				}
			}
		};

	try
	{
		$reset();
	}
	catch(Throwable $error)
	{
		$blocks->close();
		throw $error;
	}

	$engine->systems->add($prefix.'breakables',
		/**
		 * Restore the scene's intact blocks and retire old debris when reset is pressed.
		 *
		 * @param Registry $entities Scene registry, unused because the reset closure owns its IDs.
		 * @param float $dt Fixed simulation delta in seconds, unused by reset.
		 * @return void Recreates blocks on the reset edge.
		 */
		function(Registry $entities, float $dt) use ($engine, $reset): void {
			if($engine->controls->pressed('reset'))
			{
				$reset();
			}
		}, after: ['movement']
	);

	$engine->systems->add(
		$prefix.'particles'
		, [new ParticleSystem, 'update']
		, after: [$prefix.'breakables']
	);
	return $blocks;
}
