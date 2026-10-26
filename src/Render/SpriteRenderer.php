<?php
declare(strict_types=1);
namespace Phonics\Render;

use Phonics\Ecs\Registry;

/** Batch sprites and layered ECS sprite components through a shared Atlas. */
final class SpriteRenderer
{
	private Program $program;
	private array $vao = [],$buffers = [];
	private bool $closed = false;
	/** Instanced draw calls submitted by the latest render operation. */
	public int $draws = 0;
	/** Sprite instances submitted by the latest render operation. */
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
			file_get_contents(dirname(__DIR__, 2) . '/shaders/sprites.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/sprites.frag')
		);

		$this->create();
	}

	/**
	 * Allocate the vertex array and per-instance buffer used by sprite draws.
	 *
	 * @return void Configures position, rectangle, transform, flags, tint, and depth attributes.
	 */
	private function create(): void
	{
		glGenVertexArrays(1, $this->vao);
		glBindVertexArray($this->vao[0]);
		glGenBuffers(1, $this->buffers);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);

		for($i = 0; $i < 5; $i++)
		{
			glVertexAttribPointer($i, 4, GL_FLOAT, false, 80, $i * 16);
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
		$groups = [];

		foreach($sprites as $sprite)
		{
			$groups[] = [$sprite];
		}

		$this->renderGroups($groups, $camera, $milliseconds);
	}

	/**
	 * Draw entity groups with artwork in front of one shared outline depth per group.
	 *
	 * Each group occupies a depth band nearer than the group before it. Artwork instances are
	 * emitted first in painter order; outlined sprites are then repeated as ink-only instances at
	 * the back of their own band. Existing sibling artwork consequently rejects their ink while the
	 * whole group still paints over earlier entities.
	 *
	 * @param list<list<Sprite>> $groups Entity sprite groups in painter order.
	 * @param Camera $camera Viewport and world-to-screen transform.
	 * @param float $milliseconds Simulation time used to select animation frames.
	 * @return void Submits one instanced batch, or no draw when every group is empty.
	 */
	private function renderGroups(array $groups, Camera $camera, float $milliseconds): void
	{
		$this->draws = 0;
		$this->instances = 0;
		$groups = array_values(array_filter($groups));

		if(!$groups)
		{
			return;
		}

		$slots = count($groups);

		foreach($groups as $group)
		{
			$slots += count($group);
			$this->instances += count($group);
		}

		$bytes = '';
		$gpuInstances = 0;
		$rank = 0;

		foreach($groups as $group)
		{
			$outlineDepth = $this->depth($rank++, $slots);
			$records = [];

			foreach($group as $sprite)
			{
				$gid = $sprite->frame($this->atlas->map, $milliseconds);
				$rect = $this->atlas->rects[$gid]
					?? throw new \OutOfBoundsException("Sprite GID $gid is missing");

				$records[] = [$sprite, $sprite->rectangle($rect)];
			}

			foreach($records as [$sprite, $rect])
			{
				$bytes .= $this->instance($sprite, $rect, $this->depth($rank++, $slots), false);
				$gpuInstances++;
			}

			foreach($records as [$sprite, $rect])
			{
				if(!$sprite->outline)
				{
					continue;
				}

				$bytes .= $this->instance($sprite, $rect, $outlineDepth, true);
				$gpuInstances++;
			}
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
		glClearDepth(1);
		glDepthMask(true);
		glClear(GL_DEPTH_BUFFER_BIT);
		glEnable(GL_DEPTH_TEST);
		glDepthFunc(GL_LESS);
		glDrawArraysInstanced(GL_TRIANGLES, 0, 6, $gpuInstances);
		glDisable(GL_DEPTH_TEST);
		glBindVertexArray(0);
		$this->draws = 1;
	}

	/**
	 * Pack one artwork or outline-only GPU instance.
	 *
	 * @param Sprite $sprite Mutable sprite pose and appearance.
	 * @param array{int,int,int,int} $rect Resolved atlas rectangle after an optional crop.
	 * @param float $depth Normalized depth, where smaller values paint nearer the viewer.
	 * @param bool $outlinePass Whether this instance emits only expanded black ink.
	 * @return string One eighty-byte, five-vector instance record.
	 */
	private function instance(Sprite $sprite, array $rect, float $depth, bool $outlinePass): string
	{
		$flags = ($sprite->flipX ? 1 : 0) | ($sprite->flipY ? 2 : 0)
			| ($outlinePass ? 4 : 0) | ($sprite->smooth ? 8 : 0);

		return pack('f*', ...[
			$sprite->x
			, $sprite->y
			, $rect[2] * $sprite->scaleX
			, $rect[3] * $sprite->scaleY
			, ...$rect
			, $sprite->originX
			, $sprite->originY
			, $sprite->rotation
			, $flags
			, $sprite->tint[0]
			, $sprite->tint[1]
			, $sprite->tint[2]
			, $sprite->tint[3] * $sprite->opacity
			, $depth
			, 0
			, 0
			, 0
		]);
	}

	/**
	 * Convert a far-to-near integer rank into a normalized depth clear of both clipping planes.
	 *
	 * @param int $rank Zero-based painter rank.
	 * @param int $slots Total outline and artwork ranks in this batch.
	 * @return float Depth in the open interval from zero to one.
	 */
	private function depth(int $rank, int $slots): float
	{
		return 1 - ($rank + 1) / ($slots + 1);
	}

	/**
	 * Draw single-sprite and multi-sprite entities in one shared-atlas batch.
	 *
	 * @param Registry $registry Registry containing Sprite, SpriteGroup, or SpriteLayers components.
	 * @param Camera $camera Viewport and world-to-screen transform.
	 * @param float $milliseconds Simulation time used to select animation frames.
	 * @param list<int>|null $entityIds Explicit painter order, or null for entity creation order.
	 * @return void Preserves entity boundaries and submits composite silhouettes in one draw.
	 */
	public function renderEntities(
		Registry $registry
		, Camera $camera
		, float $milliseconds = 0
		, ?array $entityIds = null
	): void {
		$this->renderGroups(SpriteGroup::collectGroups($registry, $entityIds), $camera, $milliseconds);
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
