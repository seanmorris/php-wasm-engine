<?php
declare(strict_types=1);
namespace Phonics\Render;

/** Ordered, restorable renderer owned by a session or active content scope. */
interface RenderPass
{
	/**
	 * Return the globally unique pass ID.
	 * @return string Stable pipeline registration key.
	 */
	public function id(): string;

	/**
	 * List passes that must render first.
	 * @return list<string> Dependency pass IDs.
	 */
	public function after(): array;

	/**
	 * Draw this pass for the current frame.
	 * @param FrameContext $frame Current device and interpolation state.
	 * @return void Submits rendering commands without presenting the frame.
	 */
	public function render(FrameContext $frame): void;

	/**
	 * Rebuild GPU state after context restoration.
	 * @return void Replaces invalid graphics resources.
	 */
	public function restore(): void;

	/**
	 * Release GPU and host resources once.
	 * @return void Makes the pass permanently inactive.
	 */
	public function close(): void;
}
