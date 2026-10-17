<?php
declare(strict_types=1);
namespace PhpWasmEngine\Input;

/** Standard gamepad numbers shared by SDL, keyboard bindings and recorded input. */
final class Buttons
{
	public const ALIASES = [
		'jump' => 0
		, 'action' => 1
		, 'lightdash' => 2
		, 'reset' => 8
		, 'pause' => 9
		, 'up' => 12
		, 'down' => 13
		, 'left' => 14
		, 'right' => 15
	];

	/**
	 * Resolve a numbered button or an existing action to one canonical key.
	 *
	 * @param int|string $key Standard button index, ButtonN spelling or named action.
	 * @return string Canonical buttonN key, or the unchanged custom action name.
	 */
	public static function name(int|string $key): string
	{
		if(isset(self::ALIASES[$key]))
		{
			$key = self::ALIASES[$key];
		}

		if(is_int($key))
		{
			if($key < 0)
			{
				throw new \InvalidArgumentException('Button indices must be nonnegative');
			}

			return 'button' . $key;
		}

		return preg_match('/^(?:button)?([0-9]+)$/i', $key, $match) ? 'button' . (int)$match[1] : $key;
	}

	/**
	 * Merge alternate names without producing duplicate button edges.
	 *
	 * @param array<int|string,bool|int|float> $values Action or button strengths.
	 * @return array<string,float> Strongest strength per canonical key, clamped to [0,1].
	 */
	public static function normalize(array $values): array
	{
		$result = [];

		foreach($values as $key => $value)
		{
			$name = self::name($key);
			$result[$name] = max($result[$name] ?? 0, min(1, max(0, (float)$value)));
		}

		return $result;
	}
}
