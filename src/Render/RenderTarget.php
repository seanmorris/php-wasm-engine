<?php
declare(strict_types=1);
namespace Phonics\Render;

/**
 * An offscreen colour target: one RGBA8 texture, an optional 24-bit depth renderbuffer, and the framebuffer that
 * joins them.
 *
 * Post-process passes and offscreen scene targets allocate the same three GL objects, bind them while saving the
 * caller's framebuffer, and rebuild them after context restoration. This class owns that lifecycle: `bind()`
 * remembers the framebuffer bound before it and sets the viewport to the target, `unbind()` returns to that
 * framebuffer, `restore()` recreates the objects with the same names' roles after the context is lost, and
 * `close()` releases them for good. The GL calls are the ones the hand-written targets made, in the same order.
 */
final class RenderTarget
{
	/** @var array<int,int> Framebuffer and depth renderbuffer names as the GL bindings return them. */
	private array $framebuffer = [], $depthBuffer = [];
	/** Colour texture name, and the framebuffer bound when this target was bound. */
	private int $texture = 0, $parent = 0;
	/** Whether the target is bound, and whether it has been closed for good. */
	private bool $bound = false, $closed = false;

	/**
	 * Allocate the target.
	 *
	 * @param int $width Positive width in pixels.
	 * @param int $height Positive height in pixels.
	 * @param int $filter Texture filter for sampling the colour texture: GL_NEAREST or GL_LINEAR.
	 * @param bool $depth Whether to attach a 24-bit depth renderbuffer.
	 * @param string $label Name used in the error raised when the framebuffer is incomplete.
	 * @return void Creates the GL objects, or releases what was made and rethrows on failure.
	 */
	public function __construct(
		public int $width
		, public int $height
		, private int $filter = GL_NEAREST
		, private bool $depth = true
		, private string $label = 'Render target'
	){
		if($width <= 0 || $height <= 0)
		{
			throw new \InvalidArgumentException($label . ' dimensions must be positive');
		}

		$this->create();
	}

	/**
	 * The colour texture to sample the target's contents from.
	 *
	 * @return int GL texture name, or zero once closed.
	 */
	public function texture(): int { return $this->texture; }

	/**
	 * The framebuffer draws go to while the target is bound.
	 *
	 * @return int GL framebuffer name, or zero once closed.
	 */
	public function framebuffer(): int { return $this->framebuffer[0] ?? 0; }

	/**
	 * The depth renderbuffer, when the target has one.
	 *
	 * @return int GL renderbuffer name, or zero without depth or once closed.
	 */
	public function depthRenderbuffer(): int { return $this->depthBuffer[0] ?? 0; }

	/**
	 * The framebuffer that was bound when `bind()` was called.
	 *
	 * @return int GL framebuffer name, zero for the drawing buffer.
	 */
	public function parent(): int { return $this->parent; }

	/**
	 * Whether draws currently go to this target.
	 *
	 * @return bool True between `bind()` and `unbind()`.
	 */
	public function bound(): bool { return $this->bound; }

	/**
	 * Direct draws to the target: remember the caller's framebuffer, bind this one, set the viewport to the target,
	 * and optionally clear it.
	 *
	 * @param array{0:float|int,1:float|int,2:float|int,3:float|int}|null $clearColor Normalized RGBA to clear
	 * colour, and depth to one, with; null leaves the contents alone.
	 * @return void Leaves the target bound until `unbind()`.
	 */
	public function bind(?array $clearColor = null): void
	{
		if($this->closed || $this->bound)
		{
			throw new \LogicException($this->label . ' cannot be bound now');
		}

		$this->parent = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		$this->bound = true;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
		glViewport(0, 0, $this->width, $this->height);

		if($clearColor === null) return;

		glClearColor(...$clearColor);
		glClearDepth(1);
		glDepthMask(true);
		glClear($this->depth ? GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT : GL_COLOR_BUFFER_BIT);
	}

	/**
	 * Return draws to the framebuffer that was bound before `bind()`.
	 *
	 * @param int|null $viewportWidth Viewport width to set on the parent, with `$viewportHeight`; null leaves it.
	 * @param int|null $viewportHeight Viewport height to set on the parent.
	 * @return void Rebinds the parent framebuffer.
	 */
	public function unbind(?int $viewportWidth = null, ?int $viewportHeight = null): void
	{
		if(!$this->bound)
		{
			throw new \LogicException($this->label . ' is not bound');
		}

		$this->bound = false;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->parent);

		if($viewportWidth !== null && $viewportHeight !== null) glViewport(0, 0, $viewportWidth, $viewportHeight);
	}

	/**
	 * Change the target's size, recreating its objects; the contents are lost.
	 *
	 * @param int $width New positive width in pixels.
	 * @param int $height New positive height in pixels.
	 * @return bool False when the size is unchanged and nothing was done.
	 */
	public function resize(int $width, int $height): bool
	{
		if($width === $this->width && $height === $this->height) return false;

		if($this->closed || $this->bound)
		{
			throw new \LogicException($this->label . ' cannot be resized now');
		}

		if($width <= 0 || $height <= 0)
		{
			throw new \InvalidArgumentException($this->label . ' dimensions must be positive');
		}

		$this->release();
		$this->width = $width;
		$this->height = $height;
		$this->create();

		return true;
	}

	/**
	 * Rebuild the GL objects after browser context restoration.
	 *
	 * @return void Recreates the texture, depth buffer and framebuffer unless closed.
	 */
	public function restore(): void
	{
		if($this->closed) return;

		$this->bound = false;
		$this->parent = 0;
		$this->release();
		$this->create();
	}

	/**
	 * Release the GL objects for good.
	 *
	 * @return void Makes later binding and restoration no-ops or errors; repeated closure is harmless.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->bound = false;
		$this->parent = 0;
		$this->release();
	}

	/**
	 * Allocate the texture, framebuffer and depth buffer, leaving the caller's framebuffer bound afterwards.
	 *
	 * @return void Releases any partial allocation and rethrows when the framebuffer is incomplete.
	 */
	private function create(): void
	{
		$previous = glGetIntegerv(GL_FRAMEBUFFER_BINDING);

		try
		{
			$this->texture = Atlas::texture(GL_TEXTURE_2D);

			if($this->filter !== GL_NEAREST)
			{
				glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, $this->filter);
				glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, $this->filter);
			}

			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $this->width, $this->height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
			glGenFramebuffers(1, $this->framebuffer);
			glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
			glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->texture, 0);

			if($this->depth)
			{
				glGenRenderbuffers(1, $this->depthBuffer);
				glBindRenderbuffer(GL_RENDERBUFFER, $this->depthBuffer[0]);
				glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH_COMPONENT24, $this->width, $this->height);
				glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_RENDERBUFFER, $this->depthBuffer[0]);
			}

			if(glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE)
			{
				throw new \RuntimeException($this->label . ' framebuffer is incomplete');
			}

			if($this->depth) glBindRenderbuffer(GL_RENDERBUFFER, 0);

			glBindFramebuffer(GL_FRAMEBUFFER, $previous);
		}
		catch(\Throwable $error)
		{
			glBindFramebuffer(GL_FRAMEBUFFER, $previous);
			$this->release();

			throw $error;
		}
	}

	/**
	 * Delete whichever GL objects exist.
	 *
	 * @return void Leaves the names cleared and the size as it was.
	 */
	private function release(): void
	{
		if($this->texture) glDeleteTextures(1, [$this->texture]);

		if($this->framebuffer) glDeleteFramebuffers(1, $this->framebuffer);

		if($this->depthBuffer) glDeleteRenderbuffers(1, $this->depthBuffer);

		$this->texture = 0;
		$this->framebuffer = $this->depthBuffer = [];
	}
}
