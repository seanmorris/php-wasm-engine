<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\Level;

/**
 * Order model-sector faces and batch adjacent faces with the same material state.
 *
 * Translucency keeps back-to-front ordering, while the shared index buffer avoids
 * one PHP-to-WebGL draw call for every BSP cell covered by a liquid surface.
 */
final class VolumeDrawList
{
	/** Return indexed batches, excluding drained volumes at the rendered height. */
	public static function build(MapMesh $mesh, Level $level, array $view, bool $transparent): array
	{
		[$x,$y,$eye,$alpha] = $view;
		$spans = [];
		foreach($mesh->volumeSpans as $span)
		{
			if(($span['opacity'] < 1 || $span['additive']) !== $transparent) continue;
			$volume = $span['volume'];
			if($volume && !($volume->flags & 8)
				&& $volume->top($span['x'], $span['y'], $alpha) <= $volume->bottom($span['x'], $span['y'], $alpha)) continue;
			$span['light'] = $volume && !($volume->flags & 1) ? $volume->model->light : $level->sectors[$span['sector']]->light;
			$span['clipBounds'] = $volume !== null;
			$span['key'] = $span['opacity'] . ':' . $span['light'] . ':' . (int)$span['additive'] . ':' . (int)$span['clipBounds'];
			$model = $level->sectors[$span['model']];
			$height = $span['plane'] ? $model->ceilingZ($span['x'], $span['y'], $alpha) : $model->floorZ($span['x'], $span['y'], $alpha);
			$span['distance'] = ($span['x'] - $x) ** 2 + ($span['y'] - $y) ** 2 + ($height - $eye) ** 2;
			$spans[] = $span;
		}
		usort($spans, $transparent
			? static fn(array $a, array $b): int => $b['distance'] <=> $a['distance']
			: static fn(array $a, array $b): int => $a['key'] <=> $b['key']);
		$batches = [];
		foreach($spans as $span)
		{
			$last = count($batches) - 1;
			if($last < 0 || $batches[$last]['key'] !== $span['key'])
			{
				$batches[] = ['key' => $span['key'],'opacity' => $span['opacity'],
					'light' => $span['light'],'additive' => $span['additive'],'clipBounds' => $span['clipBounds'],'indices' => []];
				$last++;
			}
			array_push($batches[$last]['indices'], ...range($span['first'], $span['first'] + $span['count'] - 1));
		}
		return $batches;
	}
}
