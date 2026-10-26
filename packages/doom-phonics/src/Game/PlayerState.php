<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Persistent single-player inventory and episode statistics. */
final class PlayerState
{
	/** @var array<string,int> */
	public array $ammo = ['Clip' => 50, 'Shell' => 0, 'RocketAmmo' => 0, 'Cell' => 0];
	/** @var array<string,int> */
	public array $ammoMaximum = ['Clip' => 200, 'Shell' => 50, 'RocketAmmo' => 50, 'Cell' => 300];
	/** @var array<string,bool> */
	public array $weapons = ['Fist' => true, 'Pistol' => true];
	/** @var array<string,bool> */
	public array $keys = [];
	/** @var array<string,float> Active power name to remaining seconds. */
	public array $powers = [];
	public string $weapon = 'Pistol';
	public ?string $pendingWeapon = null;
	public ?int $weaponEntity = null;
	public int $armor = 0;
	public int $armorClass = 0;
	public int $kills = 0;
	public int $items = 0;
	public int $secrets = 0;
	public bool $godMode = false;
	public bool $noClip = false;
	public bool $exited = false;
	public bool $secretExit = false;
	public int $reactionTics = 0;
	public string $message = '';

	/** Queue a first-person lowering transition without changing the active weapon early. */
	public function requestWeapon(string $weapon): void
	{
		if($weapon === '') return;

		$this->pendingWeapon = strcasecmp($weapon, $this->weapon) === 0 ? null : $weapon;
	}
}
