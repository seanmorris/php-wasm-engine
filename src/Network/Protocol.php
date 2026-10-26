<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Binary network framing compatible with the browser protocol implementation. */
final class Protocol
{
	/**
	 * Binary header marker for Phonics network packets.
	 */
	public const MAGIC = 0x50484e31;
	/**
	 * Protocol major version.
	 */
	public const MAJOR = 1;
	/**
	 * Protocol minor version.
	 */
	public const MINOR = 0;
	/**
	 * Fixed binary header size in bytes.
	 */
	public const HEADER_BYTES = 40;

	/**
	 * Create and validate a binary protocol codec.
	 *
	 * @param int $maximumBytes Validated maximum bytes used by this binary protocol codec.
	 */
	public function __construct(
		/** Largest payload accepted by the binary codec. */
		public int $maximumBytes = 1048576
	){
		if($maximumBytes < 1) throw new \OutOfRangeException('Protocol maximum bytes must be positive');
	}

	/**
	 * Decode transport fields into a validated component instance.
	 *
	 * @param string $bytes Complete encoded binary packet.
	 * @param string $channel WebRTC delivery class, either control or realtime.
	 * @return Packet The validated decoded component.
	 */
	public function decode(string $bytes, string $channel): Packet
	{
		if(strlen($bytes) < self::HEADER_BYTES)
		throw new \UnexpectedValueException('Network packet header is truncated');

		$header = unpack(
			'Nmagic/Cmajor/Cminor/Cfamily/Cflags/Nsession/Npeer/Nsequence/Ntick/Nack/NackBits/nschema/nreserved/Nlength'
			, substr($bytes, 0, self::HEADER_BYTES)
		);

		if(!$header || $header['magic'] !== self::MAGIC)
		throw new \UnexpectedValueException('Network packet has invalid magic');

		if($header['major'] !== self::MAJOR)
		throw new \UnexpectedValueException("Unsupported network protocol major {$header['major']}");

		if(!MessageFamily::valid($header['family']))
		throw new \UnexpectedValueException("Unknown message family {$header['family']}");

		if($header['length'] > $this->maximumBytes)
		throw new \LengthException("Network payload exceeds {$this->maximumBytes} bytes");

		if(strlen($bytes) !== self::HEADER_BYTES + $header['length'])
		throw new \UnexpectedValueException('Network packet length does not match its header');

		try
		{
			$payload = json_decode(substr($bytes, self::HEADER_BYTES), true, 128, JSON_THROW_ON_ERROR);
		}
		catch(\JsonException $error)
		{
			throw new \UnexpectedValueException('Network JSON payload is invalid', 0, $error);
		}

		if(!is_array($payload)) throw new \UnexpectedValueException('Network payload must be a JSON object or array');

		return new Packet(
			$channel
			, $header['family']
			, $header['flags']
			, $header['session']
			, $header['peer']
			, $header['sequence']
			, new SimulationTick($header['tick'])
			, $header['ack']
			, $header['ackBits']
			, $header['schema']
			, $payload
		);
	}

	/**
	 * Encode a whitelisted component into scalar transport fields.
	 *
	 * @param Packet $packet Validated packet to encode.
	 * @return string JSON-safe component fields.
	 */
	public function encode(Packet $packet): string
	{
		$payload = json_encode($packet->payload, JSON_THROW_ON_ERROR);

		if(strlen($payload) > $this->maximumBytes)
		throw new \LengthException("Network payload exceeds {$this->maximumBytes} bytes");

		return pack(
			'NCCCCNNNNNNnnN'
			, self::MAGIC
			, self::MAJOR
			, self::MINOR
			, $packet->family
			, $packet->flags
			, $packet->session
			, $packet->peer
			, $packet->sequence
			, $packet->tick->value()
			, $packet->ack
			, $packet->ackBits
			, $packet->schema
			, 0
			, strlen($payload)
		) . $payload;
	}
}
