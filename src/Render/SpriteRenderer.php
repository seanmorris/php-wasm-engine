<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;

use PhpWasmEngine\Ecs\Registry;

final class SpriteRenderer
{
	private Program $program;
	private array $vao = [],$buffers = [];
	private bool $closed = false;
	public int $draws = 0;
	public int $instances = 0;

	/**
	 * Create the sprite shader and instancing buffers while borrowing an existing atlas.
	 *
	 * @param Atlas $atlas Shared atlas containing every sprite GID supplied to render.
	 * @return void Allocates sprite GPU resources without taking ownership of atlas textures.
	 */
	public function __construct(public Atlas $atlas)
	{
		$this->program = new Program(
			file_get_contents(dirname(__DIR__, 2).'/shaders/sprites.vert')
			, file_get_contents(dirname(__DIR__, 2).'/shaders/sprites.frag')
		);
		$this->create();
	}

	/**
	 * Allocate the vertex array and per-instance buffer used by sprite draws.
	 *
	 * @return void Configures position, rectangle, transform, sprite flags, and tint attributes.
	 */
	private function create(): void
	{
		glGenVertexArrays(1, $this->vao);
		glBindVertexArray($this->vao[0]);
		glGenBuffers(1, $this->buffers);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);

		for($i = 0; $i < 4; $i++)
		{
			glVertexAttribPointer($i, 4, GL_FLOAT, false, 64, $i * 16);
			glEnableVertexAttribArray($i);
			glVertexAttribDivisor($i, 1);
		}

		glBindVertexArray(0);
	}

	/**
	 * Draw sprites in supplied painter order with independent animation offsets.
	 *
	 * @param list<Sprite> $sprites Sprites sharing this renderer's atlas; caller controls painter
	 * order.
	 * @param Camera $camera Viewport and world-to-screen transform.
	 * @param float $milliseconds Simulation time in milliseconds, added to each sprite's playback
	 * offset.
	 * @return void Submits one instanced batch, or no draw for an empty sprite list.
	 */
	public function render(array $sprites, Camera $camera, float $milliseconds = 0): void
	{
		$this->draws = 0;
		$this->instances = 0;

		if(!$sprites)
		{
			return;
		}

		$bytes = '';

		foreach($sprites as $sprite)
		{
			$gid = $sprite->frame($this->atlas->map, $milliseconds);

			$rect = $this->atlas->rects[$gid] ?? throw new \OutOfBoundsException(
				"Sprite GID $gid is missing"
			);
			$rect = $sprite->rectangle($rect);

			$bytes .= pack(
				'f*'
				, $sprite->x
				, $sprite->y
				, $rect[2] * $sprite->scaleX
				, $rect[3] * $sprite->scaleY
				, ...$rect
			);

			$bytes .= pack(
				'f*'
				, $sprite->originX
				, $sprite->originY
				, $sprite->rotation
				, ($sprite->flipX?1:0) | ($sprite->flipY?2:0) | ($sprite->outline?4:0)
					| ($sprite->smooth?8:0)
				, $sprite->tint[0]
				, $sprite->tint[1]
				, $sprite->tint[2]
				, $sprite->tint[3] * $sprite->opacity
			);
		}

		$p = $this->program;
		$p->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->atlas->texture);
		glUniform1i($p->location('atlas'), 0);
		glUniform2f($p->location('camera'), $camera->x, $camera->y);
		glUniform2f($p->location('viewport'), $camera->width, $camera->height);
		glUniform1f($p->location('zoom'), $camera->zoom);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER, strlen($bytes), $bytes, GL_STREAM_DRAW);
		glDrawArraysInstanced(GL_TRIANGLES, 0, 6, count($sprites));
		glBindVertexArray(0);
		$this->draws = 1;
		$this->instances = count($sprites);
	}

	/**
	 * Draw single-sprite and multi-sprite entities in one shared-atlas batch.
	 *
	 * @param Registry $registry Registry containing Sprite and SpriteGroup components.
	 * @param Camera $camera Viewport and world-to-screen transform.
	 * @param float $milliseconds Simulation time used to select animation frames.
	 * @param list<int>|null $entityIds Explicit painter order, or null for entity creation order.
	 * @return void Collects visible components and submits their sprites in one draw.
	 */
	public function renderEntities(Registry $registry, Camera $camera, float $milliseconds = 0,
		?array $entityIds = null
	): void
	{
		$this->render(SpriteGroup::collect($registry, $entityIds), $camera, $milliseconds);
	}

	/**
	 * Recreate sprite shaders and buffers after the owner restores the borrowed atlas.
	 *
	 * @return void Rebuilds GPU state unless the renderer has closed.
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
	 * Release owned sprite buffers and shaders without closing the shared atlas.
	 *
	 * @return void Permanently closes this renderer; repeated calls have no effect.
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

	/**
	 * Delete sprite buffer and vertex-array handles.
	 *
	 * @return void Clears owned handles while retaining the shared atlas reference.
	 */
	private function release(): void
	{
		if($this->buffers)
		{
			glDeleteBuffers(1, $this->buffers);
		}

		if($this->vao)
		{
			glDeleteVertexArrays(1, $this->vao);
		}

		$this->buffers = $this->vao = [];
	}
}
