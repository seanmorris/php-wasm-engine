<?php
declare(strict_types=1);
namespace Phonics\Render;

/** Restorable linked GLES shader program with cached uniform locations. */
final class Program
{
	/** Live GL program handle, replaced after context restoration and zero after closure. */
	public int $id = 0;
	private array $locations = [];
	private bool $closed = false;

	/**
	 * Compile and link a GLSL vertex/fragment pair in the current context.
	 *
	 * @param string $vertex Complete vertex-shader source.
	 * @param string $fragment Complete fragment-shader source.
	 * @return void Stores source strings so the program can be rebuilt after context loss.
	 */
	public function __construct(private string $vertex, private string $fragment)
	{
		$this->create();
	}

	/**
	 * Compile stored shader sources and link a new GL program.
	 *
	 * @return void Sets the live program ID or releases partial resources before throwing
	 * compilation/link errors.
	 */
	private function create(): void
	{
		$this->id = glCreateProgram();

		try
		{
			foreach([[GL_VERTEX_SHADER,$this->vertex],[GL_FRAGMENT_SHADER,$this->fragment]] as [
				$type,$source
			]){
				$shader = glCreateShader($type);

				try
				{
					glShaderSource($shader, 1, $source);
					glCompileShader($shader);

					if(!glGetShaderiv($shader, GL_COMPILE_STATUS))
					{
						throw new \RuntimeException(glGetShaderInfoLog($shader));
					}

					glAttachShader($this->id, $shader);
				}
				finally
				{
					glDeleteShader($shader);
				}
			}

			glLinkProgram($this->id);

			if(!glGetProgramiv($this->id, GL_LINK_STATUS))
			{
				throw new \RuntimeException(glGetProgramInfoLog($this->id));
			}
		}
		catch(\Throwable $error)
		{
			$this->release();
			throw $error;
		}
	}

	/**
	 * Rebuild the program and its uniform cache after context restoration.
	 *
	 * @return void Replaces GPU handles in place unless the wrapper has closed.
	 */
	public function restore(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->release();
		$this->create();
	}

	/**
	 * Bind this program for subsequent GL drawing and uniform updates.
	 *
	 * @return void Makes the live program current in the active context.
	 */
	public function use(): void { glUseProgram($this->id); }

	/**
	 * Look up and cache a uniform location for the current linked program.
	 *
	 * @param string $name Uniform name as declared by the shader.
	 * @return int GL uniform location, or -1 when the uniform is absent or optimized away.
	 */
	public function location(string $name): int
	{
		return $this->locations[$name] ??= glGetUniformLocation($this->id, $name);
	}

	/**
	 * Permanently close the program wrapper and release its GL handle.
	 *
	 * @return void Prevents later restoration and clears cached locations.
	 */
	public function close(): void
	{
		$this->closed = true;
		$this->release();
	}

	/**
	 * Delete the current program handle while preserving shader sources.
	 *
	 * @return void Clears the program ID and uniform locations for teardown or restoration.
	 */
	private function release(): void
	{
		if($this->id)
		{
			glDeleteProgram($this->id);
			$this->id = 0;
			$this->locations = [];
		}
	}
}
