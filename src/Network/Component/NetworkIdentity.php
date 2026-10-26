<?php
declare(strict_types=1);
namespace Phonics\Network\Component;

use Phonics\Network\NetworkEntityId;
use Phonics\Network\ReplicationScope;

/** Stable network identity attached explicitly to a local ECS entity. */
final class NetworkIdentity
{

	/**
	 * Create and validate a network identity component.
	 *
	 * @param NetworkEntityId $id Stable identifier for an authority, event, prefab, or network entity.
	 * @param ReplicationScope $scope Lifetime boundary for the replicated entity.
	 */
	public function __construct(
		/** Stable event or prefab identifier. */
		public NetworkEntityId $id
		, /** Replicated entity lifetime boundary. */
		public ReplicationScope $scope
	){
	}
}
