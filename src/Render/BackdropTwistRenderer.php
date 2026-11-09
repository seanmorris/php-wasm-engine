<?php
declare(strict_types=1);
namespace Phonics\Render;

/**
 * Captures background draws and applies a localized radial twist before foreground sprites.
 *
 * The pass owns restorable offscreen resources and confines the post-process to a caller-controlled center,
 * radius, and strength.
 */
final class BackdropTwistRenderer
{
	/** Restorable shader program owned by this renderer. */
	private Program $program;
	/** Restorable fullscreen vertex array. */
	private array $vao = [];
	/** Offscreen scene target the background is captured into. */
	private RenderTarget $target;
	/** Whether the pass is closed and whether it is currently capturing a scene. */
	private bool $closed = false, $capturing = false;
	/** Composite draw calls submitted by the latest capture. */
	public int $draws = 0;

	/**
	 * Allocate a scene texture and framebuffer matching the drawing buffer.
	 *
	 * @param int $width Positive drawing-buffer width in pixels.
	 * @param int $height Positive drawing-buffer height in pixels.
	 * @return void Creates restorable color and depth resources for background displacement.
	 */
	public function __construct(
		/** Positive drawing-buffer width in pixels. */
		public int $width
		, /** Positive drawing-buffer height in pixels. */
		public int $height
	){
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
	 * The offscreen scene target the twist samples from.
	 *
	 * @return RenderTarget Nearest-filtered colour with a depth buffer, sized to the drawing buffer.
	 */
	public function target(): RenderTarget { return $this->target; }

	/**
	 * Allocate the shader-generated quad state and the scene target.
	 *
	 * @return void Creates context-local resources or releases partial allocation on failure.
	 */
	private function create(): void
	{
		try
		{
			glGenVertexArrays(1, $this->vao);
			$this->target = new RenderTarget($this->width, $this->height, GL_NEAREST, true, 'Backdrop twist');
		}
		catch(\Throwable $error)
		{
			$this->release();

			throw $error;
		}
	}

	/**
	 * Redirect subsequent background draws while retaining the caller's framebuffer target.
	 *
	 * @param array{0:float|int,1:float|int,2:float|int,3:float|int} $color Scene clear color as
	 * normalized RGBA channels.
	 * @return void Clears the capture color and depth buffers and begins one capture/composite pair.
	 */
	public function begin(array $color = [.055,.075,.12,1]): void
	{
		if($this->closed || $this->capturing)
		{
			throw new \LogicException('Backdrop twist renderer cannot begin this capture');
		}

		$this->capturing = true;
		$this->draws = 0;
		$this->target->bind($color);
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
		$this->target->unbind($this->width, $this->height);

		$p = $this->program;
		$p->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->target->texture());

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
