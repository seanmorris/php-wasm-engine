<?php
declare(strict_types=1);
namespace Phonics\Network\Component;

use Phonics\Network\AuthorityOwner;

/**
 * Declares which participant may originate commands for an entity.
 *
 * Replication systems read this component to reject commands or component changes from participants that do
 * not own the entity.
 */
final class NetworkAuthority
{

	/**
	 * Create and validate a network authority component.
	 *
	 * @param AuthorityOwner $owner Host or peer allowed to control the entity.
	 * @return void Stores the authority owner.
	 */
	public function __construct(
		/** Host or peer allowed to control the entity. */
		public AuthorityOwner $owner
	){
	}
}
