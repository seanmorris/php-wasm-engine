<?php
declare(strict_types=1);
namespace PhpWasmEngine\Gameplay;

use PhpWasmEngine\Render\BitmapFont;

/** A non-solid, map-authored line of bitmap text in world coordinates. */
final class TextActor
{
	/**
	 * @param array{float,float,float,float} $tint RGBA multiplier for the font glyphs.
	 */
	public function __construct(
		public string $text
		, public float $x
		, public float $y
		, public float $width = INF
		, public float $scale = 1
		, public array $tint = [1, 1, 1, 1]
		, public string $align = 'left'
		, public float $opacity = 1
		, public ?int $sourceId = null
	){
		BitmapFont::characters($text);

		if(!is_finite($x) || !is_finite($y) || ($width !== INF && (!is_finite($width) || $width <= 0))
			|| !is_finite($scale) || $scale <= 0 || !in_array($align, ['left', 'center', 'right'], true)
			|| !is_finite($opacity) || $opacity < 0 || $opacity > 1
		){
			throw new \InvalidArgumentException('TextActor metrics must be finite and valid');
		}

		if(count($tint) !== 4)
		{
			throw new \InvalidArgumentException('TextActor tint must have four channels');
		}

		foreach($tint as $channel)
		{
			if(!is_numeric($channel) || !is_finite((float)$channel) || $channel < 0 || $channel > 1)
			{
				throw new \InvalidArgumentException('TextActor tint channels must be in [0, 1]');
			}
		}
	}
}
