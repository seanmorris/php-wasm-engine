<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Identifies one locally predicted command independently of its transport packet. */
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
		/** Numeric packet peer identifier or opaque prediction peer. */
		public PeerId $peer
		, /** Monotonic command or snapshot sequence. */
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
