<?php
declare(strict_types=1);
namespace Phonics\Input;

/** A physical source, independent of the logical action that uses it. */
final class Source implements \JsonSerializable
{
	/** Keyboard key source kind. */
	public const KEY = 'key';

	/** Mouse button source kind. */
	public const MOUSE_BUTTON = 'mouse-button';

	/** Relative mouse axis source kind. */
	public const MOUSE_AXIS = 'mouse-axis';

	/** Relative mouse wheel source kind. */
	public const WHEEL = 'wheel';

	/** Standardized controller button source kind. */
	public const PAD_BUTTON = 'pad-button';

	/** Standardized controller axis source kind. */
	public const PAD_AXIS = 'pad-axis';

	/** Keyboard and mouse binding device group. */
	public const KEYBOARD = 'keyboard';

	/** Controller binding device group. */
	public const CONTROLLER = 'controller';

	/** Horizontal mouse or wheel axis index. */
	public const X = 0;

	/** Vertical mouse or wheel axis index. */
	public const Y = 1;

	/** Primary mouse button index. */
	public const PRIMARY = 1;

	/** Middle mouse button index. */
	public const MIDDLE = 2;

	/** Secondary mouse button index. */
	public const SECONDARY = 3;

	/**
	 * Construct a physical button or one directional half of an axis.
	 *
	 * @param string $kind Physical input kind.
	 * @param int $code Key, button or axis identifier.
	 * @param int $sign Axis direction, or one for buttons.
	 * @return void Validates and stores the physical source.
	 */
	public function __construct(public readonly string $kind, public readonly int $code, public readonly int $sign = 1)
	{
		$valid = match($kind)
		{
			self::KEY => $code > 0 && $code <= 0x7FFFFFFF,
			self::MOUSE_BUTTON => $code >= 1 && $code <= 5,
			self::MOUSE_AXIS, self::WHEEL => $code === self::X || $code === self::Y,
			self::PAD_BUTTON => $code >= 0 && $code < 21,
			self::PAD_AXIS => $code >= 0 && $code < 6,
			default => false,
		};

		if(!$valid || !in_array($sign, [-1, 1], true) || (!$this->axis() && $sign !== 1))
		throw new \InvalidArgumentException('Invalid physical input source');
	}

	/**
	 * Identify the group used for independent binding alternatives.
	 *
	 * @return string Keyboard/mouse or controller device group.
	 */
	public function device(): string
	{
		return str_starts_with($this->kind, 'pad-') ? self::CONTROLLER : self::KEYBOARD;
	}

	/**
	 * Check whether this source represents a directional axis half.
	 *
	 * @return bool True for mouse, wheel or controller axes.
	 */
	public function axis(): bool
	{
		return in_array($this->kind, [self::MOUSE_AXIS, self::WHEEL, self::PAD_AXIS], true);
	}

	/**
	 * Check whether the source supplies displacement instead of a held rate.
	 *
	 * @return bool True for mouse motion and wheel pulses.
	 */
	public function relative(): bool { return $this->kind === self::MOUSE_AXIS || $this->kind === self::WHEEL; }

	/**
	 * Build a stable physical identity for state and conflict maps.
	 *
	 * @return string Kind, code and direction separated by colons.
	 */
	public function id(): string { return $this->kind . ':' . $this->code . ':' . $this->sign; }

	/**
	 * Return the theme-independent symbol used by the shared controller glyph atlas.
	 *
	 * The font resolves the same symbol to Xbox, PlayStation, or generic artwork at render time.
	 * Keyboard and mouse sources return null because arbitrary keys do not have one-to-one glyphs.
	 *
	 * @return string|null Annotated controller symbol, or null when this source needs a text label.
	 */
	public function hint(): ?string
	{
		if($this->kind === self::PAD_BUTTON)
		return [
			Pad::A => '⓿'
			, Pad::B => '❶'
			, Pad::X => '❷'
			, Pad::Y => '❸'
			, Pad::LB => '❹'
			, Pad::RB => '❺'
			, Pad::BACK => '❽'
			, Pad::START => '❾'
			, Pad::LEFT_STICK => '❿'
			, Pad::RIGHT_STICK => '⓫'
			, Pad::LEFT => '←'
			, Pad::RIGHT => '→'
			, Pad::UP => '↑'
			, Pad::DOWN => '↓'
		][$this->code] ?? null;

		if($this->kind !== self::PAD_AXIS) return null;

		return match($this->code)
		{
			Pad::LEFT_X => $this->sign < 0 ? '🡨' : '🡪',
			Pad::LEFT_Y => $this->sign < 0 ? '🡩' : '🡫',
			Pad::RIGHT_X => $this->sign < 0 ? '🡰' : '🡲',
			Pad::RIGHT_Y => $this->sign < 0 ? '🡱' : '🡳',
			Pad::LT => '❻',
			Pad::RT => '❼',
			default => null,
		};
	}

	/**
	 * Provide a portable source representation.
	 *
	 * @return array{kind:string,code:int,sign:int} Validated physical source fields.
	 */
	public function jsonSerialize(): array
	{
		return ['kind' => $this->kind, 'code' => $this->code, 'sign' => $this->sign];
	}

	/**
	 * Validate stored data without coercing malformed values.
	 *
	 * @param mixed $data Decoded source representation.
	 * @return self Validated physical source.
	 */
	public static function restore(mixed $data): self
	{
		if(!is_array($data) || !is_string($data['kind'] ?? null) || !is_int($data['code'] ?? null) || !is_int($data[
			'sign'
		] ?? null
		))
		throw new \InvalidArgumentException('Invalid stored source');

		return new self($data['kind'], $data['code'], $data['sign']);
	}

	/**
	 * Name arbitrary bindings with controller-family button names when available.
	 *
	 * @param string $theme Active controller glyph family or keyboard.
	 * @return string Human-readable binding label.
	 */
	public function label(string $theme = 'generic'): string
	{
		if($this->kind === self::KEY)
		{
			if($this->code >= 33 && $this->code <= 126) return strtoupper(chr($this->code));

			return [
				Key::ENTER => 'Enter'
				, Key::KEYPAD_ENTER => 'Keypad Enter'
				, Key::ESCAPE => 'Escape'
				, Key::SPACE => 'Space'
				, Key::TAB => 'Tab'
				, Key::BACKSPACE => 'Backspace'
				, Key::LEFT => 'Left'
				, Key::RIGHT => 'Right'
				, Key::UP => 'Up'
				, Key::DOWN => 'Down'
				, Key::LEFT_CTRL => 'Left Ctrl'
				, Key::RIGHT_CTRL => 'Right Ctrl'
				, Key::LEFT_SHIFT => 'Left Shift'
			][$this->code] ?? ('Key ' . $this->code);
		}

		if($this->kind === self::MOUSE_BUTTON)
		return [
			self::PRIMARY => 'Mouse 1'
			, self::MIDDLE => 'Mouse 3'
			, self::SECONDARY => 'Mouse 2'
			, 4 => 'Mouse 4'
			, 5 => 'Mouse 5'
		][$this->code];

		if($this->kind === self::PAD_BUTTON)
		{
			$names = [
				Pad::A => 'A'
				, Pad::B => 'B'
				, Pad::X => 'X'
				, Pad::Y => 'Y'
				, Pad::BACK => 'Back'
				, Pad::GUIDE => 'Guide'
				, Pad::START => 'Start'
				, Pad::LEFT_STICK => 'LS'
				, Pad::RIGHT_STICK => 'RS'
				, Pad::LB => 'LB'
				, Pad::RB => 'RB'
				, Pad::UP => 'Dpad Up'
				, Pad::DOWN => 'Dpad Down'
				, Pad::LEFT => 'Dpad Left'
				, Pad::RIGHT => 'Dpad Right'
			];

			if($theme === 'playstation')
			$names = [
				Pad::A => 'Cross'
				, Pad::B => 'Circle'
				, Pad::X => 'Square'
				, Pad::Y => 'Triangle'
				, Pad::BACK => 'Share'
				, Pad::START => 'Options'
				, Pad::LEFT_STICK => 'L3'
				, Pad::RIGHT_STICK => 'R3'
				, Pad::LB => 'L1'
				, Pad::RB => 'R1'
			] + $names;

			return $names[$this->code] ?? ('Pad ' . $this->code);
		}

		$axis = $this->kind === self::PAD_AXIS
			? (['LS X','LS Y','RS X','RS Y','LT','RT'][$this->code])
			: (($this->kind === self::WHEEL ? 'Wheel ' : 'Mouse ') . ($this->code === self::X ? 'X' : 'Y'));

		return $axis . ($this->sign < 0 ? '-' : '+');
	}
}
