<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Graphics\CameraTextureDefinition;
use DoomPhonics\Graphics\TextureCatalog;

/** Match WAD DOMTexture declarations to trusted documents registered by the game host. */
final class DomTextureSetup
{
	/** @param array<string,object> $providers Browser DomTexture bridges keyed by texture name. */
	public static function apply(Level $level, TextureCatalog $textures, array $providers): void
	{
		$providers = array_change_key_case($providers, CASE_UPPER);

		foreach($textures->cameraTextures() as $name => $definition)
		{
			if($definition->kind !== CameraTextureDefinition::DOM) continue;
			$bridge = $providers[$name] ?? null;
			if(!is_object($bridge)) continue;
			$level->bindDomTexture(new DomTextureBinding($definition, $bridge));
		}
	}
}
