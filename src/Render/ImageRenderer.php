<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
use PhpWasmEngine\Tiled\
{
	Layer,TileMap
};

/** Repeats images in the fragment shader: one quad per layer, regardless of camera size. */
final class ImageRenderer
{
	private Program $program;
	private array $vao = [], $textures = [], $heights = [];
	private bool $closed = false;
	public int $draws = 0, $uploads = 0;

	/**
	 * Create a shader and vertex array for the map's repeating image layers.
	 *
	 * @param TileMap $map Map supplying image layers and their world placement.
	 * @return void Initializes lazy texture storage owned by this renderer.
	 */
	public function __construct(public TileMap $map)
	{
		$this->program = new Program(
			file_get_contents(dirname(__DIR__, 2).'/shaders/images.vert')
			, file_get_contents(dirname(__DIR__, 2).'/shaders/images.frag')
		);
		$this->create();
	}

	/**
	 * Allocate the vertex array used by shader-generated image quads.
	 *
	 * @return void Creates the required GPU vertex-array handle.
	 */
	private function create(): void
	{
		glGenVertexArrays(1, $this->vao);
	}

	/**
	 * Load and cache one image texture with its native dimensions.
	 *
	 * @param string $path Resolved image path accessible to SDL_image.
	 * @return array{0:int,1:int,2:int} GL texture handle, pixel width, and pixel height.
	 */
	private function texture(string $path): array
	{
		if(isset($this->textures[$path]))
		{
			return $this->textures[$path];
		}

		$source = IMG_Load($path);

		if(!$source)
		{
			throw new \RuntimeException('Cannot load '.$path.': '.SDL_GetError());
		}

		$surface = null;

		try
		{
			$width = $source->w;
			$height = $source->h;

			if(max($width, $height) > glGetIntegerv(GL_MAX_TEXTURE_SIZE))
			{
				throw new \RuntimeException('Background image exceeds GPU texture size');
			}

			$surface = new \SDL_Surface(0, $width, $height, 32, 255, 65280, 16711680, -16777216);
			SDL_SetSurfaceBlendMode($source, SDL_BLENDMODE_NONE);
			$destination = new \SDL_Rect(0, 0, $width, $height);

			if(SDL_UpperBlit($source, null, $surface, $destination) !== 0)
			{
				throw new \RuntimeException(SDL_GetError());
			}

			glActiveTexture(GL_TEXTURE0);
			$texture = Atlas::texture(GL_TEXTURE_2D);
			$this->textures[$path] = [$texture,$width,$height];

			glTexImage2D(
				GL_TEXTURE_2D
				, 0
				, GL_RGBA8
				, $width
				, $height
				, 0
				, GL_RGBA
				, GL_UNSIGNED_BYTE
				, $surface
			);
			$this->uploads++;
			return $this->textures[$path];
		}
		finally
		{
			if($surface)
			{
				SDL_FreeSurface($surface);
			}

			SDL_FreeSurface($source);
		}
	}

	/**
	 * Load visible image frames and measure anchored stacks before drawing layers.
	 *
	 * @param float $milliseconds Simulation time in milliseconds selecting animated image frames.
	 * @return void Resets frame statistics and updates layer dimensions and stack heights.
	 */
	public function prepare(float $milliseconds = 0): void
	{
		$this->draws = $this->uploads = 0;
		$this->heights = [];

		foreach($this->map->layers as $layer)
		{
			if($layer->type !== 'imagelayer')
			{
				continue;
			}

			$path = Parallax::image($layer, $milliseconds);

			if($path !== null && $layer->visible && $layer->opacity > 0)
			{
				[, $layer->imageWidth,$layer->imageHeight] = $this->texture($path);
			}

			if(isset($layer->properties['from']))
			{
				$from = $layer->properties['from'];

				$this->heights[$from] = max(
					$this->heights[$from] ?? 0
					, $layer->offsetY + $layer->imageHeight
				);
			}
		}
	}

	/**
	 * Draw one visible image layer with repetition, tint, and optional color-key transparency.
	 *
	 * @param Layer $layer Image layer previously included in prepare so dimensions and stack
	 * heights are known.
	 * @param Camera $camera Viewport and world-to-screen transform.
	 * @param float $milliseconds Simulation time in milliseconds for image animation and scrolling.
	 * @return bool True when a quad was submitted; false when no image is visible.
	 */
	public function renderLayer(Layer $layer, Camera $camera, float $milliseconds = 0): bool
	{
		$layout = Parallax::layout(
			$layer
			, $this->map
			, $camera
			, $milliseconds
			, $this->heights[$layer->properties['from'] ?? ''] ?? 0
		);
		$path = Parallax::image($layer, $milliseconds);

		if($layout === null || $path === null)
		{
			return false;
		}

		[$texture] = $this->texture($path);
		$p = $this->program;
		$p->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $texture);
		glUniform1i($p->location('image'), 0);
		glUniform2f($p->location('viewport'), $camera->width, $camera->height);
		glUniform4f($p->location('screenRect'), ...$layout['rect']);
		glUniform4f($p->location('textureRect'), ...$layout['uv']);

		glUniform4f(
			$p->location('tint')
			, $layer->tint[0]
			, $layer->tint[1]
			, $layer->tint[2]
			, $layer->tint[3] * $layer->opacity
		);
		glUniform4f($p->location('colorKey'), ...($layer->transparentColor ?? [0,0,0,0]));
		glUniform2i($p->location('repeatAxes'), $layer->repeatX?1:0, $layer->repeatY?1:0);
		glDrawArrays(GL_TRIANGLES, 0, 6);
		glBindVertexArray(0);
		$this->draws++;
		return true;
	}

	/**
	 * Prepare and draw every image layer in map order.
	 *
	 * @param Camera $camera Viewport and world-to-screen transform.
	 * @param float $milliseconds Simulation time in milliseconds for image animation and scrolling.
	 * @return void Updates draw/upload statistics while retaining cached image textures.
	 */
	public function render(Camera $camera, float $milliseconds = 0): void
	{
		$this->prepare($milliseconds);

		foreach($this->map->layers as $layer)
		{
			if($layer->type === 'imagelayer')
			{
				$this->renderLayer($layer, $camera, $milliseconds);
			}
		}
	}

	/**
	 * Rebuild shaders and vertex state, allowing image textures to reload lazily.
	 *
	 * @return void Clears stale texture handles unless the renderer has closed.
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
	 * Release all owned image textures, vertex arrays, and the shader program.
	 *
	 * @return void Permanently closes the renderer; repeated calls have no effect.
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
	 * Delete GPU image resources while retaining the map and shader wrapper.
	 *
	 * @return void Clears texture, vertex-array, and stack-height caches for reuse or teardown.
	 */
	private function release(): void
	{
		$ids = array_column($this->textures, 0);

		if($ids)
		{
			glDeleteTextures(count($ids), $ids);
		}

		if($this->vao)
		{
			glDeleteVertexArrays(1, $this->vao);
		}

		$this->textures = $this->vao = $this->heights = [];
	}
}
