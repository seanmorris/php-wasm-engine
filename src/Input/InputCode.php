<?php
declare(strict_types=1);
namespace Phonics\Input;

/** Shared logical commands. Game actions start above the reserved engine range. */
final class InputCode
{
	/** Highest code reserved for shared engine actions. */
	public const RESERVED_MAX = 0xFFFF;

	/** First code available to game-specific actions. */
	public const GAME_START = 0x10000;

	/** Legacy jump alias retaining its original button identity. */
	public const JUMP = 0;

	/** Legacy action alias retaining its original button identity. */
	public const ACTION = 1;

	/** Legacy light-dash alias retaining its original button identity. */
	public const LIGHTDASH = 2;

	/** Legacy reset alias retaining its original button identity. */
	public const RESET = 8;

	/** Shared pause action. */
	public const PAUSE = 9;

	/** Shared upward navigation action. */
	public const UP = 12;

	/** Shared downward navigation action. */
	public const DOWN = 13;

	/** Shared leftward navigation action. */
	public const LEFT = 14;

	/** Shared rightward navigation action. */
	public const RIGHT = 15;

	/** Shared confirmation action. */
	public const YES = 0x100;

	/** Shared cancellation action. */
	public const NO = 0x101;

	/** Shared restoration action. */
	public const REVERT = 0x102;

	/** Shared page-up navigation action. */
	public const PAGE_UP = 0x103;

	/** Shared page-down navigation action. */
	public const PAGE_DOWN = 0x104;

	/** Shared first-item navigation action. */
	public const HOME = 0x105;

	/** Shared last-item navigation action. */
	public const END = 0x106;

	/** Logical actions consumed by fixed engine menus. */
	public const MENU = [
		'confirm' => self::YES
		, 'back' => self::NO
		, 'pause' => self::PAUSE
		, 'revert' => self::REVERT
		, 'up' => self::UP
		, 'down' => self::DOWN
		, 'left' => self::LEFT
		, 'right' => self::RIGHT
		, 'pageUp' => self::PAGE_UP
		, 'pageDown' => self::PAGE_DOWN
		, 'home' => self::HOME
		, 'end' => self::END
	];
}
