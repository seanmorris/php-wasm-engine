<?php
declare(strict_types=1);
namespace Phonics\Render;

/** Instanced solid triangles for projected geometry and transient effects. */
final class TriangleRenderer
{
	private Program $program;
	private array $vao = [], $buffers = [];
	private bool $closed = false;
	private bool $bound = false;

	/**
	 * Create the screen-space triangle program and streaming instance buffer.
	 *
	 * @param bool $exclusive Keep the shader bound between draws when this renderer owns the pass.
	 * @return void Compiles the triangle program and allocates its vertex array and buffer.
	 */
	public function __construct(private bool $exclusive = false)
	{
		$this->program = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/triangles.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/triangles.frag')
		);

		$this->create();
	}

	/**
	 * Configure three points and one RGBA color per instance.
	 *
	 * @return void Allocates and describes the vertex array and instance buffer.
	 */
	private function create(): void
	{
		glGenVertexArrays(1, $this->vao);
		glGenBuffers(1, $this->buffers);
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);

		for($i = 0; $i < 3; $i++)
		{
			glVertexAttribPointer($i, 2, GL_FLOAT, false, 40, $i * 8);
			glEnableVertexAttribArray($i);
			glVertexAttribDivisor($i, 1);
		}

		glVertexAttribPointer(3, 4, GL_FLOAT, false, 40, 24);
		glEnableVertexAttribArray(3);
		glVertexAttribDivisor(3, 1);
		glBindVertexArray(0);
	}

	/**
	 * Draw solid triangles in supplied painter order.
	 *
	 * @param list<array{float,float,float,float,float,float,float,float,float,float}> $triangles Instance data:
	 * three screen points followed by RGBA.
	 * @param int $width Output viewport width in pixels.
	 * @param int $height Output viewport height in pixels.
	 * @return void Uploads and draws the supplied triangle instances.
	 */
	public function render(array $triangles, int $width, int $height): void
	{
		if(!$triangles || $this->closed) return;

		$bytes = '';

		foreach($triangles as $triangle) $bytes .= pack('f*', ...$triangle);

		if(!$this->exclusive || !$this->bound)
		{
			$this->program->use();
			$this->bound = true;
		}

		glUniform2f($this->program->location('viewport'), $width, $height);
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER, strlen($bytes), $bytes, GL_STREAM_DRAW);
		glDrawArraysInstanced(GL_TRIANGLES, 0, 3, count($triangles));
		glBindVertexArray(0);
	}

	/**
	 * Release current vertex array and buffer handles.
	 *
	 * @return void Deletes the GL objects and forces the program to be rebound on the next draw.
	 */
	private function release(): void
	{
		$this->bound = false;

		if($this->vao) glDeleteVertexArrays(count($this->vao), $this->vao);
		if($this->buffers) glDeleteBuffers(count($this->buffers), $this->buffers);

		$this->vao = $this->buffers = [];
	}

	/**
	 * Rebuild owned objects after WebGL context restoration.
	 *
	 * @return void Recreates the program, vertex array, and instance buffer unless closed.
	 */
	public function restore(): void
	{
		if($this->closed) return;

		$this->release();
		$this->program->restore();
		$this->create();
	}

	/**
	 * Dispose the renderer; repeated calls are harmless.
	 *
	 * @return void Releases the program, vertex array, and instance buffer.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->release();
		$this->program->close();
	}
}
