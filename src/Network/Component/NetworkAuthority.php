<?php
declare(strict_types=1);
namespace Phonics\Network\Component;

use Phonics\Network\AuthorityOwner;

/** Declares which participant may originate commands for an entity. */
final class NetworkAuthority
{

	/**
	 * Create and validate a network authority component.
	 *
	 * @param AuthorityOwner $owner Host or peer allowed to control the entity.
	 */
	public function __construct(
		/** Participant allowed to control the entity. */
		public AuthorityOwner $owner
	){
	}
}
