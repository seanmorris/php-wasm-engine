<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\CheatCommand;
use DoomPhonics\Game\CheatResult;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Definition\WeaponSlots;
use Phonics\Ecs\Registry;

/** Applies recognized cheats through the same typed player state used by ordinary pickups and combat. */
final class CheatSystem
{
	private const WEAPONS = [
		'Fist'
		, 'Chainsaw'
		, 'Pistol'
		, 'Shotgun'
		, 'SuperShotgun'
		, 'Chaingun'
		, 'RocketLauncher'
		, 'PlasmaRifle'
		, 'BFG9000'
	];
	private const KEYS = ['BlueCard','YellowCard','RedCard','BlueSkull','YellowSkull','RedSkull'];
	private const POWER_SECONDS = [
		'Invulnerable' => 30.0
		, 'Strength' => PHP_FLOAT_MAX
		, 'Invisibility' => 60.0
		, 'IronFeet' => 60.0
		, 'Allmap' => PHP_FLOAT_MAX
		, 'LightAmp' => 120.0
	];
	/** @var list<string> */
	private array $weapons;

	public function __construct(?WeaponSlots $slots = null)
	{
		$this->weapons = array_values(array_unique([
			...self::WEAPONS
			, ...($slots?->weapons() ?? [])
		]));
	}

	public function apply(CheatCommand $command, Registry $registry, int $entity): CheatResult
	{
		if(!$registry->has($entity, PlayerState::class) || !$registry->has($entity, HitPoints::class))
		throw new \InvalidArgumentException('Cheats require a live player entity');

		$player = $registry->get($entity, PlayerState::class);
		$health = $registry->get($entity, HitPoints::class);

		switch($command->type)
		{
			case CheatCommand::GOD:
				$player->godMode = !$player->godMode;

				if($player->godMode) $health->current = max($health->current, min(100, $health->maximum));

				$player->message = 'DEGREELESSNESS MODE ' . ($player->godMode ? 'ON' : 'OFF');
				break;

			case CheatCommand::ARSENAL:
				$this->arsenal($player);
				$player->message = 'AMMO (NO KEYS) ADDED';
				break;

			case CheatCommand::ARSENAL_AND_KEYS:
				$this->arsenal($player);

				foreach(self::KEYS as $key) $player->keys[$key] = true;
				$player->message = 'VERY HAPPY AMMO ADDED';
				break;

			case CheatCommand::NO_CLIP:
				$player->noClip = !$player->noClip;
				$player->message = 'NO CLIPPING MODE ' . ($player->noClip ? 'ON' : 'OFF');
				break;

			case CheatCommand::BEHOLD:
				$player->message = 'INvuln, Str, Invis, Rad, Allmap, or Lite-amp';
				break;

			case CheatCommand::POWER:
				$this->power($player, $health, (string)$command->argument);
				break;

			case CheatCommand::CHOPPERS:
				$player->weapons['Chainsaw'] = true;
				$player->requestWeapon('Chainsaw');
				$player->powers['Invulnerable'] = self::POWER_SECONDS['Invulnerable'];
				$player->message = "... DOESN'T SUCK - GM";
				break;

			case CheatCommand::POSITION:
				if($registry->has($entity, Transform::class))
				{
					$pose = $registry->get($entity, Transform::class);
					$angle = fmod(rad2deg($pose->angle) + 360, 360);
					$player->message = sprintf('X %.0f Y %.0f Z %.0f A %.0f', $pose->x, $pose->y, $pose->z, $angle);
				}

				break;

			case CheatCommand::WARP:
				$map = (string)$command->argument;
				$player->message = 'WARPING TO ' . $map;

				return CheatResult::warp($map);

			default:
				throw new \UnexpectedValueException('Unsupported cheat command ' . $command->type);
		}

		return CheatResult::applied();
	}

	private function arsenal(PlayerState $player): void
	{
		foreach($this->weapons as $weapon) $player->weapons[$weapon] = true;

		foreach($player->ammoMaximum as $type => $maximum) $player->ammo[$type] = $maximum;
		$player->armor = 200;
		$player->armorClass = 50;
	}

	private function power(PlayerState $player, HitPoints $health, string $name): void
	{
		if(!isset(self::POWER_SECONDS[$name]))
		throw new \UnexpectedValueException('Unsupported cheat power ' . $name);

		if(isset($player->powers[$name]))
		{
			unset($player->powers[$name]);
			$player->message = strtoupper($name) . ' OFF';

			return;
		}

		$player->powers[$name] = self::POWER_SECONDS[$name];

		if($name === 'Strength')
		{
			$health->current = max($health->current, min(100, $health->maximum));
			$player->requestWeapon('Fist');
		}

		$player->message = strtoupper($name) . ' ON';
	}
}
