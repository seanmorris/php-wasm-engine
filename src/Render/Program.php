<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
final class Program
{
	public int $id = 0;
	private array $locations = [];
	private bool $closed = false;
	public function __construct(private string $vertex,private string $fragment)
	{ $this->create(); }
	private function create(): void
	{
		$this->id = glCreateProgram();
		try
		{
			foreach([[GL_VERTEX_SHADER,$this->vertex],[GL_FRAGMENT_SHADER,$this->fragment]] as [$type,$source])
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
		{ $this->release();
		throw $error; }
	}
	public function restore(): void
	{ if($this->closed)
	{ return; }
	$this->release();
	$this->create(); }
	public function use(): void { glUseProgram($this->id); }
	public function location(string $name): int { return $this->locations[$name] ??= glGetUniformLocation($this->id,$name); }
	public function close(): void
	{ $this->closed = true;
	$this->release(); }
	private function release(): void
	{ if($this->id)
	{ glDeleteProgram($this->id);
	$this->id = 0;
	$this->locations = []; } }
}
