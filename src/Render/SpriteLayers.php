<?php
declare(strict_types=1);
namespace Phonics\Render;

/** Sprite planes that may surround another entity in painter order. */
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
	public function __construct(public array $behind = [], public array $front = []) {}
}
