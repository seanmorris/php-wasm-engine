<?php
declare(strict_types=1);
namespace PhpSlalom\Input;

use Phonics\Input\Bindings;
use Phonics\Input\InputCode;
use Phonics\Input\Key;
use Phonics\Input\Pad;
use Phonics\Input\Source;

/** Named steering actions and their independent device defaults. */
final class SlalomActions
{
	public const LEFT = InputCode::GAME_START;
	public const RIGHT = InputCode::GAME_START + 1;

	/** Rows shown by the shared binding editor. */
	public static function rows(): array
	{
		return [
			'left' => [self::LEFT, 1, 'Steer left']
			, 'right' => [self::RIGHT, 1, 'Steer right']
		];
	}

	/** Original keys and pointer buttons plus standardized D-pad and left-stick input. */
	public static function defaults(): Bindings
	{
		$key = static fn(int|string $code): Source => new Source(Source::KEY, is_string($code) ? ord($code) : $code);

		return new Bindings([
			self::LEFT => [
				1 => [
					$key(Key::LEFT)
					, $key('a')
					, $key('j')
					, new Source(Source::MOUSE_BUTTON, Source::PRIMARY)
					, new Source(Source::PAD_BUTTON, Pad::LEFT)
					, new Source(Source::PAD_AXIS, Pad::LEFT_X, -1)
				]
			]
			, self::RIGHT => [
				1 => [
					$key(Key::RIGHT)
					, $key('d')
					, $key('l')
					, new Source(Source::MOUSE_BUTTON, Source::SECONDARY)
					, new Source(Source::PAD_BUTTON, Pad::RIGHT)
					, new Source(Source::PAD_AXIS, Pad::LEFT_X, 1)
				]
			]
		]);
	}

	/** Pause and menu controls remain fixed and available. */
	public static function allowed(Source $source): bool
	{
		return !($source->kind === Source::KEY && in_array($source->code, [Key::ESCAPE, ord('p')], true))
			&& !($source->kind === Source::PAD_BUTTON && $source->code === Pad::START);
	}
}
