<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\InventoryPickup;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\StatusFace;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\ViewEffects;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Game\PlayerState;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;

/** Applies DECORATE inventory semantics when a player overlaps a pickup actor. */
final class PickupSystem
{
	private Query $players;

	public function __construct(public ActorCatalog $actors)
	{
		$this->players = Query::all(PlayerStart::class, PlayerState::class, Transform::class, HitPoints::class);
	}

	public function update(Registry $registry, float $dt): void
	{
		$pickups = $registry->query(Query::all(InventoryPickup::class, Transform::class));

		foreach($registry->query($this->players) as $playerEntity)
		{
			$pose = $registry->get($playerEntity, Transform::class);
			$player = $registry->get($playerEntity, PlayerState::class);
			$health = $registry->get($playerEntity, HitPoints::class);

			foreach($pickups as $entity)
			{
				if(!$registry->alive($entity) || $entity === $playerEntity) continue;

				$pickupPose = $registry->get($entity, Transform::class);
				$radius = $registry->has($entity, Body::class) ? $registry->get($entity, Body::class)->radius : 20;

				if(hypot($pose->x - $pickupPose->x, $pose->y - $pickupPose->y) > $radius + 16 || abs(
					$pose->z - $pickupPose->z
				) > 56
				)
				continue;

				$pickup = $registry->get($entity, InventoryPickup::class);
				$definition = $this->actors->named($pickup->actorName);
				$newWeapon = $definition && $this->actors->isA($definition, 'Weapon')
					&& empty($player->weapons[$definition->name]);

				if(!$definition || !$this->apply($definition, $player, $health)) continue;

				if($newWeapon && $registry->has($playerEntity, StatusFace::class))
				$registry->get($playerEntity, StatusFace::class)->recordWeaponPickup();

				if($definition->hasFlag('COUNTITEM')) $player->items++;

				$message = (string)$definition->value('Inventory.PickupMessage', $definition->name);
				$player->message = str_starts_with($message, '$') ? strtoupper($definition->name) : $message;

				if($registry->has($playerEntity, ViewEffects::class))
				$registry->get($playerEntity, ViewEffects::class)->recordPickup();

				$registry->defer(static function(Registry $r) use ($entity): void {
					if($r->alive($entity)) $r->destroy($entity);
				});
			}
		}
	}

	private function apply(object $definition, PlayerState $player, HitPoints $health): bool
	{
		$amount = (int)$definition->value('Inventory.Amount', 1);

		if($this->actors->isA($definition, 'Health'))
		{
			$maximum = (int)$definition->value('Inventory.MaxAmount', 0) ?: $health->maximum;

			if($health->current >= $maximum && !$definition->hasFlag('INVENTORY.ALWAYSPICKUP')) return false;

			$health->current = min($maximum, $health->current + $amount);

			return true;
		}

		if($this->actors->isA($definition, 'BasicArmorPickup') || $this->actors->isA($definition, 'BasicArmorBonus'))
		{
			$save = (int)$definition->value('Armor.SaveAmount', 0);
			$maximum = (int)$definition->value('Armor.MaxSaveAmount', $save);

			if($this->actors->isA($definition, 'BasicArmorPickup'))
			{
				if($player->armor >= $save) return false;

				$player->armor = $save;
			}
			else $player->armor = min($maximum, $player->armor + $save);

			$player->armorClass = (int)round((float)$definition->value('Armor.SavePercent', 0));

			return true;
		}

		if($this->actors->isA($definition, 'Ammo'))
		{
			// Derived pickups supply their own amount to the base ammo class's inventory pool.
			$ammo = $definition;

			while($ammo->parent !== null && strcasecmp($ammo->parent, 'Ammo') !== 0)
			{
				$parent = $this->actors->named($ammo->parent);

				if(!$parent) break;

				$ammo = $parent;
			}

			return $this->giveAmmo($player, $ammo->name, $amount, (int)$ammo->value('Inventory.MaxAmount', 0));
		}

		if($this->actors->isA($definition, 'Weapon'))
		{
			$new = empty($player->weapons[$definition->name]);
			$player->weapons[$definition->name] = true;
			$type = (string)$definition->value('Weapon.AmmoType', '');
			$give = (int)$definition->value('Weapon.AmmoGive', 0);
			$ammo = $type !== '' && $give > 0
				? $this->giveAmmo($player, $type, $give, (int)($player->ammoMaximum[$type] ?? 0))
				: false;

			if($new) $player->requestWeapon($definition->name);

			return $new || $ammo;
		}

		if($this->actors->isA($definition, 'Key'))
		{
			$new = empty($player->keys[$definition->name]);
			$player->keys[$definition->name] = true;

			return $new;
		}

		if($definition->name === 'Backpack')
		{
			foreach($player->ammoMaximum as $type => $maximum)
			$player->ammoMaximum[$type] = min($maximum * 2, $maximum + 300);

			foreach([
				'Clip' => 10
				, 'Shell' => 4
				, 'RocketAmmo' => 1
				, 'Cell' => 20
			] as $type => $give) $this->giveAmmo($player, $type, $give, $player->ammoMaximum[$type]);

			return true;
		}

		if($definition->name === 'Berserk')
		{
			$health->current = max($health->current, 100);
			$player->powers['Strength'] = PHP_FLOAT_MAX;
			$player->requestWeapon('Fist');

			return true;
		}

		if($definition->name === 'Soulsphere')
		{
			$health->current = min(200, $health->current + 100);

			return true;
		}

		if($definition->name === 'Allmap')
		{
			$player->powers['Allmap'] = PHP_FLOAT_MAX;

			return true;
		}

		if($this->actors->isA($definition, 'PowerupGiver'))
		{
			$type = (string)$definition->value('Powerup.Type', $definition->name);
			$player->powers[$type] = max($player->powers[$type] ?? 0, $type === 'IronFeet' ? 60 : 30);

			return true;
		}

		return $definition->hasFlag('INVENTORY.ALWAYSPICKUP');
	}

	private function giveAmmo(PlayerState $player, string $type, int $amount, int $maximum): bool
	{
		$current = $player->ammo[$type] ?? 0;
		$maximum = max($maximum, $player->ammoMaximum[$type] ?? 0);

		if($maximum <= 0 || $current >= $maximum) return false;

		$player->ammo[$type] = min($maximum, $current + $amount);
		$player->ammoMaximum[$type] = $maximum;

		return true;
	}
}
