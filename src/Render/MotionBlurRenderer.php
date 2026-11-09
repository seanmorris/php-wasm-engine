<?php
declare(strict_types=1);
namespace Phonics\Render;

/**
 * Captures terrain and applies a one-dimensional Gaussian blur along camera travel.
 *
 * The restorable pass renders selected scene content offscreen, then composites a bounded directional kernel
 * without changing simulation state.
 */
final class MotionBlurRenderer
{
	/** Restorable shader program owned by this renderer. */
	private Program $program;
	/** Restorable fullscreen vertex array. */
	private array $vao = [];
	/** Offscreen scene target the terrain is captured into, sampled linearly by the blur. */
	private RenderTarget $target;
	/** Whether the pass is closed and whether it is currently capturing a scene. */
	private bool $closed = false, $capturing = false;
	/** Composite draw calls submitted by the latest capture. */
	public int $draws = 0;

	/**
	 * Allocate an offscreen scene target matching the drawing buffer.
	 *
	 * @param int $width Positive drawing-buffer width in pixels.
	 * @param int $height Positive drawing-buffer height in pixels.
	 * @return void Creates restorable color and depth resources for directional terrain blur.
	 */
	public function __construct(
		/** Positive drawing-buffer width in pixels. */
		public int $width
		, /** Positive drawing-buffer height in pixels. */
		public int $height
	){
		if($width <= 0 || $height <= 0)
		{
			throw new \InvalidArgumentException('Motion blur target dimensions must be positive');
		}

		$this->program = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/motion-blur.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/motion-blur.frag')
		);

		$this->create();
	}

	/**
	 * The offscreen scene target the blur samples from.
	 *
	 * @return RenderTarget Linearly filtered colour with a depth buffer, sized to the drawing buffer.
	 */
	public function target(): RenderTarget { return $this->target; }

	/**
	 * Allocate the geometry and the linearly sampled scene target.
	 *
	 * @return void Creates context-local resources or releases partial allocation on failure.
	 */
	private function create(): void
	{
		try
		{
			glGenVertexArrays(1, $this->vao);
			$this->target = new RenderTarget($this->width, $this->height, GL_LINEAR, true, 'Motion blur');
		}
		catch(\Throwable $error)
		{
			$this->release();

			throw $error;
		}
	}

	/**
	 * Redirect terrain draws while retaining the caller's framebuffer target.
	 *
	 * @param array{0:float|int,1:float|int,2:float|int,3:float|int} $color Scene clear color as
	 * normalized RGBA channels.
	 * @return void Clears the capture color and depth buffers and begins one capture/composite pair.
	 */
	public function begin(array $color = [.055, .075, .12, 1]): void
	{
		if($this->closed || $this->capturing)
		{
			throw new \LogicException('Motion blur renderer cannot begin this capture');
		}

		$this->capturing = true;
		$this->draws = 0;
		$this->target->bind($color);
	}

	/**
	 * Restore the parent framebuffer and redraw captured terrain through a directional Gaussian.
	 *
	 * @param float $sigma Gaussian standard deviation in output pixels.
	 * @param float $directionX Horizontal component of a normalized screen-space direction.
	 * @param float $directionY Vertical component of a normalized screen-space direction.
	 * @return void Ends capture and submits one fullscreen composite draw.
	 */
	public function compose(float $sigma, float $directionX, float $directionY): void
	{
		if(!$this->capturing)
		{
			throw new \LogicException('Motion blur renderer must capture before composing');
		}

		foreach([$sigma, $directionX, $directionY] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Motion blur values must be finite');
			}
		}

		if($sigma < 0)
		{
			throw new \InvalidArgumentException('Motion blur sigma must be nonnegative');
		}

		$length = hypot($directionX, $directionY);

		if($sigma > 0 && abs($length - 1) > .001)
		{
			throw new \InvalidArgumentException('Active motion blur direction must be normalized');
		}

		$this->capturing = false;
		$this->target->unbind($this->width, $this->height);
		$p = $this->program;
		$p->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->target->texture());
		glUniform1i($p->location('scene'), 0);
		glUniform2f($p->location('viewport'), $this->width, $this->height);
		glUniform1f($p->location('sigma'), $sigma);
		glUniform2f($p->location('direction'), $directionX, $directionY);
		glDrawArrays(GL_TRIANGLES, 0, 6);
		glBindVertexArray(0);
		$this->draws = 1;
	}

	/**
	 * Rebuild all context-local resources after browser WebGL restoration.
	 *
	 * @return void Recreates framebuffer texture and shader state unless permanently closed.
	 */
	public function restore(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->capturing = false;
		$this->release();
		$this->program->restore();
		$this->create();
	}

	/**
	 * Permanently release framebuffer, texture, vertex array, and shader program.
	 *
	 * @return void Makes repeated closure harmless and prevents later capture or restoration.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->capturing = false;
		$this->release();
		$this->program->close();
	}

	/**
	 * Delete the scene target and geometry while retaining dimensions.
	 *
	 * @return void Clears every owned GL handle except the separately managed program wrapper.
	 */
	private function release(): void
	{
		if(isset($this->target)) $this->target->close();

		if($this->vao)
		{
			glDeleteVertexArrays(1, $this->vao);
		}

		$this->vao = [];
	}
}
