<?php
declare(strict_types=1);
namespace Phonics\Tiled;

/** Authored target identity and data; movement, drawing, and collision remain system decisions. */
final class Target
{

	/**
	 * Bind a component-bearing entity to its world, map, layer, object, or tile group.
	 *
	 * @param string $kind Target kind: world, map, layer, object, or tile.
	 * @param string $id Stable entity or source-local target identity.
	 * @param mixed $value Parsed content retained by this scope.
	 * @param ?TileMap $map Map instance whose authored content is being inspected.
	 * @param ?Layer $layer Owning layer when the target is a layer or object.
	 * @return void Initializes the owned references and validates configuration.
	 */
	public function __construct(
		public string $kind
		, public string $id
		, public mixed $value
		, public ?TileMap $map = null
		, public ?Layer $layer = null
	){
	}
}
