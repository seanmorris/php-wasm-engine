<?php
declare(strict_types=1);
// Procedural demo art. No assets from the reference games are distributed.
function png(string $path,int $width,int $height,callable $pixel): void
{
	$chunk = static fn($type,$data) => pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
	$data = '';
	for($y = 0;$y < $height;$y++)
	{ $data .= "\0";
	for($x = 0;$x < $width;$x++)
	{ $data .= pack('C*',...$pixel($x,$y)); } }
	file_put_contents($path,"\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',$width,$height,8,6,0,0,0)).$chunk('IDAT',gzcompress($data)).$chunk('IEND',''));
}
function jsonAsset(string $name,array $data): void { file_put_contents(__DIR__.'/assets/'.$name,json_encode($data,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"); }
// Tiled's JSON importer reads omitted visibility and opacity as false and zero.
$layerDefaults = ['visible' => true,'opacity' => 1,'x' => 0,'y' => 0];
png(__DIR__.'/assets/tiles.png',288,32,static function($x,$y) {
	$id = intdiv($x,32);
	$x %= 32;
	if($id <= 2)
	{
		$top = $id === 1?31 - $x:($id === 2?$x:0);
		if($y < $top)
		{ return [0,0,0,0]; }
		return $y < $top + 5?[105,212,157,255]:(($x + $y) % 7 === 0?[71,100,105,255]:[42,63,77,255]);
	}
	if($id === 3)
	{ return $y < 6?[244,195,107,255]:[0,0,0,0]; }
	if($id === 4 || $id === 5)
	{ return hypot($x - 16,$y - 16) < ($id === 4?10:7) && hypot($x - 16,$y - 16) > 4?[255,211,107,255]:[0,0,0,0]; }
	if($x < 6 || $x > 25 || $y < 3 || $y > 29)
	{ return [0,0,0,0]; }
	if($x >= 18 && $x <= 22 && $y >= 8 && $y <= 12)
	{ return [240,247,255,255]; }
	if($y > 25)
	{ return [222,238,247,255]; }
	return [[86,167,245,255],[242,120,114,255],[246,189,83,255]][$id - 6];
});
$solid = [['name' => 'solid','type' => 'bool','value' => true]];
$tiles = ['type' => 'tileset','name' => 'demo','tilewidth' => 32,'tileheight' => 32,'columns' => 9,'tilecount' => 9,'image' => 'tiles.png','imagewidth' => 288,'imageheight' => 32 , 'tiles' => [ ['id' => 0,'properties' => $solid] , ['id' => 1,'objectgroup' => ['objects' => [['x' => 0,'y' => 0,'polygon' => [['x' => 0,'y' => 32],['x' => 32,'y' => 0],['x' => 32,'y' => 32]]]]]] , ['id' => 2,'objectgroup' => ['objects' => [['x' => 0,'y' => 0,'polygon' => [['x' => 0,'y' => 0],['x' => 32,'y' => 32],['x' => 0,'y' => 32]]]]]] , ['id' => 3,'properties' => [['name' => 'oneWay','value' => true]],'objectgroup' => ['objects' => [['x' => 0,'y' => 0,'width' => 32,'height' => 6]]]] , ['id' => 4,'animation' => [['tileid' => 4,'duration' => 180],['tileid' => 5,'duration' => 180]]] , ['id' => 6,'class' => 'runner'] , ['id' => 7,'class' => 'glider'] , ['id' => 8,'class' => 'flyer'] ] ];
jsonAsset('tiles.tsj',$tiles);
$width = 96;
$height = 20;
$floor = array_fill(0,$width * $height,0);
$items = $floor;
for($x = 0;$x < $width;$x++)
{
	$top = 14;
	if($x >= 18 && $x <= 23)
	{ $top = 14 - ($x - 18); }
	if($x >= 24 && $x <= 34)
	{ $top = 9; }
	if($x >= 35 && $x <= 39)
	{ $top = 9 + $x - 35; }
	if($x >= 54 && $x <= 58)
	{ continue; }
	for($y = $top;$y < $height;$y++)
	{ $floor[$y * $width + $x] = 1; }
	if($x >= 18 && $x <= 23)
	{ $floor[$top * $width + $x] = 2; }
	if($x >= 35 && $x <= 39)
	{ $floor[$top * $width + $x] = 3; }
	if($x % 4 === 0)
	{ $items[($top - 2) * $width + $x] = 5; }
}
for($x = 8;$x <= 12;$x++)
{ $floor[10 * $width + $x] = 4; }
for($y = 6;$y < 14;$y++)
{ $floor[$y * $width + 68] = 1; }
$base = ['type' => 'map','version' => '1.10','orientation' => 'orthogonal','renderorder' => 'right-down','width' => $width,'height' => $height,'tilewidth' => 32,'tileheight' => 32,'infinite' => false,'tilesets' => [['firstgid' => 1,'source' => 'tiles.tsj']]];
// Repeating coastal strips, drawn entirely by the PHP image renderer.
png(__DIR__.'/assets/sky.png',8,300,static fn($x,$y) => [15 + (int)($y / 18),30 + (int)($y / 10),52 + (int)($y / 7),255]);
png(__DIR__.'/assets/clouds.png',512,96,static function($x,$y) {
	foreach([[72,26,62,12],[110,19,29,17],[300,64,88,10],[348,55,42,15]] as [$cx,$cy,$rx,$ry])
	{ if((($x - $cx) / $rx) ** 2 + (($y - $cy) / $ry) ** 2 < 1)
	{ return [116,159,180,85]; } }
	return [0,0,0,0];
});
foreach(['far' => [180,48,78,101],'near' => [140,39,67,80]] as $name => [$h,$r,$g,$b])
{
	png(__DIR__.'/assets/mountains-'.$name.'.png',512,$h,static function($x,$y) use ($name,$h,$r,$g,$b) {
		$ridge = $name === 'far'?45 + 25 * sin($x * M_PI / 128) + 16 * cos($x * M_PI / 64):56 + 27 * cos($x * M_PI / 256) + 13 * sin($x * M_PI / 64);
		if($y < $ridge)
		{ return [0,0,0,0]; }
		$shade = (int)(12 * ($y - $ridge) / $h);
		return [$r - $shade,$g - $shade,$b - $shade,255];
	});
}
png(__DIR__.'/assets/water.png',128,256,static function($x,$y) {
	$shine = ($y % 13 === 0 && ($x + intdiv($y,13) * 19) % 64 < 28)?12:0;
	return [31 + $shine,69 + $shine - (int)($y / 24),89 + $shine - (int)($y / 16),255];
});
foreach([0,1] as $frame)
{ png(__DIR__.'/assets/shine-'.$frame.'.png',128,8,static fn($x,$y) => ($x + $frame * 16 + $y * 7) % 48 < 18 && $y % 3 === 0?[124,172,180,130]:[0,0,0,0]); }
$background = [];
$bands = [
	['sky',0,0,0,8,300]
	, ['clouds',36,.06,6,512,96]
	, ['mountains-far',128,.16,0,512,180]
	, ['water',284,.28,0,128,256]
	, ['mountains-near',196,.38,0,512,140]
	, ['shine-0',284,.28,18,128,8]
];
foreach($bands as $i => [$name,$offset,$parallax,$scroll,$w,$h])
{
	$properties = [['name' => 'from','type' => 'string','value' => 'bottom'],['name' => 'scrollX','type' => 'float','value' => $scroll]];
	if($name === 'shine-0')
	{ $properties[] = ['name' => 'frames','type' => 'string','value' => json_encode([['image' => 'shine-0.png','duration' => 180],['image' => 'shine-1.png','duration' => 180]])]; }
	$background[] = ['id' => 10 + $i,'name' => $name,'type' => 'imagelayer','image' => $name.'.png','imagewidth' => $w,'imageheight' => $h,'repeatx' => true,'parallaxx' => $parallax,'parallaxy' => 0,'offsety' => $offset,'properties' => $properties] + $layerDefaults;
}
$base['layers'] = [['id' => 1,'name' => 'Terrain','type' => 'tilelayer','width' => $width,'height' => $height,'data' => $floor] + $layerDefaults,['id' => 2,'name' => 'Markers','type' => 'tilelayer','width' => $width,'height' => $height,'data' => $items] + $layerDefaults,['id' => 3,'name' => 'Spawns','type' => 'objectgroup','objects' => [['id' => 1,'class' => 'Player','x' => 80,'y' => 100]]] + $layerDefaults];
$base['layers'] = array_merge($background,$base['layers']);
jsonAsset('platformer.tmj',$base);
$infinite = $base;
$infinite['infinite'] = true;
$infinite['width'] = $infinite['height'] = 0;
$infinite['layers'] = [];
foreach([-32,0,32] as $offset)
{
	$data = array_fill(0,32 * 20,0);
	for($y = 14;$y < 20;$y++)
	{ for($x = 0;$x < 32;$x++)
	{ $data[$y * 32 + $x] = 1; } }
	$chunks[] = ['x' => $offset,'y' => 0,'width' => 32,'height' => 20,'data' => $data];
}
$infinite['layers'] = [['id' => 1,'name' => 'Infinite terrain','type' => 'tilelayer','chunks' => $chunks] + $layerDefaults];
$infinite['layers'] = array_merge($background,$infinite['layers']);
jsonAsset('infinite.tmj',$infinite);
png(__DIR__.'/assets/iso.png',128,64,static function($x,$y) {
	$id = intdiv($x,64);
	$x %= 64;
	$dy = $y - ($id?22:48);
	if(abs($x - 32) / 32 + abs($dy) / 16 <= 1)
	{ return $id?[159,159,235,255]:[104,200,173,255]; }
	if($id && $y >= 22 && $y < 48 + 16 - abs($x - 32) / 2)
	{ return $x < 32?[71,83,139,255]:[91,104,166,255]; }
	return [0,0,0,0];
});
jsonAsset('iso.tsj',['type' => 'tileset','tilewidth' => 64,'tileheight' => 64,'tilecount' => 2,'columns' => 2,'image' => 'iso.png','imagewidth' => 128,'imageheight' => 64]);
$iso = ['type' => 'map','orientation' => 'isometric','width' => 12,'height' => 12,'tilewidth' => 64,'tileheight' => 32,'tilesets' => [['firstgid' => 1,'source' => 'iso.tsj']],'layers' => [['id' => 1,'name' => 'Isometric','type' => 'tilelayer','width' => 12,'height' => 12,'data' => array_map(fn($i) => ($i % 13 === 0 || $i === 58 || $i === 59)?2:1,range(0,143))] + $layerDefaults]];
$iso['layers'] = array_merge($background,$iso['layers']);
jsonAsset('isometric.tmj',$iso);
jsonAsset('demo.world',['type' => 'world','maps' => [['fileName' => 'platformer.tmj','x' => 0,'y' => 0,'width' => 3072,'height' => 640],['fileName' => 'infinite.tmj','x' => 4096,'y' => 0,'width' => 3072,'height' => 640,'bounds' => ['x' => -1024,'y' => 0,'width' => 3072,'height' => 640]]]]);
// Asymmetric four-quadrant image for orientation and flip regressions.
png(__DIR__.'/assets/test.png',16,16,static fn($x,$y) => $y < 8?($x < 8?[255,0,0,255]:[0,255,0,255]):($x < 8?[0,0,255,255]:[255,255,0,255]));
png(__DIR__.'/assets/test-parallax.png',7,5,static fn($x,$y) => $y < 2?($x < 3?[255,0,0,255]:[0,255,0,255]):($x < 3?[0,0,255,255]:[255,255,0,255]));

// Independently downloadable maps with shared tilesets and parallax images.
$worldMaps = [];
foreach(range(-2,2) as $section)
{
	$streamMap = $base;
	$streamMap['width'] = 32;
	$streamMap['layers'] = $background;
	$cells = array_fill(0,32 * 20,0);
	for($y = 13;$y < 20;$y++)
	{ for($x = 0;$x < 32;$x++)
	{ $cells[$y * 32 + $x] = 1; } }
	foreach(range(0,31,4) as $x)
	{ $cells[(9 + abs($section)) * 32 + $x] = 5; }
	$streamMap['layers'][] = ['id' => 1,'name' => 'Terrain','type' => 'tilelayer','width' => 32,'height' => 20,'data' => $cells] + $layerDefaults;
	$name = 'stream-'.($section + 2).'.tmj';
	jsonAsset($name,$streamMap);
	$worldMaps[] = ['fileName' => $name,'x' => $section * 1024,'y' => 0,'width' => 1024,'height' => 640];
}
jsonAsset('stream.world',['type' => 'world','maps' => $worldMaps]);
require __DIR__.'/generate-loop.php';
