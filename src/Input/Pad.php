<?php
declare(strict_types=1);
namespace Phonics\Input;

/** SDL standardized physical controller buttons and axes, independent of logical aliases. */
final class Pad
{
	/** Standardized A or bottom face-button index. */
	public const A = 0;

	/** Standardized B or right face-button index. */
	public const B = 1;

	/** Standardized X or left face-button index. */
	public const X = 2;

	/** Standardized Y or top face-button index. */
	public const Y = 3;

	/** Standardized Back or Share button index. */
	public const BACK = 4;

	/** Standardized Guide button index. */
	public const GUIDE = 5;

	/** Standardized Start or Options button index. */
	public const START = 6;

	/** Standardized left-stick click index. */
	public const LEFT_STICK = 7;

	/** Standardized right-stick click index. */
	public const RIGHT_STICK = 8;

	/** Standardized left shoulder button index. */
	public const LB = 9;

	/** Standardized right shoulder button index. */
	public const RB = 10;

	/** Standardized directional-pad up index. */
	public const UP = 11;

	/** Standardized directional-pad down index. */
	public const DOWN = 12;

	/** Standardized directional-pad left index. */
	public const LEFT = 13;

	/** Standardized directional-pad right index. */
	public const RIGHT = 14;

	/** Standardized left-stick horizontal axis index. */
	public const LEFT_X = 0;

	/** Standardized left-stick vertical axis index. */
	public const LEFT_Y = 1;

	/** Standardized right-stick horizontal axis index. */
	public const RIGHT_X = 2;

	/** Standardized right-stick vertical axis index. */
	public const RIGHT_Y = 3;

	/** Standardized left-trigger axis index. */
	public const LT = 4;

	/** Standardized right-trigger axis index. */
	public const RT = 5;
}
