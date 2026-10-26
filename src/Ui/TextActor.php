<?php
declare(strict_types=1);
namespace Phonics\Ui;

use Phonics\Render\BitmapFont;

/** A non-solid, map-authored line of bitmap text in world coordinates. */
final class TextActor
{

	/**
	 * Initialize TextActor state and its owned services.
	 *
	 * @param string $text Text content to display.
	 * @param float $x World X coordinate in pixels.
	 * @param float $y World Y coordinate in pixels.
	 * @param float $width Width in pixels.
	 * @param float $scale Positive text scale.
	 * @param array{float,float,float,float} $tint RGBA multiplier for the font glyphs.
	 * @param string $align Horizontal text alignment.
	 * @param float $opacity Layer opacity from zero to one.
	 * @param int|null $sourceId Original Tiled object ID for diagnostics and editing, if any.
	 * @return void Validates and retains renderer-independent world text state.
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
