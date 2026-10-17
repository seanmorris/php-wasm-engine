<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;

/** Captures background draws and applies a localized radial twist before foreground sprites. */
final class BackdropTwistRenderer
{
	private Program $program;
	private array $vao = [], $framebuffer = [];
	private int $sceneTexture = 0, $targetFramebuffer = 0;
	private bool $closed = false, $capturing = false;
	public int $draws = 0;

	/**
	 * Allocate a scene texture and framebuffer matching the drawing buffer.
	 *
	 * @param int $width Positive drawing-buffer width in pixels.
	 * @param int $height Positive drawing-buffer height in pixels.
	 * @return void Creates restorable resources for localized background displacement.
	 */
	public function __construct(public int $width, public int $height)
	{
		if($width <= 0 || $height <= 0)
		{
			throw new \InvalidArgumentException('Backdrop twist target dimensions must be positive');
		}

		$this->program = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/backdrop-twist.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/backdrop-twist.frag')
		);

		$this->create();
	}

	/**
	 * Allocate the framebuffer, scene texture, and shader-generated quad state.
	 *
	 * @return void Creates context-local resources or releases partial allocation on failure.
	 */
	private function create(): void
	{
		try
		{
			glGenVertexArrays(1, $this->vao);
			$this->sceneTexture = Atlas::texture(GL_TEXTURE_2D);
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $this->width, $this->height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);

			glGenFramebuffers(1, $this->framebuffer);
			glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
			glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->sceneTexture, 0);

			if(glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE)
			{
				throw new \RuntimeException('Backdrop twist framebuffer is incomplete');
			}

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
	 * Redirect subsequent background draws while retaining the caller's framebuffer target.
	 *
	 * @param array{0:float|int,1:float|int,2:float|int,3:float|int} $color Scene clear color as
	 * normalized RGBA channels.
	 * @return void Clears the capture texture and begins one capture/composite pair.
	 */
	public function begin(array $color = [.055,.075,.12,1]): void
	{
		if($this->closed || $this->capturing)
		{
			throw new \LogicException('Backdrop twist renderer cannot begin this capture');
		}

		$this->targetFramebuffer = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		$this->capturing = true;
		$this->draws = 0;
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
		glViewport(0, 0, $this->width, $this->height);
		glClearColor(...$color);
		glClear(GL_COLOR_BUFFER_BIT);
	}

	/**
	 * Restore the captured background and redraw one 64-world-pixel region through a twist field.
	 *
	 * @param Camera $camera Viewport and world-to-screen transform used by the background draw.
	 * @param float $worldX World X coordinate at the center of the displacement field.
	 * @param float $worldY World Y coordinate at the center of the displacement field.
	 * @param float $strength Signed displacement strength; changing sign reverses the field.
	 * @return void Ends capture, restores its parent framebuffer, and submits one or two draws.
	 */
	public function compose(Camera $camera, float $worldX, float $worldY, float $strength): void
	{
		if(!$this->capturing)
		{
			throw new \LogicException('Backdrop twist renderer must capture before composing');
		}

		foreach([$worldX, $worldY, $strength, $camera->x, $camera->y, $camera->zoom] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Backdrop twist values must be finite');
			}
		}

		if($camera->zoom <= 0)
		{
			throw new \InvalidArgumentException('Backdrop twist camera zoom must be positive');
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
		glUniform2f($p->location('viewport'), $camera->width, $camera->height);
		glUniform1f($p->location('zoom'), $camera->zoom);
		glUniform1i($p->location('twistEffect'), 0);
		glUniform4f($p->location('screenRect'), 0, 0, $camera->width, $camera->height);
		glDrawArrays(GL_TRIANGLES, 0, 6);
		$this->draws = 1;

		$size = 64 * $camera->zoom;
		$effectLeft = ($worldX - $camera->x) * $camera->zoom - $size / 2;
		$effectTop = ($worldY - $camera->y) * $camera->zoom - $size / 2;
		$left = max(0, $effectLeft);
		$top = max(0, $effectTop);
		$right = min($camera->width, $effectLeft + $size);
		$bottom = min($camera->height, $effectTop + $size);

		if($strength != 0 && $right > $left && $bottom > $top)
		{
			glUniform1i($p->location('twistEffect'), 1);
			glUniform1f($p->location('strength'), $strength);
			glUniform4f($p->location('effectRect'), $effectLeft, $effectTop, $size, $size);
			glUniform4f($p->location('screenRect'), $left, $top, $right - $left, $bottom - $top);
			glDrawArrays(GL_TRIANGLES, 0, 6);
			$this->draws++;
		}

		glBindVertexArray(0);
		glActiveTexture(GL_TEXTURE0);
	}

	/**
	 * Rebuild context-local resources after browser WebGL restoration.
	 *
	 * @return void Recreates the framebuffer texture and shader state unless permanently closed.
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
	 * Permanently release the framebuffer, texture, vertex array, and shader program.
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
	 * Delete live context objects while retaining dimensions and shader source for restoration.
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

		if($this->vao)
		{
			glDeleteVertexArrays(1, $this->vao);
		}

		$this->sceneTexture = 0;
		$this->framebuffer = $this->vao = [];
	}
}
