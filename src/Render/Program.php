<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
final class Program
{
	public int $id;
	private array $locations = [];
	public function __construct(string $vertex,string $fragment)
	{
		$this->id = glCreateProgram();
		try
		{
			foreach([[GL_VERTEX_SHADER,$vertex],[GL_FRAGMENT_SHADER,$fragment]] as [$type,$source])
			{
				$shader = glCreateShader($type);
				try
				{ glShaderSource($shader,1,$source);
				glCompileShader($shader);
				if(!glGetShaderiv($shader,GL_COMPILE_STATUS))
				{ throw new \RuntimeException(glGetShaderInfoLog($shader)); } glAttachShader($this->id,$shader); }
				finally
				{ glDeleteShader($shader); }
			}
			glLinkProgram($this->id);
			if(!glGetProgramiv($this->id,GL_LINK_STATUS))
			{ throw new \RuntimeException(glGetProgramInfoLog($this->id)); }
		} catch(\Throwable $error)
		{ glDeleteProgram($this->id);
		throw $error; }
	}
	public function use(): void { glUseProgram($this->id); }
	public function location(string $name): int { return $this->locations[$name] ??= glGetUniformLocation($this->id,$name); }
	public function close(): void
	{ if($this->id)
	{ glDeleteProgram($this->id);
	$this->id = 0; } }
}
