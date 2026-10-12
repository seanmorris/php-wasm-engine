<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
final class SpriteRenderer
{
	private Program $program;
	private array $vao = [],$buffers = [];
	private bool $closed = false;
	public int $draws = 0;
	public function __construct(public Atlas $atlas)
	{
		$this->program = new Program(file_get_contents(dirname(__DIR__,2).'/shaders/sprites.vert'),file_get_contents(dirname(__DIR__,2).'/shaders/sprites.frag'));
		$this->create();
	}
	private function create(): void
	{
		glGenVertexArrays(1,$this->vao);
		glBindVertexArray($this->vao[0]);
		glGenBuffers(1,$this->buffers);
		glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[0]);
		for($i = 0;$i < 4;$i++)
		{ glVertexAttribPointer($i,4,GL_FLOAT,false,64,$i * 16);
		glEnableVertexAttribArray($i);
		glVertexAttribDivisor($i,1); }
		glBindVertexArray(0);
	}
	/** Input order is painter order; caller may sort by feet Y for an isometric scene. */
	public function render(array $sprites,Camera $camera,float $milliseconds = 0): void
	{
		$this->draws = 0;
		if(!$sprites)
		{ return; } $bytes = '';
		foreach($sprites as $sprite)
		{
			$gid = $this->atlas->map->animatedGid($sprite->gid,$milliseconds + $sprite->time);
			$rect = $this->atlas->rects[$gid] ?? throw new \OutOfBoundsException("Sprite GID $gid is missing");
			$bytes .= pack('f*',$sprite->x,$sprite->y,$rect[2] * $sprite->scaleX,$rect[3] * $sprite->scaleY,...$rect);
			$bytes .= pack('f*',$sprite->originX,$sprite->originY,$sprite->rotation,($sprite->flipX?1:0) | ($sprite->flipY?2:0),$sprite->tint[0],$sprite->tint[1],$sprite->tint[2],$sprite->tint[3] * $sprite->opacity);
		}
		$p = $this->program;
		$p->use();
		glBindVertexArray($this->vao[0]);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D,$this->atlas->texture);
		glUniform1i($p->location('atlas'),0);
		glUniform2f($p->location('camera'),$camera->x,$camera->y);
		glUniform2f($p->location('viewport'),$camera->width,$camera->height);
		glUniform1f($p->location('zoom'),$camera->zoom);
		glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER,strlen($bytes),$bytes,GL_STREAM_DRAW);
		glDrawArraysInstanced(GL_TRIANGLES,0,6,count($sprites));
		glBindVertexArray(0);
		$this->draws = 1;
	}
	/** The owner restores the shared atlas before restoring this sprite batch. */
	public function restore(): void
	{
		if($this->closed)
		{ return; }
		$this->release();
		$this->program->restore();
		$this->create();
	}
	public function close(): void
	{ if($this->closed)
	{ return; } $this->closed = true;
	$this->release();
	$this->program->close(); }
	private function release(): void
	{ if($this->buffers)
	{ glDeleteBuffers(1,$this->buffers); }
	if($this->vao)
	{ glDeleteVertexArrays(1,$this->vao); }
	$this->buffers = $this->vao = []; }
}
