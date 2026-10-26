<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Host or peer authority assignment for one replicated entity/component. */
final class AuthorityOwner implements \JsonSerializable
{

	private function __construct(private string $kind, private ?PeerId $peer = null) {}

	/**
	 * Create an authority assignment owned by the host.
	 *
	 * @return self A host-owned authority assignment, or true when this match is hosted locally.
	 */
	public static function host(): self { return new self('host'); }

	/**
	 * Create an authority assignment for a specific peer.
	 *
	 * @param PeerId $peer Session peer associated with authority or input ownership.
	 * @return self A peer-owned authority assignment.
	 */
	public static function peer(PeerId $peer): self { return new self('peer', $peer); }

	/**
	 * Restore an authority assignment from transport-safe fields.
	 *
	 * @param array $value Scalar value or serialized fields to validate.
	 * @return self A validated value restored from serialized fields.
	 */
	public static function fromArray(array $value): self
	{
		if(($value['kind'] ?? null) === 'host') return self::host();

		if(($value['kind'] ?? null) === 'peer' && is_string($value['peer'] ?? null))
		return self::peer(new PeerId($value['peer']));

		throw new \InvalidArgumentException('Invalid authority owner');
	}

	/**
	 * Check whether this authority assignment belongs to the host.
	 *
	 * @return bool True when the host owns the authority assignment.
	 */
	public function isHost(): bool { return $this->kind === 'host'; }

	/**
	 * Read the peer that owns this authority assignment.
	 *
	 * @return ?PeerId The owning peer, or null for host authority.
	 */
	public function peerId(): ?PeerId { return $this->peer; }

	/**
	 * Compare this authority assignment with another value of the same kind.
	 *
	 * @param self $other Validated other used by this authority assignment.
	 * @return bool True when both values represent the same identity.
	 */
	public function equals(self $other): bool
	{
		return $this->kind === $other->kind
			&& ($this->peer === null
				? $other->peer === null
				: $other->peer !== null && $this->peer->equals($other->peer)
			);
	}

	/**
	 * Serialize the authority assignment into transport-safe fields.
	 *
	 * @return array Scalar fields suitable for JSON transport.
	 */
	public function jsonSerialize(): array
	{
		return ['kind' => $this->kind] + ($this->peer ? ['peer' => $this->peer->value()] : []);
	}
}
