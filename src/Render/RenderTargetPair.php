<?php
declare(strict_types=1);
namespace Phonics\Render;

/**
 * Two same-sized render targets a pass alternates between: draw into one while sampling the other.
 *
 * Screen wipes and feedback effects keep the previous frame while the next is drawn. The pair owns both targets,
 * resizes them together, and on a resize lets the caller redraw kept content from the old textures into the new
 * targets before the old ones are released.
 */
final class RenderTargetPair
{
	/** @var array{0:RenderTarget,1:RenderTarget} The two targets by slot. */
	private array $targets;

	/**
	 * Allocate both targets.
	 *
	 * @param int $width Positive width in pixels.
	 * @param int $height Positive height in pixels.
	 * @param int $filter Texture filter for sampling either colour texture: GL_NEAREST or GL_LINEAR.
	 * @param bool $depth Whether each target carries a 24-bit depth renderbuffer.
	 * @param string $label Name used in the error raised when a framebuffer is incomplete.
	 * @return void Creates both targets, or releases what was made and rethrows on failure.
	 */
	public function __construct(
		public int $width
		, public int $height
		, private int $filter = GL_NEAREST
		, private bool $depth = true
		, private string $label = 'Render target pair'
	){
		$this->targets = $this->create($width, $height);
	}

	/**
	 * One of the two targets.
	 *
	 * @param int $slot Zero or one.
	 * @return RenderTarget The target in that slot.
	 */
	public function target(int $slot): RenderTarget
	{
		if($slot !== 0 && $slot !== 1) throw new \OutOfRangeException($this->label . ' has slots 0 and 1');

		return $this->targets[$slot];
	}

	/**
	 * The colour texture of one slot.
	 *
	 * @param int $slot Zero or one.
	 * @return int GL texture name, or zero once closed.
	 */
	public function texture(int $slot): int { return $this->target($slot)->texture(); }

	/**
	 * Change both targets' size; the contents are lost unless the caller copies them.
	 *
	 * @param int $width New positive width in pixels.
	 * @param int $height New positive height in pixels.
	 * @param callable|null $copy Runs once per slot as `$copy(int $slot, int $oldTexture, RenderTarget $new)`
	 * while the old textures still exist, so a caller can draw kept content into the new target.
	 * @return bool False when the size is unchanged and nothing was done.
	 */
	public function resize(int $width, int $height, ?callable $copy = null): bool
	{
		if($width === $this->width && $height === $this->height) return false;

		foreach($this->targets as $target)
		{
			if($target->bound()) throw new \LogicException($this->label . ' cannot be resized while bound');
		}

		$old = $this->targets;
		$this->targets = $this->create($width, $height);
		$this->width = $width;
		$this->height = $height;

		if($copy)
		{
			foreach($this->targets as $slot => $target) $copy($slot, $old[$slot]->texture(), $target);
		}

		foreach($old as $target) $target->close();

		return true;
	}

	/**
	 * Rebuild both targets after browser context restoration.
	 *
	 * @return void Recreates the GL objects of each slot.
	 */
	public function restore(): void
	{
		foreach($this->targets as $target) $target->restore();
	}

	/**
	 * Release both targets for good.
	 *
	 * @return void Repeated closure is harmless.
	 */
	public function close(): void
	{
		foreach($this->targets as $target) $target->close();
	}

	/**
	 * Allocate two targets, releasing the first if the second fails.
	 *
	 * @param int $width Positive width in pixels.
	 * @param int $height Positive height in pixels.
	 * @return array{0:RenderTarget,1:RenderTarget} The new targets.
	 */
	private function create(int $width, int $height): array
	{
		$first = new RenderTarget($width, $height, $this->filter, $this->depth, $this->label);

		try
		{
			return [$first, new RenderTarget($width, $height, $this->filter, $this->depth, $this->label)];
		}
		catch(\Throwable $error)
		{
			$first->close();

			throw $error;
		}
	}
}
