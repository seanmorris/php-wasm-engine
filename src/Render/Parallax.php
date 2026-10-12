<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
use PhpWasmEngine\Tiled\
{Layer,TileMap};

/** Camera-relative image layout, independent of SDL and the GPU. */
final class Parallax
{
	public static function camera(Layer $layer,TileMap $map,Camera $camera): array
	{
		$ox = $map->x + ($map->data['parallaxoriginx'] ?? 0);
		$oy = $map->y + ($map->data['parallaxoriginy'] ?? 0);
		$halfX = $camera->width / $camera->zoom / 2;
		$halfY = $camera->height / $camera->zoom / 2;
		return [
			$ox + ($camera->x + $halfX - $ox) * $layer->parallaxX - $halfX
			, $oy + ($camera->y + $halfY - $oy) * $layer->parallaxY - $halfY
		];
	}
	public static function image(Layer $layer,float $milliseconds = 0): ?string
	{
		if(!$layer->imageFrames)
		{ return $layer->image; }
		$time = fmod(max(0,$milliseconds),array_sum(array_column($layer->imageFrames,'duration')));
		foreach($layer->imageFrames as $frame)
		{
			if($time < $frame['duration'])
			{ return $frame['image']; }
			$time -= $frame['duration'];
		}
		return $layer->imageFrames[0]['image'];
	}
	/** One clipped screen quad, with bounded UV phase even in an infinite world. */
	public static function layout(Layer $layer,TileMap $map,Camera $camera,float $milliseconds = 0,float $stackHeight = 0): ?array
	{
		if(!$layer->visible || $layer->opacity <= 0 || $layer->imageWidth <= 0 || $layer->imageHeight <= 0)
		{ return null; }
		if($camera->zoom <= 0)
		{ throw new \InvalidArgumentException('Camera zoom must be positive'); }
		[$cx,$cy] = self::camera($layer,$map,$camera);
		$x = $map->x + $layer->offsetX - $cx;
		$y = $map->y + $layer->offsetY - $cy;
		if(isset($layer->properties['from']))
		{
			$anchor = ['top' => 0,'center' => .5,'bottom' => 1][$layer->properties['from']];
			$height = $layer->properties['stackHeight'] ?? $stackHeight;
			$x = $layer->offsetX - ($camera->x - $map->x - ($map->data['parallaxoriginx'] ?? 0)) * $layer->parallaxX;
			$y = ($camera->height / $camera->zoom - $height) * $anchor + $layer->offsetY
				- ($camera->y - $map->y - ($map->data['parallaxoriginy'] ?? 0)) * $layer->parallaxY;
		}
		$seconds = max(0,$milliseconds) / 1000;
		$x += (float)($layer->properties['scrollX'] ?? 0) * $seconds;
		$y += (float)($layer->properties['scrollY'] ?? 0) * $seconds;
		$width = $layer->imageWidth * $camera->zoom;
		$height = $layer->imageHeight * $camera->zoom;
		$x *= $camera->zoom;
		$y *= $camera->zoom;
		$left = $layer->repeatX?0.0:max(0.0,$x);
		$top = $layer->repeatY?0.0:max(0.0,$y);
		$right = $layer->repeatX?(float)$camera->width:min((float)$camera->width,$x + $width);
		$bottom = $layer->repeatY?(float)$camera->height:min((float)$camera->height,$y + $height);
		if($right <= $left || $bottom <= $top)
		{ return null; }
		$u = ($left - $x) / $width;
		$v = ($top - $y) / $height;
		if($layer->repeatX)
		{ $u -= floor($u); }
		if($layer->repeatY)
		{ $v -= floor($v); }
		return ['rect' => [$left,$top,$right - $left,$bottom - $top],'uv' => [$u,$v,($right - $left) / $width,($bottom - $top) / $height]];
	}
}
