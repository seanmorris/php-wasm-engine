<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
use PhpWasmEngine\Tiled\
{TileMap,Chunk,Gid};

/** RGBA8UI texture-array slices hold tile IDs, one instance per visible chunk/layer. */
final class MapRenderer
{
	public Atlas $atlas;
	public array $stats = ['draws' => 0,'instances' => 0,'uploads' => 0];
	private Program $program;
	public ?ImageRenderer $images = null;
	private array $pages = [], $buffers = [], $vao = [],$gridRanges = [];
	private array $layout = [];
	private bool $closed = false;
	public function __construct(public TileMap $map)
	{
		$this->atlas = new Atlas($map);
		$this->program = new Program(file_get_contents(dirname(__DIR__,2).'/shaders/tiles.vert'),file_get_contents(dirname(__DIR__,2).'/shaders/tiles.frag'));
		$this->create();
	}
	private function create(): void
	{
		$map = $this->map;
		glGenVertexArrays(1,$this->vao);
		glBindVertexArray($this->vao[0]);
		glGenBuffers(2,$this->buffers);
		$cells = [];
		for($y = 0;$y < 32;$y++)
		{ for($x = 0;$x < 32;$x++)
		{ $cells[] = [$x,$y]; } }
		if($map->projection->orientation === 'isometric')
		{ usort($cells,fn($a,$b) => ($a[0] + $a[1]) <=> ($b[0] + $b[1]) ?: $b[0] <=> $a[0]); }
		else
		{
			$order = $map->data['renderorder'] ?? 'right-down';
			usort($cells,fn($a,$b) => (($order === 'right-up' || $order === 'left-up'?-1:1) * ($a[1] <=> $b[1])) ?: ((str_starts_with($order,'left')?-1:1) * ($a[0] <=> $b[0])));
		}
		$bytes = '';
		$vertex = 0;
		foreach($cells as [$x,$y])
		{
			$diagonal = $x + $y;
			if(!isset($this->gridRanges[$diagonal]))
			{ $this->gridRanges[$diagonal] = [$vertex,0]; } $this->gridRanges[$diagonal][1] += 6;
			foreach([[0,0],[1,0],[0,1],[0,1],[1,0],[1,1]] as [$u,$v])
			{ $bytes .= pack('f*',$x,$y,$u,$v);
			$vertex++; }
		}
		glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER,strlen($bytes),$bytes,GL_STATIC_DRAW);
		glVertexAttribPointer(0,4,GL_FLOAT,false,16,0);
		glEnableVertexAttribArray(0);
		glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[1]);
		for($i = 1;$i <= 3;$i++)
		{ glVertexAttribPointer($i,4,GL_FLOAT,false,48,($i - 1) * 16);
		glEnableVertexAttribArray($i);
		glVertexAttribDivisor($i,1); }
	}
	private function encoded(Chunk $chunk): string
	{
		$bytes = str_repeat("\0",32 * 32 * 4);
		for($y = 0;$y < $chunk->height;$y++)
		{ for($x = 0;$x < $chunk->width;$x++)
		{
			$gid = $chunk->gidAt($chunk->x + $x,$chunk->y + $y);
			$index = $this->atlas->indices[$gid->id] ?? 0;
			if($gid->id && !$index)
			{ throw new \UnexpectedValueException("Unknown tile GID {$gid->id}"); }
			$bytes = substr_replace($bytes,(new Gid($index,$gid->flags))->bytes(),($y * 32 + $x) * 4,4);
		} }
		return $bytes;
	}
	private function sync(): void
	{
		$entries = [];
		foreach($this->map->layers as $order => $layer)
		{ foreach($layer->chunks as $chunk)
		{ $entries[] = [$layer,$chunk,$order]; } }
		$layout = array_map(fn($entry) => [spl_object_id($entry[0]),spl_object_id($entry[1]),$entry[2]],$entries);
		if($layout !== $this->layout)
		{
			foreach($this->pages as $page)
			{ glDeleteTextures(1,[$page['texture']]); } $this->pages = [];
			$limit = min(256,glGetIntegerv(GL_MAX_ARRAY_TEXTURE_LAYERS));
			foreach(array_chunk($entries,$limit) as $group)
			{
				$texture = Atlas::texture(GL_TEXTURE_2D_ARRAY);
				glTexStorage3D(GL_TEXTURE_2D_ARRAY,1,GL_RGBA8UI,32,32,count($group));
				$this->pages[] = ['texture' => $texture,'entries' => $group,'revisions' => []];
			}
			$this->layout = $layout;
		}
		foreach($this->pages as &$page)
		{
			glBindTexture(GL_TEXTURE_2D_ARRAY,$page['texture']);
			foreach($page['entries'] as $slice => [$layer,$chunk])
			{ if(($page['revisions'][$slice] ?? -1) !== $chunk->revision)
			{
				glTexSubImage3D(GL_TEXTURE_2D_ARRAY,0,0,0,$slice,32,32,1,GL_RGBA_INTEGER,GL_UNSIGNED_BYTE,$this->encoded($chunk));
				$page['revisions'][$slice] = $chunk->revision;
				$this->stats['uploads']++;
			} }
		} unset($page);
	}
	private function visible($layer,Chunk $chunk,Camera $camera): bool
	{
		if(!$layer->visible || $layer->opacity <= 0)
		{ return false; }
		$points = [];
		foreach([[$chunk->x,$chunk->y],[$chunk->x + $chunk->width,$chunk->y],[$chunk->x,$chunk->y + $chunk->height],[$chunk->x + $chunk->width,$chunk->y + $chunk->height]] as [$x,$y])
		{ $points[] = $this->map->projection->project($x,$y); }
		$xs = array_column($points,0);
		$ys = array_column($points,1);
		[$cx,$cy] = Parallax::camera($layer,$this->map,$camera);
		$cx -= $this->map->x + $layer->offsetX;
		$cy -= $this->map->y + $layer->offsetY;
		// Atlas extent is conservative for oversized tiles and tileset offsets.
		$margin = max($this->atlas->width,$this->atlas->height);
		return max($xs) + $margin >= $cx && min($xs) - $margin <= $cx + $camera->width / $camera->zoom && max($ys) + $margin >= $cy && min($ys) - $margin <= $cy + $camera->height / $camera->zoom;
	}
	private function instance($layer,Chunk $chunk,int $slice): string { return pack('f*',$chunk->x,$chunk->y,$slice,$layer->opacity,$layer->offsetX,$layer->offsetY,$layer->parallaxX,$layer->parallaxY,...$layer->tint); }
	private function draw(int $texture,string $instances,int $first = 0,int $count = 6144): void
	{
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D_ARRAY,$texture);
		glBindBuffer(GL_ARRAY_BUFFER,$this->buffers[1]);
		glBufferData(GL_ARRAY_BUFFER,strlen($instances),$instances,GL_STREAM_DRAW);
		$n = intdiv(strlen($instances),48);
		glDrawArraysInstanced(GL_TRIANGLES,$first,$count,$n);
		$this->stats['draws']++;
		$this->stats['instances'] += $n;
	}
	/** Worlds can exclude anchored images outside the selected background stack. */
	public function render(Camera $camera,float $milliseconds = 0,int $startLayer = 0,?int $endLayer = null,bool $anchoredImages = true): void
	{
		$endLayer ??= count($this->map->layers);
		$this->stats = ['draws' => 0,'instances' => 0,'uploads' => 0];
		$this->sync();
		$this->atlas->animate($milliseconds);
		$start = $startLayer;
		$prepared = false;
		if($this->images !== null)
		{ $this->images->draws = $this->images->uploads = 0; }
		foreach($this->map->layers as $order => $layer)
		{
			if($order < $startLayer || $order >= $endLayer || $layer->type !== 'imagelayer' || !$layer->visible || $layer->opacity <= 0)
			{ continue; }
			if(!$anchoredImages && isset($layer->properties['from']))
			{ continue; }
			if(!$prepared)
			{
				$this->images ??= new ImageRenderer($this->map);
				$this->images->prepare($milliseconds);
				$prepared = true;
			}
			$this->renderTiles($camera,$start,$order);
			if($this->images->renderLayer($layer,$camera,$milliseconds))
			{ $this->stats['draws']++; }
			$start = $order + 1;
		}
		$this->renderTiles($camera,$start,$endLayer);
	}
	private function renderTiles(Camera $camera,int $start,int $end): void
	{
		if($start >= $end || !$this->pages)
		{ return; }
		$p = $this->program;
		$p->use();
		glBindVertexArray($this->vao[0]);
		glUniform1i($p->location('tileIds'),0);
		glUniform1i($p->location('metadata'),1);
		glUniform1i($p->location('atlas'),2);
		glActiveTexture(GL_TEXTURE1);
		glBindTexture(GL_TEXTURE_2D,$this->atlas->metadata);
		glActiveTexture(GL_TEXTURE2);
		glBindTexture(GL_TEXTURE_2D,$this->atlas->texture);
		glUniform2f($p->location('tileSize'),$this->map->projection->tileWidth,$this->map->projection->tileHeight);
		glUniform2f($p->location('mapOrigin'),$this->map->x,$this->map->y);
		glUniform2f($p->location('camera'),$camera->x,$camera->y);
		glUniform2f($p->location('viewport'),$camera->width,$camera->height);
		glUniform1f($p->location('zoom'),$camera->zoom);
		glUniform2f($p->location('parallaxOrigin'),$this->map->x + ($this->map->data['parallaxoriginx'] ?? 0),$this->map->y + ($this->map->data['parallaxoriginy'] ?? 0));
		$iso = $this->map->projection->orientation === 'isometric';
		glUniform1i($p->location('isometric'),$iso?1:0);
		glUniform1f($p->location('isoOrigin'),$this->map->projection->mapHeight * $this->map->projection->tileWidth / 2);
		if(!$iso)
		{
			foreach($this->pages as $page)
			{ $instances = '';
			foreach($page['entries'] as $slice => [$layer,$chunk,$order])
			{ if($order >= $start && $order < $end && $this->visible($layer,$chunk,$camera))
			{ $instances .= $this->instance($layer,$chunk,$slice); } } if($instances !== '')
			{ $this->draw($page['texture'],$instances); } }
		} else
		{
			// Merge chunk diagonals in world tile order so tall tiles at chunk seams
			// blend correctly. Matching slices still share an instanced draw.
			$commands = [];
			foreach($this->pages as $page)
			{ foreach($page['entries'] as $slice => [$layer,$chunk,$order])
			{ if($order >= $start && $order < $end && $this->visible($layer,$chunk,$camera))
			{
				foreach($this->gridRanges as $diagonal => [$first,$count])
				{
					if($diagonal > $chunk->width + $chunk->height - 2)
					{ continue; }
					$commands[] = [$order,$chunk->x + $chunk->y + $diagonal,-$chunk->x,$page['texture'],$first,$count,$this->instance($layer,$chunk,$slice)];
				}
			} } }
			usort($commands,fn($a,$b) => [$a[0],$a[1],$a[2]] <=> [$b[0],$b[1],$b[2]]);
			$key = null;
			$instances = '';
			foreach($commands as $command)
			{
				$next = [$command[3],$command[4],$command[5]];
				if($key !== null && $next !== $key)
				{
					$this->draw($key[0],$instances,$key[1],$key[2]);
					$instances = '';
				}
				$key = $next;
				$instances .= $command[6];
			}
			if($key !== null)
			{ $this->draw($key[0],$instances,$key[1],$key[2]); }
		}
		glBindVertexArray(0);
	}

	/** Restore GPU storage without replacing map, atlas or image renderer objects. */
	public function restore(): void
	{
		if($this->closed)
		{ return; }
		$this->release();
		$this->program->restore();
		$this->atlas->restore();
		$this->images?->restore();
		$this->create();
	}
	public function close(): void
	{
		if($this->closed)
		{ return; } $this->closed = true;
		$this->release();
		$this->program->close();
		$this->atlas->close();
		$this->images?->close();
	}
	private function release(): void
	{
		foreach($this->pages as $page)
		{ glDeleteTextures(1,[$page['texture']]); }
		if($this->buffers)
		{ glDeleteBuffers(count($this->buffers),$this->buffers); }
		if($this->vao)
		{ glDeleteVertexArrays(1,$this->vao); }
		$this->pages = $this->buffers = $this->vao = $this->gridRanges = $this->layout = [];
	}
}
