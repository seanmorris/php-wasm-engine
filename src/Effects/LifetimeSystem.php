<?php
declare(strict_types=1);
namespace Phonics\Effects;

use Phonics\Ecs\Registry;
use Phonics\Render\Sprite;

/**
 * Age every Lifetime, fade the sprite of each, and retire the expired on the registry's next flush.
 *
 * A game whose managers must act in the same step an effect expires, to release what they hold on it, keeps
 * stepping its lifetimes itself and skips this system; a game that only needs effects to age, fade and go runs it.
 */
final class LifetimeSystem
{

	/**
	 * Advance lifetimes and schedule expired entities for destruction.
	 *
	 * @param Registry $registry Registry holding Lifetime components, with or without sprites.
	 * @param float $dt Finite nonnegative simulation delta in seconds.
	 * @return void Updates sprite opacity; expired entities disappear on the registry's next flush.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if(!is_finite($dt) || $dt < 0)
		{
			throw new \InvalidArgumentException('Lifetime delta must be finite and nonnegative');
		}

		foreach($registry->query(Lifetime::class) as $id)
		{
			$lifetime = $registry->get($id, Lifetime::class);
			$lifetime->advance($dt);

			if($lifetime->expired())
			{
				$registry->destroyLater($id);

				continue;
			}

			if($registry->has($id, Sprite::class)) $registry->get($id, Sprite::class)->opacity = $lifetime->opacity();
		}
	}
}
