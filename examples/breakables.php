<?php
declare(strict_types=1);

use PhpWasmEngine\Engine;
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Effects\ParticleSystem;
use PhpWasmEngine\Gameplay\BreakableBlock;
use PhpWasmEngine\Gameplay\BreakableBlocks;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Tiled\TileMap;

/**
 * Populate the breakable-block scene and bind interaction, reset, and particle updates.
 *
 * @param Engine $engine Engine providing the registry, controls, scheduler, and clock.
 * @param Terrain $terrain Terrain shared by the player and stationary blocks.
 * @param TileMap $map Demo map containing editable breakable tile objects.
 * @param Body $body Player whose proximity permits breaking a block.
 * @return BreakableBlocks Block owner to close when the scene stops.
 */
function configureBreakables(Engine $engine, Terrain $terrain, TileMap $map, Body $body):
BreakableBlocks
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
	$reset();

	$engine->systems->add('breakables',
		/**
		 * Break a nearby block on the action edge or restore all blocks on reset.
		 *
		 * @param Registry $entities Registry supplying intact block sprites.
		 * @param float $dt Fixed simulation delta in seconds, unused by this interaction.
		 * @return void Shatters at most one block per press; movement alone stays blocked.
		 */
		function(Registry $entities, float $dt) use ($engine, $blocks, $map, $body, $reset): void {
			if($engine->controls->pressed('reset'))
			{
				$reset();
			}

			if(!$engine->controls->pressed('action'))
			{
				return;
			}

			foreach($entities->query(BreakableBlock::class) as $id)
			{
				$sprite = $entities->get($id, Sprite::class);
					$rect = $map->tile($sprite->gid)['rect'];


				$dx = max(
					$sprite->x - $body->x
					, 0
					, $body->x - $sprite->x - $rect[2] * $sprite->scaleX
				);

				$dy = max(
					$sprite->y - $rect[3] * $sprite->scaleY - $body->y
					, 0
					, $body->y - $sprite->y
				);

				if($dx <= $body->radius + 24 && $dy <= $body->halfHeight + 24)
				{
					$blocks->shatter($id, $engine->time * 1000);
					break;
				}
			}
		}, after: ['movement']
	);

	$engine->systems->add('particles', [new ParticleSystem, 'update'], after: ['breakables']);
	return $blocks;
}
