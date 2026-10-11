<?php
declare(strict_types=1);
// Procedural demo art. No assets from the reference games are distributed.
function png(string $path,int $width,int $height,callable $pixel): void
{
	$chunk=static fn($type,$data)=> pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
	$data=''; for($y=0;$y<$height;$y++)
	{ $data.="\0"; for($x=0;$x<$width;$x++) $data.=pack('C*',...$pixel($x,$y)); }
	file_put_contents($path,"\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',$width,$height,8,6,0,0,0)).$chunk('IDAT',gzcompress($data)).$chunk('IEND',''));
}
function jsonAsset(string $name,array $data): void { file_put_contents(__DIR__.'/assets/'.$name,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n"); }
png(__DIR__.'/assets/tiles.png',288,32,static function($x,$y) {
	$id=intdiv($x,32); $x%=32;
	if($id<=2)
	{
		$top=$id===1?31-$x:($id===2?$x:0);
		if($y<$top) return [0,0,0,0];
		return $y<$top+5?[105,212,157,255]:(($x+$y)%7===0?[71,100,105,255]:[42,63,77,255]);
	}
	if($id===3) return $y<6?[244,195,107,255]:[0,0,0,0];
	if($id===4||$id===5) return hypot($x-16,$y-16)<($id===4?10:7)&&hypot($x-16,$y-16)>4?[255,211,107,255]:[0,0,0,0];
	if($x<6||$x>25||$y<3||$y>29) return [0,0,0,0];
	if($x>=18&&$x<=22&&$y>=8&&$y<=12) return [240,247,255,255];
	if($y>25) return [222,238,247,255];
	return [[86,167,245,255],[242,120,114,255],[246,189,83,255]][$id-6];
});
$solid=[['name'=> 'solid','type'=> 'bool','value'=> true]];
$tiles=['type'=> 'tileset','name'=> 'demo','tilewidth'=> 32,'tileheight'=> 32,'columns'=> 9,'tilecount'=> 9,'image'=> 'tiles.png','imagewidth'=> 288,'imageheight'=> 32
, 'tiles'=> [
	['id'=> 0,'properties'=> $solid]
	, ['id'=> 1,'objectgroup'=> ['objects'=> [['x'=> 0,'y'=> 0,'polygon'=> [['x'=> 0,'y'=> 32],['x'=> 32,'y'=> 0],['x'=> 32,'y'=> 32]]]]]]
	, ['id'=> 2,'objectgroup'=> ['objects'=> [['x'=> 0,'y'=> 0,'polygon'=> [['x'=> 0,'y'=> 0],['x'=> 32,'y'=> 32],['x'=> 0,'y'=> 32]]]]]]
	, ['id'=> 3,'properties'=> [['name'=> 'oneWay','value'=> true]],'objectgroup'=> ['objects'=> [['x'=> 0,'y'=> 0,'width'=> 32,'height'=> 6]]]]
	, ['id'=> 4,'animation'=> [['tileid'=> 4,'duration'=> 180],['tileid'=> 5,'duration'=> 180]]]
	, ['id'=> 6,'class'=> 'runner']
	, ['id'=> 7,'class'=> 'glider']
	, ['id'=> 8,'class'=> 'flyer']
]];
jsonAsset('tiles.tsj',$tiles);
$width=96; $height=20; $floor=array_fill(0,$width*$height,0); $items=$floor;
for($x=0;$x<$width;$x++)
{
	$top=14;
	if($x>=18&&$x<=23) $top=14-($x-18);
	if($x>=24&&$x<=34) $top=9;
	if($x>=35&&$x<=39) $top=9+$x-35;
	if($x>=54&&$x<=58) continue;
	for($y=$top;$y<$height;$y++) $floor[$y*$width+$x]=1;
	if($x>=18&&$x<=23) $floor[$top*$width+$x]=2;
	if($x>=35&&$x<=39) $floor[$top*$width+$x]=3;
	if($x%4===0) $items[($top-2)*$width+$x]=5;
}
for($x=8;$x<=12;$x++) $floor[10*$width+$x]=4;
for($y=6;$y<14;$y++) $floor[$y*$width+68]=1;
$base=['type'=> 'map','version'=> '1.10','orientation'=> 'orthogonal','renderorder'=> 'right-down','width'=> $width,'height'=> $height,'tilewidth'=> 32,'tileheight'=> 32,'infinite'=> false,'tilesets'=> [['firstgid'=> 1,'source'=> 'tiles.tsj']]];
$base['layers']=[['id'=> 1,'name'=> 'Terrain','type'=> 'tilelayer','width'=> $width,'height'=> $height,'data'=> $floor],['id'=> 2,'name'=> 'Markers','type'=> 'tilelayer','width'=> $width,'height'=> $height,'data'=> $items],['id'=> 3,'name'=> 'Spawns','type'=> 'objectgroup','objects'=> [['id'=> 1,'class'=> 'Player','x'=> 80,'y'=> 100]]]];
jsonAsset('platformer.tmj',$base);
$infinite=$base; $infinite['infinite']=true; $infinite['width']=$infinite['height']=0; $infinite['layers']=[];
foreach([-32,0,32] as $offset)
{
	$data=array_fill(0,32*20,0); for($y=14;$y<20;$y++) for($x=0;$x<32;$x++) $data[$y*32+$x]=1;
	$chunks[]=['x'=> $offset,'y'=> 0,'width'=> 32,'height'=> 20,'data'=> $data];
}
$infinite['layers']=[['id'=> 1,'name'=> 'Infinite terrain','type'=> 'tilelayer','chunks'=> $chunks]];
jsonAsset('infinite.tmj',$infinite);
png(__DIR__.'/assets/iso.png',128,64,static function($x,$y) {
	$id=intdiv($x,64); $x%=64;
	$dy=$y-($id?22:48);
	if(abs($x-32)/32+abs($dy)/16<=1) return $id?[159,159,235,255]:[104,200,173,255];
	if($id&&$y>=22&&$y<48+16-abs($x-32)/2) return $x<32?[71,83,139,255]:[91,104,166,255];
	return [0,0,0,0];
});
jsonAsset('iso.tsj',['type'=> 'tileset','tilewidth'=> 64,'tileheight'=> 64,'tilecount'=> 2,'columns'=> 2,'image'=> 'iso.png','imagewidth'=> 128,'imageheight'=> 64]);
$iso=['type'=> 'map','orientation'=> 'isometric','width'=> 12,'height'=> 12,'tilewidth'=> 64,'tileheight'=> 32,'tilesets'=> [['firstgid'=> 1,'source'=> 'iso.tsj']],'layers'=> [['id'=> 1,'name'=> 'Isometric','type'=> 'tilelayer','width'=> 12,'height'=> 12,'data'=> array_map(fn($i)=> ($i%13===0||$i===58||$i===59)?2:1,range(0,143))]]];
jsonAsset('isometric.tmj',$iso);
jsonAsset('demo.world',['type'=> 'world','maps'=> [['fileName'=> 'platformer.tmj','x'=> 0,'y'=> 0,'width'=> 3072,'height'=> 640],['fileName'=> 'infinite.tmj','x'=> 3072,'y'=> 0,'width'=> 3072,'height'=> 640]]]);
// Asymmetric four-quadrant image for orientation and flip regressions.
png(__DIR__.'/assets/test.png',16,16,static fn($x,$y)=> $y<8?($x<8?[255,0,0,255]:[0,255,0,255]):($x<8?[0,0,255,255]:[255,255,0,255]));
