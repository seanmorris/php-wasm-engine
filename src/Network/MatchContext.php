<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Immutable host/guest identity, player slots, and game-owned match settings. */
final class MatchContext implements \JsonSerializable
{

	/**
	 * Create and validate a match context.
	 *
	 * @param string $role Local match role, host or guest.
	 * @param PlayerSlot $localSlot Player slot controlled by this runtime.
	 * @param PlayerSlot $remoteSlot Player slot controlled by the connected peer.
	 * @param PeerId $localPeer Opaque identity of the local participant.
	 * @param PeerId $remotePeer Opaque identity of the connected participant.
	 * @param string $descriptorDigest Integrity digest of the negotiated match descriptor.
	 * @param array<string,mixed> $descriptor Canonical game, content, settings, and player declaration.
	 */
	public function __construct(
		/** Local host or guest role. */
		public string $role
		, /** Player slot controlled by this runtime. */
		public PlayerSlot $localSlot
		, /** Player slot controlled by the connected peer. */
		public PlayerSlot $remoteSlot
		, /** Opaque identity of the local participant. */
		public PeerId $localPeer
		, /** Opaque identity of the connected participant. */
		public PeerId $remotePeer
		, /** Integrity digest of the match descriptor. */
		public string $descriptorDigest
		, /** Canonical negotiated match descriptor. */
		public array $descriptor
	){
		if(!in_array($role, ['host','guest'], true)) throw new \InvalidArgumentException("Invalid match role $role");

		if($localSlot->equals($remoteSlot)) throw new \InvalidArgumentException('Match player slots must differ');

		if(!preg_match('/^sha256-[A-Za-z0-9+\/=]+$/', $descriptorDigest))
		throw new \InvalidArgumentException('Match descriptor digest is invalid');

		json_encode($descriptor, JSON_THROW_ON_ERROR);
	}

	/**
	 * Restore a match context from transport-safe fields.
	 *
	 * @param array $value Scalar value or serialized fields to validate.
	 * @return self A validated value restored from serialized fields.
	 */
	public static function fromArray(array $value): self
	{
		if(!is_array($value['descriptor'] ?? null)) throw new \InvalidArgumentException('Match descriptor is missing');

		return new self(
			(string)($value['role'] ?? '')
			, new PlayerSlot((int)($value['localSlot'] ?? 0))
			, new PlayerSlot((int)($value['remoteSlot'] ?? 0))
			, new PeerId((string)($value['localPeer'] ?? ''))
			, new PeerId((string)($value['remotePeer'] ?? ''))
			, (string)($value['descriptorDigest'] ?? '')
			, $value['descriptor']
		);
	}

	/**
	 * Read the match assignment installed by the browser handshake.
	 *
	 * @return ?self The negotiated match assignment, or null outside a network match.
	 */
	public static function browser(): ?self
	{
		if(!function_exists('vrzno_env')) return null;

		$read = vrzno_env('phonicsNetworkMatch');
		$json = $read ? (string)$read() : '';

		if($json === '' || $json === 'null') return null;

		$value = json_decode($json, true, 128, JSON_THROW_ON_ERROR);

		if(!is_array($value)) throw new \UnexpectedValueException('Browser returned invalid match state');

		return self::fromArray($value);
	}

	/**
	 * Check whether the local participant is the host.
	 *
	 * @return bool A host-owned authority assignment, or true when this match is hosted locally.
	 */
	public function host(): bool { return $this->role === 'host'; }

	/**
	 * Read game-owned settings from the match descriptor.
	 *
	 * @return array<string,mixed> Game-owned match settings.
	 */
	public function settings(): array
	{
		return is_array($this->descriptor['settings'] ?? null) ? $this->descriptor['settings'] : [];
	}

	/**
	 * Serialize the match context into transport-safe fields.
	 *
	 * @return array Scalar fields suitable for JSON transport.
	 */
	public function jsonSerialize(): array
	{
		return [
			'role' => $this->role
			, 'localSlot' => $this->localSlot->value()
			, 'remoteSlot' => $this->remoteSlot->value()
			, 'localPeer' => $this->localPeer->value()
			, 'remotePeer' => $this->remotePeer->value()
			, 'descriptorDigest' => $this->descriptorDigest
			, 'descriptor' => $this->descriptor
		];
	}
}
