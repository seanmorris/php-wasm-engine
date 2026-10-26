<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Stable protocol family numbers shared with browser/network/Protocol.mjs. */
final class MessageFamily
{
	/**
	 * Match descriptor handshake family.
	 */
	public const HELLO = 1;
	/**
	 * Compatibility acknowledgement family.
	 */
	public const COMPATIBILITY = 2;
	/**
	 * Negotiated resource manifest family.
	 */
	public const RESOURCE_MANIFEST = 3;
	/**
	 * Resource readiness family.
	 */
	public const RESOURCE_READY = 4;
	/**
	 * Game activation readiness family.
	 */
	public const GAME_READY = 5;
	/**
	 * Synchronized match start family.
	 */
	public const START = 6;
	/**
	 * Player input command family.
	 */
	public const INPUT = 7;
	/**
	 * Authoritative world snapshot family.
	 */
	public const SNAPSHOT = 8;
	/**
	 * Reliable gameplay event family.
	 */
	public const EVENT = 9;
	/**
	 * Lobby or match chat family.
	 */
	public const CHAT = 10;
	/**
	 * Clock probe request family.
	 */
	public const PING = 11;
	/**
	 * Clock probe response family.
	 */
	public const PONG = 12;
	/**
	 * Full-state resynchronization request family.
	 */
	public const RESYNC = 13;
	/**
	 * World scope transition family.
	 */
	public const TRANSITION = 14;
	/**
	 * Peer disconnect family.
	 */
	public const DISCONNECT = 15;
	/**
	 * Structured network diagnostics family.
	 */
	public const DIAGNOSTICS = 16;

	private const VALUES = [
		self::HELLO,self::COMPATIBILITY,self::RESOURCE_MANIFEST,self::RESOURCE_READY
		, self::GAME_READY
		, self::START
		, self::INPUT
		, self::SNAPSHOT
		, self::EVENT
		, self::CHAT
		, self::PING
		, self::PONG
		, self::RESYNC
		, self::TRANSITION
		, self::DISCONNECT
		, self::DIAGNOSTICS
	];

	/**
	 * Check whether a numeric message family is part of this protocol version.
	 *
	 * @param int $family Stable message-family number for the payload.
	 * @return bool True when the family number belongs to the protocol.
	 */
	public static function valid(int $family): bool { return in_array($family, self::VALUES, true); }

	/**
	 * Check whether PHP gameplay code may originate this message family.
	 *
	 * @param int $family Stable message-family number for the payload.
	 * @return bool True when PHP gameplay code may originate the family.
	 */
	public static function gameplay(int $family): bool
	{
		return in_array($family, [self::INPUT,self::SNAPSHOT,self::EVENT,self::RESYNC,self::TRANSITION], true);
	}
}
