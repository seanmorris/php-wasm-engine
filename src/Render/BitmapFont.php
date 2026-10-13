<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;

use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Tiled\TileMap;

/** A font assembled from annotated Tiled tilesets, independent of GPU allocation. */
final class BitmapFont
{
	public TileMap $map;
	public float $lineHeight;
	public float $spaceAdvance;
	public string $theme = 'generic';
	private array $glyphs = [];
	private string $fallback;
	private bool $caseFold;

	/**
	 * Read font metrics and glyph annotations, optionally adding themed symbol sheets.
	 *
	 * @param Loader $loader Shared Tiled and asset loader.
	 * @param string $path Font TSJ with font.* and glyph.* custom properties.
	 * @param list<string> $symbols Additional annotated TSJ paths sharing this font atlas.
	 * @return void Indexes characters by metadata, never by their position in the sheet.
	 */
	public function __construct(Loader $loader, string $path, array $symbols = [])
	{
		$data = $loader->assets->json($path);
		$properties = Loader::properties($data['properties'] ?? []);
		$this->lineHeight = self::positive($properties['font.lineHeight'] ?? $data['tileheight']);

		$this->spaceAdvance = self::positive(
			$properties['font.spaceAdvance'] ?? $data['tilewidth']
		);
		$this->fallback = $properties['font.fallback'] ?? '?';
		$this->caseFold = $properties['font.caseFold'] ?? false;
		$this->map = new TileMap($path, ['tilewidth' => 1, 'tileheight' => 1]);
		$first = 1;

		foreach(array_merge([$path], $symbols) as $source)
		{
			$tiles = $loader->tileset($source);
			$this->map->tilesets[] = ['firstgid' => $first, 'definitions' => $tiles];

			foreach($tiles as $id => $tile)
			{
				$p = $tile['properties'];

				if(!isset($p['glyph.char']))
				{
					continue;
				}

				foreach(['glyph.offsetX', 'glyph.offsetY'] as $metric)
				{
					if(isset($p[$metric]) && (!is_numeric(
						$p[$metric]
					) || !is_finite(
						(float)$p[$metric]
					))){
						throw new \InvalidArgumentException('Glyph offsets must be finite numbers');
					}
				}

				$chars = self::characters($p['glyph.char']);

				if(count($chars) !== 1)
				{
					throw new \InvalidArgumentException(
						'glyph.char must contain one Unicode character'
					);
				}

				$width = (int)($p['glyph.width'] ?? $tile['rect'][2]);
				$height = (int)($p['glyph.height'] ?? $tile['rect'][3]);
				$crop = [(int)($p['glyph.cropX'] ?? 0)
				, (int)($p['glyph.cropY'] ?? 0)
				, $width
					, $height
				];
				$probe = new Sprite($first + $id);
				$probe->crop = $crop;
				$probe->rectangle($tile['rect']);
				$glyph = ['gid' => $first + $id, 'crop' => $crop
					, 'advance' => self::positive($p['glyph.advance'] ?? $width)
					, 'x' => (float)($p['glyph.offsetX'] ?? 0)
					, 'y' => (float)($p['glyph.offsetY'] ?? 0)
					, 'tint' => (bool)($p['glyph.tint'] ?? true)
				];
				$theme = $p['glyph.theme'] ?? '';

				foreach(array_merge($chars, self::characters($p['glyph.aliases'] ?? '')) as $char)
				{
					if(isset($this->glyphs[$theme][$char]))
					{
						throw new \InvalidArgumentException(
							"Duplicate glyph $char for theme $theme"
						);
					}

					$this->glyphs[$theme][$char] = $glyph;
				}
			}
			$first += $tiles ? max(array_keys($tiles)) + 1 : 0;
		}

		if(!isset($this->glyphs[''][$this->fallback]))
		{
			throw new \InvalidArgumentException('The font must annotate its fallback glyph');
		}
	}

	/**
	 * Validate a positive finite pixel metric.
	 *
	 * @param mixed $value Numeric metric read from Tiled properties.
	 * @return float Validated advance, scale, or line height.
	 */
	private static function positive(mixed $value): float
	{
		if(!is_numeric($value) || !is_finite((float)$value) || $value <= 0)
		{
			throw new \InvalidArgumentException('Font metrics must be positive and finite');
		}

		return (float)$value;
	}

	/**
	 * Split UTF-8 into codepoints without requiring an optional PHP extension.
	 *
	 * @param string $text Valid UTF-8 text to split.
	 * @return list<string> Unicode codepoints; invalid UTF-8 raises InvalidArgumentException.
	 */
	public static function characters(string $text): array
	{
		$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

		if($chars === false)
		{
			throw new \InvalidArgumentException('Text must contain valid UTF-8');
		}

		return $chars;
	}

	/**
	 * Resolve a themed character, ASCII case alias, or fallback without changing the text.
	 *
	 * @param string $char One Unicode codepoint.
	 * @return array<string,mixed> Glyph image, advance, and placement metadata.
	 */
	public function glyph(string $char): array
	{
		$char = $this->caseFold && strlen($char) === 1 ? strtoupper($char) : $char;
		return $this->glyphs[$this->theme][$char] ?? $this->glyphs[''][$char]
			?? $this->glyphs['generic'][$char] ?? $this->glyphs[''][$this->fallback];
	}

	/**
	 * Lay out text at the origin with bounded lines and metadata-derived spacing.
	 *
	 * @param string $text UTF-8 text; CRLF is normalized to a line break.
	 * @param float $scale Positive pixel scale used for both glyphs and advances.
	 * @param float $maxWidth Maximum line width in output pixels; infinity disables wrapping.
	 * @param array{float,float,float,float} $tint RGBA multiplier for tintable glyphs.
	 * @return array{sprites:list<Sprite>,width:float,height:float,positions:array} Glyph batch,
	 * measured line box, and codepoint caret positions including the final caret.
	 */
	public function layout(string $text, float $scale = 1, float $maxWidth = INF,
		array $tint = [1, 1, 1, 1]
	): array
	{
		self::positive($scale);

		if(is_nan($maxWidth) || $maxWidth <= 0)
		{
			throw new \InvalidArgumentException('Text width must be positive');
		}

		$x = $y = $width = 0.0;
		$sprites = $positions = [];
		$chars = self::characters(str_replace(["\r\n", "\r"], "\n", $text));

		foreach($chars as $char)
		{
			$glyph = $this->glyph($char);
			$advance = ($char === ' ' ? $this->spaceAdvance : $glyph['advance']) * $scale;

			if($char === "\n" || ($x > 0 && $x + $advance > $maxWidth))
			{
				$width = max($width, $x);
				$x = 0;
				$y += $this->lineHeight * $scale;
			}

			$positions[] = [$x, $y];

			if($char === "\n")
			{
				continue;
			}

			if($char !== ' ')
			{
				$sprite = new Sprite(
					$glyph['gid']
					, $x + $glyph['x'] * $scale
					, $y + $glyph['y'] * $scale
					, 0
					, 0
				);
				$sprite->crop = $glyph['crop'];
				$sprite->scaleX = $sprite->scaleY = $scale;
				$sprite->outline = $sprite->animated = false;
				$sprite->tint = $glyph['tint'] ? $tint : [1, 1, 1, $tint[3]];
				$sprites[] = $sprite;
			}

			$x += $advance;
		}
		$positions[] = [$x, $y];
		return ['sprites' => $sprites, 'width' => max($width, $x)
			, 'height' => $text === '' ? 0.0 : $y + $this->lineHeight * $scale
			, 'positions' => $positions
		];
	}

	/**
	 * Measure the same line boxes used by sprite layout.
	 *
	 * @param string $text UTF-8 text to measure.
	 * @param float $scale Positive output scale.
	 * @param float $maxWidth Maximum output line width.
	 * @return array{width:float,height:float} Dimensions in output pixels.
	 */
	public function measure(string $text, float $scale = 1, float $maxWidth = INF): array
	{
		$layout = $this->layout($text, $scale, $maxWidth);
		return ['width' => $layout['width'], 'height' => $layout['height']];
	}
}
