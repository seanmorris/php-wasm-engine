<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;
use Phonics\Render\RenderTarget;

/** Same-frame pigment, lighting normal, coating and receiver depth in one integer texel. */
final class SurfaceSampleBuffer
{
	private RenderTarget $target;
	private int $frame = 0;

	public function __construct(int $width, int $height)
	{
		glActiveTexture(GL_TEXTURE0 + 13);
		$this->target = new RenderTarget($width, $height, GL_NEAREST, true, 'Terrain surface samples');
		try {
			glBindTexture(GL_TEXTURE_2D, $this->target->texture());
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA32UI, $width, $height, 0, GL_RGBA_INTEGER, GL_UNSIGNED_INT, null);
			$this->target->bind();
			try {
				if(glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE)
					throw new \RuntimeException('Incomplete terrain surface framebuffer');
			} finally {
				$this->target->unbind();
			}
		} catch(\Throwable $error) {
			$this->target->close();
			throw $error;
		}
	}

	public function fits(int $width, int $height): bool
	{
		// Fresh WebGL storage contains zeros. Replace it before reusing a nonzero stamp.
		return $this->target->width === $width && $this->target->height === $height && $this->frame < 0x7fffffff;
	}

	public function begin(): void
	{
		++$this->frame;
		$this->target->bind();
		glClearDepth(1.);
		glDepthMask(true);
		glClear(GL_DEPTH_BUFFER_BIT);
	}

	public function end(int $width, int $height): void
	{
		$this->target->unbind($width, $height);
	}

	public function bindFrame(Program $program): void
	{
		glUniform1i($program->location('uSurfaceFrame'), $this->frame);
	}

	public function bind(Program $program, int $width, int $height): void
	{
		glActiveTexture(GL_TEXTURE0 + 13);
		glBindTexture(GL_TEXTURE_2D, $this->target->texture());
		glUniform1i($program->location('uSurfaceData'), 13);
		glUniform2f($program->location('uSurfacePixel'), 1 / $width, 1 / $height);
		$this->bindFrame($program);
	}

	public function close(): void
	{
		$this->target->close();
	}
}
