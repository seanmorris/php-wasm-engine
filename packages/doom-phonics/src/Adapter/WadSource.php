<?php
declare(strict_types=1);
namespace DoomPhonics\Adapter;

use DoomPhonics\Archive\Stack;
use DoomPhonics\Definition\ActorDefinition;
use DoomPhonics\Definition\WeaponSlots;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\CameraTextureSetup;
use DoomPhonics\Graphics\Palette;
use DoomPhonics\Graphics\TextureCatalog;
use Phonics\World\ActiveScope;
use Phonics\World\ContentSource;
use Phonics\World\ScopeContext;
use Phonics\World\ScopeDescriptor;

/** Catalogs maps from a layered WAD stack and activates maps without game-specific policy. */
final class WadSource implements ContentSource
{
	private ?TextureCatalog $textures = null;
	private ?Palette $palette = null;
	private WadDefinitions $definitions;
	private ?WeaponSlots $weapons = null;
	private bool $closed = false;

	/**
	 * @param array<string,string> $decorate
	 * @param array<string,string> $sndinfo
	 * @param array<string,string> $keyconf
	 */
	public function __construct(public Stack $wad, array $decorate = [], array $sndinfo = [], array $keyconf = [])
	{
		$this->definitions = new WadDefinitions($decorate, $sndinfo, $keyconf);
	}

	public function catalog(): iterable
	{
		$this->requireOpen();
		foreach($this->wad->maps() as $name)
		{
			$episode = preg_match('/^E(\d+)M(\d+)$/', $name, $match) ? (int)$match[1] : null;
			$tags = ['playable','wad-map'];
			if($episode !== null) $tags[] = 'episode-' . $episode;
			yield new ScopeDescriptor($name, 'map', null, $tags, [
				'format' => 'doom'
				, 'episode' => $episode
				, 'level' => isset($match[2]) ? (int)$match[2] : null
			]);
		}
	}

	public function activate(string $id, ScopeContext $context): ActiveScope
	{
		$this->requireOpen();
		if(!in_array(strtoupper($id), $this->wad->maps(), true))
		throw new \OutOfBoundsException("Unknown WAD map $id");

		$this->textures ??= new TextureCatalog($this->wad);
		$this->palette ??= new Palette($this->wad);
		$this->weapons ??= WeaponSlots::fromCatalog(
			$this->definitions->actors
			, fn(ActorDefinition $weapon): bool => $this->weaponAssetsExist($weapon)
			, $this->definitions->keyconf
		);
		$level = new Level($this->wad->map($id));
		CameraTextureSetup::apply($level, $this->textures);
		$content = new WadLevel(
			$this->wad,
			$level,
			$this->textures,
			$this->palette,
			$this->definitions->actors,
			$this->definitions->sounds,
			$this->weapons
		);

		return new WadActiveScope($context->descriptor, $content);
	}

	public function close(): void
	{
		$this->closed = true;
		$this->textures = null;
		$this->palette = null;
		$this->weapons = null;
	}

	private function weaponAssetsExist(ActorDefinition $weapon): bool
	{
		$ready = $weapon->state('Ready');

		if(!$ready) return false;

		foreach($ready->frames as $frame)
		if(strcasecmp($frame->sprite, 'TNT1') !== 0
			&& $this->textures?->sprite($frame->sprite, $frame->frame, 0) !== null
		) return true;

		return false;
	}

	private function requireOpen(): void
	{
		if($this->closed) throw new \LogicException('WAD source is closed');
	}
}
