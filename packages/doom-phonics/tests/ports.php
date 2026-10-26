<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Adapter\WadDefinitions;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\MapThing;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\SectorMover;
use DoomPhonics\Component\SpawnPoint;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\ProjectileImpact;
use DoomPhonics\Game\ProjectileTracer;
use DoomPhonics\Game\VerticalPhysics;
use DoomPhonics\Graphics\IndexedAtlas;
use DoomPhonics\Graphics\Palette;
use DoomPhonics\Graphics\PngDecoder;
use DoomPhonics\Graphics\TextureCatalog;
use DoomPhonics\Render\MapMeshBuilder;
use DoomPhonics\Tests\PortMap;
use Phonics\Ecs\Registry;

require __DIR__ . '/fixtures/PortMap.php';

$chunk = static fn(string $name, string $data): string => pack('N', strlen($data)) . $name . $data . hash('crc32b', $name . $data, true);
$grays = implode('', array_map(static fn(int $i): string => str_repeat(chr($i), 3), range(0,255)));
$portPalette = new Palette(new Stack(PortMap::archive(['PLAYPAL' => $grays])));
// Precomputed rows exercise None, Sub, Up, Average and Paeth, including left edges.
$filtered = pack('C*', 0,1,2,3,4, 1,5,1,1,1, 2,4,4,4,4, 3,9,3,3,3, 4,4,1,1,1);
$png = PngDecoder::SIGNATURE . $chunk('IHDR', pack('N2C5', 4,5,8,3,0,0,0))
	. $chunk('PLTE', $grays) . $chunk('tRNS', "\xff\0\x80") . $chunk('grAb', pack('N2', -7,12))
	. $chunk('IDAT', gzcompress($filtered)) . $chunk('IEND', '');
$image = PngDecoder::decode(PortMap::archive(['PNG' => $png])->lump('PNG'), $portPalette);
verifyWad($image->width === 4 && $image->height === 5 && $image->pixels === pack('C*', ...range(1,20)), 'PNG reconstructs all five row filters');
verifyWad($image->leftOffset === -7 && $image->topOffset === 12 && substr($image->alpha,0,3) === "\0\x80\xff", 'PNG preserves signed grAb and indexed transparency');
foreach([2,6] as $type)
{
	$raw = $type === 6 ? pack('C*', 0,40,40,40,0,99,99,99,128) : pack('C*', 0,40,40,40,99,99,99);
	$rgb = PngDecoder::SIGNATURE . $chunk('IHDR', pack('N2C5', 2,1,8,$type,0,0,0))
		. ($type === 2 ? $chunk('tRNS', pack('n3',40,40,40)) : '')
		. $chunk('IDAT', gzcompress($raw)) . $chunk('IEND','');
	$image = PngDecoder::decode(PortMap::archive(['RGB' => $rgb])->lump('RGB'), $portPalette);
	verifyWad($image->pixels === chr(40) . chr(99) && $image->alpha === ($type === 6 ? "\0\x80" : "\0\xff"), "PNG type $type maps RGB and alpha to PLAYPAL");
}
$badCrc = $png; $badCrc[29] = chr(ord($badCrc[29]) ^ 1);
foreach([$badCrc, substr($png,0,-4), str_replace(PngDecoder::SIGNATURE,'badmagic',$png)] as $badPng)
{
	try { PngDecoder::decode(PortMap::archive(['BROKEN' => $badPng])->lump('BROKEN'), $portPalette); verifyWad(false,'corrupt PNG must fail'); }
	catch(UnexpectedValueException $error) { verifyWad(str_contains($error->getMessage(),'BROKEN'), 'PNG rejection identifies its lump'); }
}

$portMap = PortMap::map();
verifyWad($portMap->format === 'HEXEN' && $portMap->sectorAt(32,64) === 0 && $portMap->sectorAt(160,64) === 1, 'Hexen binary records and BSP parse');
verifyWad($portMap->things[5]['flags']['single'] && !$portMap->things[5]['flags']['deathmatch'] && $portMap->things[8]['flags']['deathmatch'], 'Hexen game mode bits are distinct from Doom multiplayer-only');
$portMap->linedefs[2]['action'] = 210; $portMap->linedefs[2]['args'][0] = 2;
$portMap->linedefs[3]['action'] = 211; $portMap->linedefs[3]['args'][0] = 2;
$portLevel = new Level($portMap);
verifyWad($portLevel->sectors[0]->floorZ(0,64) === 0.0 && $portLevel->sectors[0]->floorZ(128,64) === 64.0 && $portLevel->sectors[0]->ceilingZ(64,64) === 160.0, 'Plane_Align anchors both slopes at the shared edge and far vertex');
verifyWad($portLevel->sectors[1]->floorLightSource === 0 && $portLevel->sectors[1]->ceilingLightSource === 0, 'Hexen floor and ceiling light transfers resolve sector tags');
$backMap = PortMap::map(); $backMap->linedefs[0]['args'] = [2,0,0,0,0];
$backLevel = new Level($backMap);
verifyWad($backLevel->sectors[1]->floorZ(128,64) === 0.0 && $backLevel->sectors[1]->floorZ(256,64) === 64.0 && $backLevel->sectors[0]->floorZ(64,64) === 0.0, 'Plane_Align honors back-side and unchanged-plane arguments');

$portFactory = new ActorFactory((new WadDefinitions)->actors);
$portRegistry = new Registry;
$populated = $portFactory->populate($portRegistry, $portMap, 2, false, null, 5080, $portLevel);
$players = iterator_to_array($portRegistry->query(PlayerStart::class));
verifyWad(!$populated['missing'] && count($players) === 1 && count($populated['entities']) === 2, 'inspection spawns one team player and only the eligible single-player pickup');
$spawnPose = $portRegistry->get($players[0], Transform::class);
verifyWad($spawnPose->x === 32.0 && $spawnPose->z === 16.0 && $portRegistry->get($players[0], SpawnPoint::class)->z === 16.0, 'actor and respawn origins sit on the sloped floor');
$coopRegistry = new Registry;
$portFactory->populate($coopRegistry, $portMap, 2, true);
$coopPickups = [];
foreach($coopRegistry->query(MapThing::class) as $entity)
if($coopRegistry->get($entity,MapThing::class)->type === 2010) $coopPickups[] = $coopRegistry->get($entity,Transform::class)->x;
verifyWad($coopPickups === [72.0], 'cooperative mode selects cooperative things at the chosen skill');

$portPose = new Transform(16,64,8);
$portBody = new Body(8,56,true);
$portVelocity = new Velocity;
$portCollision = new Collision($portLevel);
$portGravity = new VerticalPhysics($portLevel);
foreach([32,64,96,120,136,120,96,64,32,16] as $x)
{
	$portCollision->moveAndSlide($portPose,$portBody,$x - $portPose->x,0);
	$portGravity->step($portPose,$portVelocity,$portBody,1/35);
	verifyWad(abs($portPose->x - $x) < .01 && abs($portPose->z - min(64,$x*.5)) < .01 && $portBody->grounded, "movement follows the ramp and adjoining floor at x=$x");
}
$tracer = new ProjectileTracer($portLevel);
$impact = $tracer->trace(new Transform(16,64,32),new Body(2,8,false,false,true),100,0,0);
verifyWad($impact?->surface === ProjectileImpact::FLOOR && abs($impact->x - 64) < .01, 'horizontal projectile intersects a rising floor');
$impact = $tracer->trace(new Transform(112,64,168),new Body(2,8,false,false,true),-100,0,0);
verifyWad($impact?->surface === ProjectileImpact::CEILING && abs($impact->x - 96) < .01, 'horizontal projectile intersects a descending ceiling');
verifyWad(!$portLevel->portal(0)->admitsProjectile(60,8,.001,128,64) && $portLevel->portal(0)->admitsProjectile(70,8,.001,128,64), 'projectile portal tests use slope height at the crossing');

$doorMap = PortMap::map();
$doorMap->linedefs[0]['action'] = 12; $doorMap->linedefs[0]['flags'] = 1540;
$doorMap->linedefs[0]['args'] = [0,16,105,0,0]; $doorMap->sectors[1]['ceiling'] = 64;
$doorLevel = new Level($doorMap); $doorRegistry = new Registry;
$hexenDoors = new LineSpecials($doorLevel,$doorRegistry);
$portPlayer = new PlayerState;
verifyWad(!$hexenDoors->activate(0,'walk',0,$portPlayer) && $hexenDoors->activate(0,'use',0,$portPlayer), 'Hexen Door_Raise obeys use activation flags');
$mover = $doorRegistry->get($doorLevel->sectors[1]->ceilingMover,SectorMover::class);
verifyWad($mover->speed === 70.0 && $mover->waitTics === 105 && !$hexenDoors->consumed, 'Hexen door speed, delay and repeat flag are honored');
$hexenDoors->update(1/35);
verifyWad($doorLevel->sectors[1]->ceiling === 66.0, 'Hexen door moves in map units per Doom tic');

$liftMap = PortMap::map(); $liftMap->linedefs[0]['action'] = 206;
$liftMap->linedefs[0]['flags'] = 1028; $liftMap->linedefs[0]['args'] = [2,128,35,2,0];
$liftLevel = new Level($liftMap); $liftRegistry = new Registry;
$hexenLift = new LineSpecials($liftLevel,$liftRegistry);
verifyWad($hexenLift->activate(0,'use',0,$portPlayer), 'Hexen lift activates tagged sectors');
$mover = $liftRegistry->get($liftLevel->sectors[1]->floorMover,SectorMover::class);
verifyWad($mover->target === 2.0 && $mover->speed === 560.0 && $mover->waitTics === 35 && isset($hexenLift->consumed[0]), 'Hexen lift respects lip, speed, delay and one-shot flag');

$teleportMap = PortMap::map(); $teleportMap->linedefs[0]['action'] = 70;
$teleportMap->linedefs[0]['flags'] = 516; $teleportMap->linedefs[0]['args'] = [2,0,0,0,0];
$teleportMap->things = [['type'=>14,'id'=>1,'x'=>16,'y'=>64,'angle'=>0],['type'=>14,'id'=>2,'x'=>192,'y'=>64,'angle'=>90]];
$hexenTeleport = new LineSpecials(new Level($teleportMap));
$teleportPose = new Transform(16,64,0);
verifyWad(!$hexenTeleport->activate(0,'walk',0,$portPlayer,$teleportPose,false) && !$hexenTeleport->activate(0,'use',0,$portPlayer,$teleportPose), 'Hexen teleport rejects reverse crossing and Doom action-70 use semantics');
verifyWad($hexenTeleport->activate(0,'walk',0,$portPlayer,$teleportPose) && $teleportPose->x === 192.0 && $teleportPose->z === 64.0 && $teleportPose->previousX === 192.0, 'Hexen teleport selects TID and resets interpolation');

$scrollMap = PortMap::map(); $scrollMap->linedefs[0]['action'] = 101;
$scrollMap->linedefs[0]['args'] = [32,0,0,0,0]; $scrollMap->linedefs[2]['action'] = 80;
$scrollMap->linedefs[3]['action'] = 121;
$scrollLevel = new Level($scrollMap); $scrollSpecials = new LineSpecials($scrollLevel);
$scrollSpecials->update(1/35);
verifyWad($scrollLevel->wallScroll[0] === -.5 && $scrollLevel->unsupportedSpecials === [80=>1,121=>1], 'Hexen texture scrolling uses fractional tics and reports unimplemented ACS and line identification');
verifyWad(!$scrollSpecials->activate(2,'walk',0,$portPlayer), 'unsupported Hexen actions never fall through to Doom dispatch');

$portAtlas = IndexedAtlas::forLevel($portLevel, new TextureCatalog($stack));
$portMesh = (new MapMeshBuilder($portLevel,$portAtlas))->build();
verifyWad($portMesh->sync($portLevel,$portAtlas) === [], 'unchanged map material sync emits no vertex uploads');
$portLevel->setWallScroll(1, -.5);
verifyWad(count($portMesh->sync($portLevel,$portAtlas)) > 0 && $portMesh->sync($portLevel,$portAtlas) === [], 'only changed scroll offsets produce uploads');
unset($portLevel->wallScroll[1]);
verifyWad(count($portMesh->sync($portLevel,$portAtlas)) > 0 && $portMesh->sync($portLevel,$portAtlas) === [], 'removing a scroll offset restores original UVs once');
