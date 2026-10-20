<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;

/** Captures terrain and applies a one-dimensional Gaussian blur along camera travel. */
final class MotionBlurRenderer
{
	private Program $program;
	private array $vao = [], $framebuffer = [], $depthBuffer = [];
	private int $sceneTexture = 0, $targetFramebuffer = 0;
	private bool $closed = false, $capturing = false;
	public int $draws = 0;

	/**
	 * Allocate an offscreen scene target matching the drawing buffer.
	 *
	 * @param int $width Positive drawing-buffer width in pixels.
	 * @param int $height Positive drawing-buffer height in pixels.
	 * @return void Creates restorable color and depth resources for directional terrain blur.
	 */
	public function __construct(public int $width, public int $height)
	{
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
	 * Allocate the framebuffer, linearly sampled scene texture, depth buffer, and geometry.
	 *
	 * @return void Creates context-local resources or releases partial allocation on failure.
	 */
	private function create(): void
	{
		try
		{
			glGenVertexArrays(1, $this->vao);
			$this->sceneTexture = Atlas::texture(GL_TEXTURE_2D);
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $this->width, $this->height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);

			glGenFramebuffers(1, $this->framebuffer);
			glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
			glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->sceneTexture, 0);
			glGenRenderbuffers(1, $this->depthBuffer);
			glBindRenderbuffer(GL_RENDERBUFFER, $this->depthBuffer[0]);
			glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH_COMPONENT24, $this->width, $this->height);
			glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_RENDERBUFFER, $this->depthBuffer[0]);

			if(glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE)
			{
				throw new \RuntimeException('Motion blur framebuffer is incomplete');
			}

			glBindRenderbuffer(GL_RENDERBUFFER, 0);
			glBindFramebuffer(GL_FRAMEBUFFER, 0);
		}
		catch(\Throwable $error)
		{
			glBindFramebuffer(GL_FRAMEBUFFER, 0);
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

		$this->targetFramebuffer = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		$this->capturing = true;
		$this->draws = 0;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
		glViewport(0, 0, $this->width, $this->height);
		glClearColor(...$color);
		glClearDepth(1);
		glDepthMask(true);
		glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);
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
		glBindFramebuffer(GL_FRAMEBUFFER, $this->targetFramebuffer);
		glViewport(0, 0, $this->width, $this->height);
		$p = $this->program;
		$p->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->sceneTexture);
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
		$this->targetFramebuffer = 0;
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
		$this->targetFramebuffer = 0;
		$this->release();
		$this->program->close();
	}

	/**
	 * Delete live texture, depth, framebuffer, and geometry objects while retaining dimensions.
	 *
	 * @return void Clears every owned GL handle except the separately managed program wrapper.
	 */
	private function release(): void
	{
		if($this->sceneTexture)
		{
			glDeleteTextures(1, [$this->sceneTexture]);
		}

		if($this->framebuffer)
		{
			glDeleteFramebuffers(1, $this->framebuffer);
		}

		if($this->depthBuffer)
		{
			glDeleteRenderbuffers(1, $this->depthBuffer);
		}

		if($this->vao)
		{
			glDeleteVertexArrays(1, $this->vao);
		}

		$this->sceneTexture = 0;
		$this->framebuffer = $this->depthBuffer = $this->vao = [];
	}
}
