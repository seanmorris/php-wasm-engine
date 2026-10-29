<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Validated delivery, authority, and presentation policy for one component codec. */
final class ReplicationPolicy implements \JsonSerializable
{
	/**
	 * Policy requiring host authority.
	 */
	public const AUTHORITY_HOST = 'host';
	/**
	 * Policy allowing the entity owner to originate state.
	 */
	public const AUTHORITY_OWNER = 'owner';
	/**
	 * Policy allowing either participant to originate state.
	 */
	public const AUTHORITY_EITHER = 'either';
	/**
	 * Reliable ordered delivery policy.
	 */
	public const DELIVERY_RELIABLE = 'reliable';
	/**
	 * Latency-oriented realtime delivery policy.
	 */
	public const DELIVERY_REALTIME = 'realtime';
	/**
	 * Policy applying samples without interpolation.
	 */
	public const INTERPOLATE_NONE = 'none';
	/**
	 * Policy requesting linear interpolation.
	 */
	public const INTERPOLATE_LINEAR = 'linear';
	/**
	 * Policy delegating interpolation to game code.
	 */
	public const INTERPOLATE_GAME = 'game';

	/**
	 * Create and validate a replication policy.
	 *
	 * @param string $authority Participant allowed to originate this component state.
	 * @param string $delivery Reliable or realtime transport class.
	 * @param string $interpolation Presentation strategy used between received samples.
	 * @param int $priority Relative shedding priority for realtime state.
	 * @return void Stores the policy after validating authority, delivery, interpolation and priority.
	 */
	public function __construct(
		/** Allowed component-state origin. */
		public string $authority = self::AUTHORITY_HOST
		, /** Transport delivery class. */
		public string $delivery = self::DELIVERY_REALTIME
		, /** Sample presentation strategy. */
		public string $interpolation = self::INTERPOLATE_NONE
		, /** Realtime shedding priority. */
		public int $priority = 0
	){
		if(!in_array($authority, [self::AUTHORITY_HOST,self::AUTHORITY_OWNER,self::AUTHORITY_EITHER], true))
		throw new \InvalidArgumentException("Invalid replication authority $authority");

		if(!in_array($delivery, [self::DELIVERY_RELIABLE,self::DELIVERY_REALTIME], true))
		throw new \InvalidArgumentException("Invalid replication delivery $delivery");

		if(!in_array($interpolation, [self::INTERPOLATE_NONE,self::INTERPOLATE_LINEAR,self::INTERPOLATE_GAME], true))
		throw new \InvalidArgumentException("Invalid interpolation policy $interpolation");

		if($priority < -100 || $priority > 100)
		throw new \OutOfRangeException('Replication priority must be between -100 and 100');
	}

	/**
	 * Serialize the replication policy into transport-safe fields.
	 *
	 * @return array Scalar fields suitable for JSON transport.
	 */
	public function jsonSerialize(): array
	{
		return [
			'authority' => $this->authority
			, 'delivery' => $this->delivery
			, 'interpolation' => $this->interpolation
			, 'priority' => $this->priority
		];
	}
}
