<?php
declare(strict_types=1);
namespace Phonics\Effects;

use Phonics\Ecs\Registry;

/** Advance every ParticleBurst component and retire completed particle entities. */
final class ParticleSystem
{

	/**
	 * Advance particle entities and schedule expired entities for destruction.
	 *
	 * @param Registry $registry Registry containing ParticleBurst components.
	 * @param float $dt Finite nonnegative simulation delta in seconds.
	 * @return void Updates sprites; expired entities disappear on the registry's next flush.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if(!is_finite($dt) || $dt < 0)
		{
			throw new \InvalidArgumentException('Particle delta must be finite and nonnegative');
		}

		foreach($registry->query(ParticleBurst::class) as $id)
		{
			$burst = $registry->get($id, ParticleBurst::class);
			$burst->step($dt);

			if($burst->finished())
			{
				$registry->defer(
					/**
					 * Retire the completed burst unless the entity acquired a replacement burst.
					 *
					 * @param Registry $entities Registry flushing the deferred destruction.
					 * @return void Destroys the matching particle entity at most once.
					 */
					static function(Registry $entities) use ($id, $burst): void {
						if($entities->has($id, ParticleBurst::class)
							&& $entities->get($id, ParticleBurst::class) === $burst
						){
							$entities->destroy($id);
						}
					}
				);

			}
		}
	}
}
