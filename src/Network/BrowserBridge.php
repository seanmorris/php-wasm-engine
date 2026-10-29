<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Vrzno-safe gameplay packet bridge; browser code owns framing sequence numbers. */
final class BrowserBridge
{
	private $receiver;
	private $sender;

	/**
	 * Create and validate a browser packet bridge.
	 *
	 * @param ?callable $receiver Callback that drains encoded packet records from the browser queue.
	 * @param ?callable $sender Callback that sends a payload through the browser transport.
	 * @param ?Protocol $protocol Binary codec used to validate packets crossing the PHP boundary.
	 * @return void Stores the callbacks, defaulting to phonicsNetworkReceive and phonicsNetworkSend;
	 * throws when either is unavailable.
	 */
	public function __construct(?callable $receiver = null, ?callable $sender = null, ?Protocol $protocol = null)
	{
		if(($receiver === null || $sender === null) && function_exists('vrzno_env'))
		{
			$receiver ??= vrzno_env('phonicsNetworkReceive');
			$sender ??= vrzno_env('phonicsNetworkSend');
		}

		if(!is_callable($receiver) || !is_callable($sender))
		throw new \RuntimeException('Browser network bridge is unavailable');

		$this->receiver = $receiver;
		$this->sender = $sender;
		$this->protocol = $protocol ?? new Protocol;
	}

	private Protocol $protocol;

	/**
	 * Drain and decode queued gameplay packets from the browser transport.
	 *
	 * @param int $limit Maximum number of queued packets to drain in this call.
	 * @return list<Packet> Validated packets drained in queue order.
	 */
	public function receive(int $limit = 64): array
	{
		$limit = max(0, min(256, $limit));
		$value = json_decode((string)($this->receiver)($limit), true, 64, JSON_THROW_ON_ERROR);

		if(!is_array($value)) throw new \UnexpectedValueException('Browser packet queue returned invalid data');

		$result = [];

		foreach($value as $record)
		{
			if(!is_array($record) || !is_string($record['channel'] ?? null) || !is_string($record['data'] ?? null))
			throw new \UnexpectedValueException('Browser packet queue returned an invalid record');

			$bytes = base64_decode($record['data'], true);

			if($bytes === false) throw new \UnexpectedValueException('Browser packet queue returned invalid base64');

			$result[] = $this->protocol->decode($bytes, $record['channel']);
		}

		return $result;
	}

	/**
	 * Encode and enqueue one gameplay payload for browser transport.
	 *
	 * @param int $family Stable message-family number for the payload.
	 * @param array<string,mixed> $payload Validated scalar data carried by the message or component.
	 * @param SimulationTick $tick Simulation tick associated with the command, event, or snapshot.
	 * @param string $channel WebRTC delivery class, either control or realtime.
	 * @param int $schema Stable schema identifier or payload schema version.
	 * @return bool True when the browser accepted the outgoing payload.
	 */
	public function send(
		int $family
		, array $payload
		, SimulationTick $tick
		, string $channel = 'realtime'
		, int $schema = 1
	): bool {
		if(!MessageFamily::gameplay($family))
		throw new \InvalidArgumentException("PHP cannot originate message family $family");

		if(!in_array($channel, ['control','realtime'], true))
		throw new \InvalidArgumentException("Unknown network channel $channel");

		if($schema < 1 || $schema > 0xffff) throw new \OutOfRangeException('Packet schema is invalid');

		$json = json_encode($payload, JSON_THROW_ON_ERROR);

		return (bool)($this->sender)($channel, $family, $tick->value(), $schema, $json);
	}
}
