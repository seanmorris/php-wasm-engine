<?php
declare(strict_types=1);
namespace Phonics\Network;

/**
 * One validated binary protocol packet plus its WebRTC delivery class.
 *
 * The value keeps validated header metadata and payload bytes together after decoding, including the
 * delivery channel required by the transport.
 */
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
		/** WebRTC delivery class, either control or realtime. */
		public string $channel
		, /** Stable message-family number for the payload. */
		public int $family
		, /** Protocol bit flags carried in the packet header. */
		public int $flags
		, /** Numeric session identifier carried in the packet header. */
		public int $session
		, /** Session peer associated with authority or input ownership. */
		public int $peer
		, /** Command or snapshot sequence used as a boundary. */
		public int $sequence
		, /** Simulation tick associated with the command, event, or snapshot. */
		public SimulationTick $tick
		, /** Newest received packet sequence acknowledged by this packet. */
		public int $ack
		, /** Bit window acknowledging earlier packet sequences. */
		public int $ackBits
		, /** Stable schema identifier or payload schema version. */
		public int $schema
		, /** Validated scalar data carried by the message or component. */
		public array $payload
	){
		if(!in_array($channel, ['control','realtime'], true))
		throw new \InvalidArgumentException("Unknown network channel $channel");

		if(!MessageFamily::valid($family)) throw new \InvalidArgumentException("Unknown message family $family");
	}
}
