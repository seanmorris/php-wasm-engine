<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;

/** Instanced solid rectangles for screen-space panels, sliders, and text selections. */
final class RectRenderer
{
	private Program $program;
	private array $vao = [], $buffers = [];
	private bool $closed = false;

	/**
	 * Create the screen-space rectangle program and buffers in the current GL context.
	 *
	 * @return void Allocates resources owned exclusively by this renderer.
	 */
	public function __construct()
	{
		$this->program = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/ui.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/ui.frag')
		);

		$this->create();
	}

	/**
	 * Configure one rectangle and color record per instance.
	 *
	 * @return void Creates the vertex array and streaming instance buffer.
	 */
	private function create(): void
	{
		glGenVertexArrays(1, $this->vao);
		glGenBuffers(1, $this->buffers);
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);

		for($i = 0; $i < 2; $i++)
		{
			glVertexAttribPointer($i, 4, GL_FLOAT, false, 32, $i * 16);
			glEnableVertexAttribArray($i);
			glVertexAttribDivisor($i, 1);
		}

		glBindVertexArray(0);
	}

	/**
	 * Draw solid UI shapes in supplied painter order.
	 *
	 * @param list<array{float,float,float,float,float,float,float,float}> $rectangles Shape batch.
	 * Screen rectangle XYWH and RGBA color records.
	 * @param int $width Output viewport width in pixels.
	 * @param int $height Output viewport height in pixels.
	 * @return void Submits one draw for a nonempty batch without modifying clipping state.
	 */
	public function render(array $rectangles, int $width, int $height): void
	{
		if(!$rectangles || $this->closed)
		{
			return;
		}

		$bytes = '';

		foreach($rectangles as $rectangle)
		{
			$bytes .= pack('f*', ...$rectangle);
		}

		$this->program->use();
		glUniform2f($this->program->location('viewport'), $width, $height);
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER, strlen($bytes), $bytes, GL_STREAM_DRAW);
		glDrawArraysInstanced(GL_TRIANGLES, 0, 6, count($rectangles));
		glBindVertexArray(0);
	}

	/**
	 * Release rectangle buffers while leaving the retained shader wrapper intact.
	 *
	 * @return void Deletes any current handles and clears the handle arrays.
	 */
	private function release(): void
	{
		if($this->vao)
		{
			glDeleteVertexArrays(count($this->vao), $this->vao);
		}

		if($this->buffers)
		{
			glDeleteBuffers(count($this->buffers), $this->buffers);
		}

		$this->vao = $this->buffers = [];
	}

	/**
	 * Rebuild owned objects after WebGL context restoration.
	 *
	 * @return void Recreates the program and buffers unless already closed.
	 */
	public function restore(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->release();
		$this->program->restore();
		$this->create();
	}

	/**
	 * Dispose the renderer without affecting other UI or world resources.
	 *
	 * @return void Releases resources once; repeated calls are harmless.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->release();
		$this->program->close();
	}
}
