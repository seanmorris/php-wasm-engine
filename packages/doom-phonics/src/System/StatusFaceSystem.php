<?php
declare(strict_types=1);
namespace DoomPhonics\System;
use DoomPhonics\Input\DoomActions;

use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\StatusFace;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Game\PlayerState;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;

/** Advances player status-face idle and sustained-fire behavior at the game tic rate. */
final class StatusFaceSystem
{
	private float $ticRemainder = 0;
	private Query $players;

	public function __construct(private Controls $controls)
	{
		$this->players = Query::all(PlayerState::class, HitPoints::class, StatusFace::class);
	}

	public function update(Registry $registry, float $dt): void
	{
		$this->ticRemainder += max(0, $dt) * 35;
		$tics = (int)floor($this->ticRemainder + 1e-9);

		if($tics <= 0) return;

		$this->ticRemainder -= $tics;

		foreach($registry->query($this->players) as $entity)
		{
			$attacking = $registry->has($entity, PlayerCommand::class)
				? $registry->get($entity, PlayerCommand::class)->fire
				: $this->controls->down(DoomActions::FIRE) || ($registry->has($entity, ViewInput::class)
					&& $registry->get($entity, ViewInput::class)->fire
				);

			$registry->get($entity, StatusFace::class)->advance($attacking, $tics);
		}
	}
}
