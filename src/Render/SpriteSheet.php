<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
use PhpWasmEngine\Tiled\
{
	Loader,TileMap
};

final class SpriteSheet
{
	public TileMap $map;
	public array $animations = [];

	/**
	 * Load a tileset and index tile classes, legacy types, or names as sprite animations.
	 *
	 * @param Loader $loader Tiled loader and shared asset cache.
	 * @param string $path External tileset JSON path.
	 * @return void Creates a synthetic map whose atlas can be shared by sprites from this sheet.
	 */
	public function __construct(Loader $loader, string $path)
	{
		$tiles = $loader->tileset($path);
		$this->map = new TileMap($path, ['tilewidth' => 1,'tileheight' => 1]);
		$this->map->tilesets = [['firstgid' => 1,'definitions' => $tiles]];

		foreach($tiles as $id => $tile)
		{
			$this->animations[
				$tile['class'] ?? $tile['type'] ?? $tile['properties']['name'] ?? (string)$id
			] = $id + 1;
		}
	}

	/**
	 * Create a sprite using a named animation or numeric local tile ID.
	 *
	 * @param string $animation Name from the sheet's animation index.
	 * @param float $x Initial world X coordinate of the sprite anchor.
	 * @param float $y Initial world Y coordinate of the sprite anchor.
	 * @return Sprite New bottom-center-anchored sprite following the renderer's global clock.
	 */
	public function sprite(string $animation, float $x = 0, float $y = 0): Sprite
	{
		return new Sprite(
			$this->animations[$animation] ?? throw new \OutOfBoundsException("Unknown animation $animation")
			, $x
			, $y
		);
	}

	/**
	 * Switch an existing sprite's named animation and begin at the supplied renderer time.
	 * Repeated requests for the current animation preserve its phase unless restart is true.
	 *
	 * @param Sprite $sprite Sprite to update; it must use an atlas created from this sheet.
	 * @param string $animation Animation name to resolve before mutating the sprite.
	 * @param float $milliseconds Finite renderer or simulation time in milliseconds.
	 * @param bool $restart Restart even when the requested animation is already selected.
	 * @return bool True when playback changed or restarted; false when the current animation was
	 * preserved.
	 */
	public function play(
		Sprite $sprite
		, string $animation
		, float $milliseconds
		, bool $restart = false
	): bool {
		if(!is_finite($milliseconds))
		{
			throw new \InvalidArgumentException('Animation time must be finite');
		}

		$gid = $this->animations[$animation] ?? throw new \OutOfBoundsException("Unknown animation $animation");

		if($sprite->gid === $gid && !$restart)
		{
			return false;
		}

		$sprite->gid = $gid;
		$sprite->time = -$milliseconds;

		return true;
	}
}
