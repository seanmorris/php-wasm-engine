<?php
declare(strict_types=1);
namespace Phonics\Network;

/** One validated binary protocol packet plus its WebRTC delivery class. */
final class Packet
{

	/**
	 * Create and validate a network packet.
	 *
	 * @param string $channel WebRTC delivery class, either control or realtime.
	 * @param int $family Stable message-family number for the payload.
	 * @param int $flags Protocol bit flags carried in the packet header.
	 * @param int $session Numeric session identifier carried in the packet header.
	 * @param int $peer Session peer associated with authority or input ownership.
	 * @param int $sequence Command or snapshot sequence used as a boundary.
	 * @param SimulationTick $tick Simulation tick associated with the command, event, or snapshot.
	 * @param int $ack Newest received packet sequence acknowledged by this packet.
	 * @param int $ackBits Bit window acknowledging earlier packet sequences.
	 * @param int $schema Stable schema identifier or payload schema version.
	 * @param array<string,mixed> $payload Validated scalar data carried by the message or component.
	 * @return void Stores the packet fields after validating the channel and message family.
	 */
	public function __construct(
		/** WebRTC delivery class. */
		public string $channel
		, /** Stable message-family number. */
		public int $family
		, /** Protocol header flags. */
		public int $flags
		, /** Numeric protocol session identifier. */
		public int $session
		, /** Numeric packet peer identifier or opaque prediction peer. */
		public int $peer
		, /** Monotonic command or snapshot sequence. */
		public int $sequence
		, /** Simulation tick represented by the record. */
		public SimulationTick $tick
		, /** Newest acknowledged packet sequence. */
		public int $ack
		, /** Acknowledgement bit window. */
		public int $ackBits
		, /** Payload schema version. */
		public int $schema
		, /** Validated JSON-safe payload fields. */
		public array $payload
	){
		if(!in_array($channel, ['control','realtime'], true))
		throw new \InvalidArgumentException("Unknown network channel $channel");

		if(!MessageFamily::valid($family)) throw new \InvalidArgumentException("Unknown message family $family");
	}
}
