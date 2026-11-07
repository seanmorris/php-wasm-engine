<?php
declare(strict_types=1);
namespace Phonics\Render;

/**
 * Sprite planes that may surround another entity in painter order.
 *
 * Back and front collections preserve explicit painter placement around a primary sprite while sharing the
 * owning entity transform.
 */
final class SpriteLayers
{
	/** Entity rendered between the rear and foreground planes, or null while unoccupied. */
	public ?int $anchor = null;

	/**
	 * Retain independently outlined rear and foreground sprite planes.
	 *
	 * @param list<Sprite> $behind Planes painted behind the optional anchor.
	 * @param list<Sprite> $front Planes painted in front of the optional anchor.
	 * @return void Stores mutable sprite lists without copying their members.
	 */
	public function __construct(
		/** Planes painted behind the optional anchor. */
		public array $behind = []
		, /** Planes painted in front of the optional anchor. */
		public array $front = []
	){
	}
}
