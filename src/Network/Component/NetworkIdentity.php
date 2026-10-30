<?php
declare(strict_types=1);
namespace Phonics\Network\Component;

use Phonics\Network\NetworkEntityId;
use Phonics\Network\ReplicationScope;

/**
 * Stable network identity attached explicitly to a local ECS entity.
 *
 * The component joins transient local entity IDs to match-stable network IDs without deriving identity from
 * allocation order.
 */
final class NetworkIdentity
{

	/**
	 * Create and validate a network identity component.
	 *
	 * @param NetworkEntityId $id Stable identifier for an authority, event, prefab, or network entity.
	 * @param ReplicationScope $scope Lifetime boundary for the replicated entity.
	 * @return void Stores the network ID and replication scope.
	 */
	public function __construct(
		/** Stable identifier for an authority, event, prefab, or network entity. */
		public NetworkEntityId $id
		, /** Lifetime boundary for the replicated entity. */
		public ReplicationScope $scope
	){
	}
}
