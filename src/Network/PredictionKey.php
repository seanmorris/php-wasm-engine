<?php
declare(strict_types=1);
namespace Phonics\Network;

/**
 * Identifies one locally predicted command independently of its transport packet.
 *
 * It lets authoritative events refer back to a local prediction so the receiver can reconcile rather than
 * present the same result twice.
 */
final class PredictionKey implements \JsonSerializable
{

	/**
	 * Create and validate a prediction key.
	 *
	 * @param PeerId $peer Session peer associated with authority or input ownership.
	 * @param CommandSequence $sequence Command or snapshot sequence used as a boundary.
	 * @return void Stores the peer and command sequence.
	 */
	public function __construct(
		/** Session peer associated with authority or input ownership. */
		public PeerId $peer
		, /** Command or snapshot sequence used as a boundary. */
		public CommandSequence $sequence
	){
	}

	/**
	 * Serialize the prediction key into transport-safe fields.
	 *
	 * @return array Scalar fields suitable for JSON transport.
	 */
	public function jsonSerialize(): array
	{
		return ['peer' => $this->peer->value(), 'sequence' => $this->sequence->value()];
	}

	/**
	 * Render the prediction key as its canonical string.
	 *
	 * @return string The canonical scalar string.
	 */
	public function __toString(): string { return $this->peer . ':' . $this->sequence; }
}
