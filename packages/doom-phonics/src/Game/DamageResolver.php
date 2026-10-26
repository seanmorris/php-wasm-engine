<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\StatusFace;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewEffects;
use Phonics\Ecs\Registry;

/** Applies DECORATE health, mass, pain, extreme-death, and damage-thrust rules. */
final class DamageResolver
{
	private const TICS_PER_SECOND = 35;
	private const THRUST_PER_DAMAGE_TIC = 12.5;

	/** Return true only when this event changed the target. */
	public function apply(Registry $registry, DamageEvent $event): bool
	{
		if($event->amount === 0 || !$registry->alive($event->target)
			|| !$registry->has($event->target, HitPoints::class)
			|| !$registry->has($event->target, ActorState::class)
		) return false;

		$health = $registry->get($event->target, HitPoints::class);
		if($health->current <= 0) return false;

		$state = $registry->get($event->target, ActorState::class);
		$damage = max(0, (int)round($event->amount * (float)$state->definition->value('DamageFactor', 1)));
		if($damage === 0) return false;

		if($registry->has($event->target, PlayerState::class))
		{
			$player = $registry->get($event->target, PlayerState::class);
			if($player->godMode || isset($player->powers['Invulnerable'])) return false;

			$absorbed = min($player->armor, (int)floor($damage * $player->armorClass / 100));
			$player->armor -= $absorbed;
			$damage -= $absorbed;
			if($damage === 0) return false;
		}

		if($event->thrust) $this->applyThrust($registry, $event, $damage, $state);
		$health->current -= $damage;

		if($registry->has($event->target, PlayerState::class)
			&& $registry->has($event->target, ViewEffects::class)
		) $registry->get($event->target, ViewEffects::class)->recordDamage($damage);

		if($registry->has($event->target, StatusFace::class))
		{
			$direction = FaceDirection::front();

			if($registry->has($event->target, Transform::class)
				&& $registry->alive($event->source)
				&& $registry->has($event->source, Transform::class)
			) $direction = FaceDirection::toward(
				$registry->get($event->target, Transform::class)
				, $registry->get($event->source, Transform::class)
			);

			$registry->get($event->target, StatusFace::class)->recordDamage($damage, $direction);
		}

		if($health->current <= 0)
		{
			if($registry->has($event->target, Body::class))
			$registry->get($event->target, Body::class)->shootable = false;

			$label = $this->deathLabel($state, $health);
			if($label !== null) $state->enter($label);

			if($registry->has($event->target, MonsterBrain::class)
				&& $registry->alive($event->source)
				&& $registry->has($event->source, PlayerState::class)
			) $registry->get($event->source, PlayerState::class)->kills++;
		}
		elseif($state->definition->state('Pain') && mt_rand(0, 255) < $health->painChance)
		$state->enter('Pain');

		return true;
	}

	private function deathLabel(ActorState $state, HitPoints $health): ?string
	{
		$gibHealth = (int)$state->definition->value('GibHealth', -$health->maximum);
		$extreme = !$state->definition->hasFlag('DONTGIB')
			&& $health->current <= $gibHealth
			&& $state->definition->state('XDeath') !== null;

		if($extreme) return 'XDeath';
		if($state->definition->state('Death')) return 'Death';

		return !$state->definition->hasFlag('DONTGIB') && $state->definition->state('XDeath')
			? 'XDeath'
			: null;
	}

	private function applyThrust(
		Registry $registry
		, DamageEvent $event
		, int $damage
		, ActorState $state
	): void {
		if($state->definition->hasFlag('NODAMAGETHRUST') || $state->definition->hasFlag('NOCLIP')
			|| !$registry->has($event->target, Transform::class)
			|| !$registry->has($event->target, Velocity::class)
		) return;

		$source = $event->thrustSource();
		if($source === $event->target || !$registry->alive($source)
			|| !$registry->has($source, Transform::class)
		) return;

		$targetPose = $registry->get($event->target, Transform::class);
		$sourcePose = $registry->get($source, Transform::class);
		$dx = $targetPose->x - $sourcePose->x;
		$dy = $targetPose->y - $sourcePose->y;
		$distance = hypot($dx, $dy);
		if($distance < 1e-7) return;

		$mass = max(1.0, (float)$state->definition->value('Mass', 100));
		$momentum = $damage * self::THRUST_PER_DAMAGE_TIC * self::TICS_PER_SECOND / $mass;
		$velocity = $registry->get($event->target, Velocity::class);
		$velocity->x += $dx / $distance * $momentum;
		$velocity->y += $dy / $distance * $momentum;
	}
}
