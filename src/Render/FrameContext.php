<?php
declare(strict_types=1);
namespace Phonics\Render;

/** Values shared by ordered render passes for one presented frame. */
final class FrameContext
{
	/**
	 * Describe the current render frame.
	 *
	 * @param object $device Device exposing ready(), begin(), and present().
	 * @param float $alpha Fixed-step interpolation fraction.
	 * @param int $generation Graphics generation used for context restoration.
	 * @return void Stores values for pass rendering.
	 */
	public function __construct(
		public object $device
		, public float $alpha
		, public int $generation
	) {}
}
