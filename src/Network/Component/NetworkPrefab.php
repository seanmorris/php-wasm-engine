<?php
declare(strict_types=1);
namespace Phonics\Network\Component;

/** Whitelisted game-owned factory identity used for remote spawn records. */
final class NetworkPrefab
{
	/**
	 * Stable event or prefab identifier.
	 */
	public string $id;

	/**
	 * Create and validate a network prefab component.
	 *
	 * @param string $id Stable identifier for an authority, event, prefab, or network entity.
	 * @return void Stores the prefab ID after validating its spelling.
	 */
	public function __construct(string $id)
	{
		if(!preg_match('/^[A-Za-z][A-Za-z0-9_.:\/-]{0,127}$/', $id))
		throw new \InvalidArgumentException("Invalid network prefab $id");

		$this->id = $id;
	}
}
