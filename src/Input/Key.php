<?php
declare(strict_types=1);
namespace Phonics\Input;

/** SDL key symbols available to binding definitions even in hosts without SDL loaded. */
final class Key
{
	/** Enter key symbol. */
	public const ENTER = 13;

	/** Escape key symbol. */
	public const ESCAPE = 27;

	/** Space key symbol. */
	public const SPACE = 32;

	/** Tab key symbol. */
	public const TAB = 9;

	/** Backspace key symbol. */
	public const BACKSPACE = 8;

	/** Left arrow key symbol. */
	public const LEFT = 1073741904;

	/** Right arrow key symbol. */
	public const RIGHT = 1073741903;

	/** Up arrow key symbol. */
	public const UP = 1073741906;

	/** Down arrow key symbol. */
	public const DOWN = 1073741905;

	/** Left Control key symbol. */
	public const LEFT_CTRL = 1073742048;

	/** Right Control key symbol. */
	public const RIGHT_CTRL = 1073742052;

	/** Left Shift key symbol. */
	public const LEFT_SHIFT = 1073742049;

	/** Numeric keypad Enter key symbol. */
	public const KEYPAD_ENTER = 1073741912;
}
