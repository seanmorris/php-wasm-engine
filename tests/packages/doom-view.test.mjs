import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mouseLookDelta} from '../../packages/doom-phonics/browser/game.mjs';

const source = path => readFile(new URL(`../../packages/doom-phonics/${path}`, import.meta.url), 'utf8');

test('Doom browser export loads from verified bytes without a package base URL', async() => {
	const manifest = JSON.parse(await source('phonics.json'));
	const code = await source(manifest.exports.game.browser);
	const game = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);

	assert.equal(typeof game.activate, 'function');
	assert.equal(typeof game.WadMusic, 'function');
});

test('pointer movement follows the canonical WAD view axes', () => {
	const right = mouseLookDelta(12, 0);
	const down = mouseLookDelta(0, 12);

	assert.ok(right.yaw < 0, 'moving right turns clockwise in WAD coordinates');
	assert.ok(down.pitch < 0, 'moving down lowers the view');
});

test('GLES maps, sprites, beams, portals, and skies share perspective and classic pitch modes', async() => {
	const [map, sprite, beam, portal, sky, skyOcclusion, renderer, raycaster] = await Promise.all([
		source('shaders/map.vert')
		, source('shaders/sprite.vert')
		, source('shaders/beam.vert')
		, source('shaders/camera-portal.vert')
		, source('shaders/sky.frag')
		, source('shaders/sky-occlusion.vert')
		, source('src/Render/GlesMapRenderer.php')
		, source('src/Render/Raycaster.php')
	]);

	for(const shader of [map, sprite, beam, portal, skyOcclusion])
	{
		assert.match(shader, /vec2 right = vec2\(forward\.y, -forward\.x\);/);
		assert.match(shader, /uniform float nearPlane;/);
		assert.match(shader, /uniform int pitchMode;/);
		assert.match(shader, /classicPitch \? horizontalDepth[\s\S]*horizontalDepth \* pitchCos \+ height \* pitchSin/);

		assert.match(
			shader
			, /classicPitch \? height - tan\(cameraPitch\) \* horizontalDepth[\s\S]*height \* pitchCos - horizontalDepth \* pitchSin/
		);

		assert.doesNotMatch(shader, /pitchOffset/);
	}

	assert.match(sky, /worldForward = pitchCos - viewUp \* pitchSin/);
	assert.match(sky, /worldUp = pitchSin \+ viewUp \* pitchCos/);
	assert.match(sky, /cameraAngle - atan\(viewRight, worldForward\)/);
	assert.match(sky, /yaw \* 4\.0 \/ 6\.28318530718/);
	assert.match(sky, /\.725 - elevation \/ halfVerticalFov \* \.425/);
	assert.match(sky, /\.725 - \(screenPosition\.y \+ tan\(cameraPitch\) \* projection\.y\) \* \.425/);
	assert.match(sky, /pitchMode == 1 \? classicV : perspectiveV/);
	assert.doesNotMatch(sky, /pitchOffset/);

	assert.match(renderer, /\$rightX = sin\(\$angle\);\s+\$rightY = -cos\(\$angle\);/);
	assert.equal(renderer.match(/location\('cameraPitch'\)/g)?.length, 8);
	assert.equal(renderer.match(/location\('nearPlane'\)/g)?.length, 7);
	assert.equal(renderer.match(/location\('pitchMode'\)/g)?.length, 8);
	assert.match(renderer, /private const NEAR_PLANE = 1\.0;/);
	assert.match(renderer, /CameraPortalMesh::LINE_NEAR_PLANE/);
	assert.match(renderer, /\$distance < self::NEAR_PLANE \? CameraPortalMesh::LINE_NEAR_PLANE : self::NEAR_PLANE/);
	assert.equal(raycaster.match(/\$angle - atan\(/g)?.length, 3);
});

test('camera textures and mirrors capture live views into dedicated surfaces', async() => {
	const [renderer, builder, cameraFragment, mirrorFragment, setup, mirror] = await Promise.all([
		source('src/Render/GlesMapRenderer.php')
		, source('src/Render/MapMeshBuilder.php')
		, source('shaders/camera-texture.frag')
		, source('shaders/mirror.frag')
		, source('src/Game/CameraTextureSetup.php')
		, source('src/Game/MirrorView.php')
	]);

	assert.match(setup, /SetCameraToTexture/);
	assert.match(builder, /cameraTextureBinding/);
	assert.match(builder, /mirrorForLine/);
	assert.match(renderer, /function renderCameraTextureViews\(/);
	assert.match(renderer, /function renderMirrorViews\(/);
	assert.match(renderer, /new ViewProjection\(\$definition->width, \$definition->height, \$binding->fov\)/);
	assert.match(renderer, /\$mirror->camera\(\$this->level, \$x, \$y, \$z, \$angle\)/);
	assert.match(cameraFragment, /cameraReady == 0/);
	assert.match(cameraFragment, /surfaceSide == 0 && !gl_FrontFacing/);
	assert.match(mirrorFragment, /gl_FragCoord\.xy \/ viewport/);
	assert.match(mirror, /'angle' => 2 \* \$lineAngle - \$angle/);
});

test('Doom viewport resizing uses a physical framebuffer with an optional half-scale scene', async() => {
	const [browser, device, pass, target, viewport] = await Promise.all([
		source('browser/game.mjs')
		, readFile(new URL('../../src/Render/Device.php', import.meta.url), 'utf8')
		, source('src/Render/DoomRenderPass.php')
		, source('src/Render/ScaledSceneTarget.php')
		, source('src/Render/RenderViewport.php')
	]);

	assert.match(browser, /new ResizeObserver\(measureViewport\)/);
	assert.match(browser, /cssWidth \* dpr/);
	assert.match(browser, /module\.doomViewport/);
	assert.match(browser, /MAX_RENDERBUFFER_SIZE/);
	assert.match(device, /public function resize\(int \$width, int \$height\): bool/);
	assert.match(device, /SDL_SetWindowSize/);

	assert.match(pass, /renderResolution\(\)->scale/);
	assert.match(pass, /new RenderViewport\(\$frame->device->width, \$frame->device->height, \$scale\)/);
	assert.match(target, /public function resize\(RenderViewport \$viewport\): bool/);
	assert.match(viewport, /sceneWidth/);
	assert.match(viewport, /sceneHeight/);
});

test('indexed rendering composes PLAYPAL flashes with fixed COLORMAP effects', async() => {
	const [map, sprite, sky, overlayVertex, overlay, renderer] = await Promise.all([
		source('shaders/map.frag')
		, source('shaders/sprite.frag')
		, source('shaders/sky.frag')
		, source('shaders/overlay.vert')
		, source('shaders/overlay.frag')
		, source('src/Render/GlesMapRenderer.php')
	]);

	for(const shader of [map, sprite, sky, overlay])
	{
		assert.match(shader, /uniform int paletteRows;/);
		assert.match(shader, /uniform int palettePage;/);
		assert.match(shader, /palettePage \* paletteRows \+ colorMap/);
	}

	for(const shader of [map, sprite, sky]) assert.match(shader, /fixedLightMap >= 0/);
	assert.match(overlayVertex, /aFixedColormap/);
	assert.match(overlay, /useFixedColormap > 0 && fixedLightMap >= 0/);
	assert.match(renderer, /textureRowCount\(\)/);
	assert.match(renderer, /intdiv\(count\(\$values\), 7\)/);
});

test('railgun beams follow portal-segmented world rays with depth-tested pixel-width ribbons', async() => {
	const [vertex, fragment, renderer, pass, combat] = await Promise.all([
		source('shaders/beam.vert')
		, source('shaders/beam.frag')
		, source('src/Render/GlesMapRenderer.php')
		, source('src/Render/DoomRenderPass.php')
		, source('src/System/CombatSystem.php')
	]);

	assert.match(vertex, /vec2 right = vec2\(forward\.y, -forward\.x\);/);
	assert.match(vertex, /aHalfWidth \* 2\.0 \/ viewport/);
	assert.match(fragment, /smoothstep\(\.72, 1\.0, abs\(across\)\)/);
	assert.match(renderer, /function renderBeams\(/);
	assert.match(renderer, /glEnable\(GL_DEPTH_TEST\);[\s\S]*glDepthMask\(false\);[\s\S]*glDrawArrays\(GL_TRIANGLES/);
	assert.match(pass, /renderActors[\s\S]*renderBeams[\s\S]*compose/);
	assert.match(combat, /function spawnBeamPath\(/);
	assert.match(combat, /new BeamEffect\([\s\S]*\$segment\['x'\] \+ \$segment\['dx'\][\s\S]*\$segment\['y'\] \+ \$segment\['dy'\][\s\S]*\$segment\['z'\] \+ \$segment\['dz'\]/);
});

test('camera portals recurse through destination spaces and occlude unresolved views', async() => {
	const [renderer, mesh, pass, mapData, vertex, fragment, skyOcclusion, portalFragment] = await Promise.all([
		source('src/Render/GlesMapRenderer.php')
		, source('src/Render/CameraPortalMesh.php')
		, source('src/Render/DoomRenderPass.php')
		, source('src/Map/MapData.php')
		, source('shaders/map.vert')
		, source('shaders/map.frag')
		, source('shaders/sky-occlusion.vert')
		, source('shaders/camera-portal.frag')
	]);
	const portalSurfaces = renderer.slice(
		renderer.indexOf('private function renderCameraPortalSurfaces(')
		, renderer.indexOf('/** Keep the usual depth precision')
	);

	assert.match(renderer, /function renderCameraPortalView\(/);
	assert.match(renderer, /intdiv\(\$this->cameraPortalViewBudget, \$linkCount\)/);
	assert.match(renderer, /intdiv\(\$availableChildViews, \$childCount\)/);
	assert.match(renderer, /\|\| \$branchBudget <= 0/);
	assert.match(renderer, /\$link->destinationSector/);
	assert.match(renderer, /\$link->reciprocalId/);
	assert.doesNotMatch(renderer, /isset\(\$path\[\$link->id\]\)/);
	assert.match(renderer, /\$connectedSectors = \$this->level->map->connectedSectors\(\$cameraSector\)/);
	assert.match(renderer, /\$link->destinationSector, \$nearPlane\s*\n\s*\);\s*\n\s*\$this->renderActors/);
	assert.match(portalSurfaces, /location\('portalReady'\), 0/);
	assert.doesNotMatch(portalSurfaces, /glColorMask\(false, false, false, false\)/);
	assert.match(renderer, /location\('cameraComponent'\)/);
	assert.match(pass, /\$cameraSector = \$this->renderer->level->map->sectorAt/);
	assert.equal(pass.match(/, \$cameraSector/g)?.length, 3);
	assert.match(mapData, /function sectorComponent\(int \$origin\): int/);
	assert.match(vertex, /flat out ivec2 sectorComponents;/);
	assert.match(fragment, /if\(sectorComponent != cameraComponent\) discard;/);
	assert.match(skyOcclusion, /if\(int\(round\(sectorData\.a\)\) != cameraComponent\)/);
	assert.match(portalFragment, /portalReady \? texture\(portalView, coordinate\) : vec4\(0\.0, 0\.0, 0\.0, 1\.0\)/);
	assert.match(mesh, /function spanInHorizontalView\(/);
	assert.match(mesh, /connectedSectors\(\$cameraSector\)/);
	assert.match(mesh, /public const LINE_NEAR_PLANE = \.01;/);
	assert.match(mesh, /\$surface->kind === CameraPortalSurface::LINE \? self::LINE_NEAR_PLANE : 1\.0/);
	assert.match(mesh, /ksort\(\$candidates, SORT_NUMERIC\)/);
	assert.doesNotMatch(mesh, /\$dx \* \$dx \+ \$dy \* \$dy/);
});

test('view weapons retain the full-screen psprite origin behind the status bar', async() => {
	const [renderer, placement] = await Promise.all([
		source('src/Render/GlesMapRenderer.php')
		, source('src/Render/ViewWeaponPlacement.php')
	]);

	assert.match(renderer, /ViewWeaponPlacement::fromImage\(\$image, \$offsetY\)/);
	assert.match(placement, /READY_ORIGIN_Y = 32\.0/);
	assert.match(placement, /self::READY_ORIGIN_Y - \$image->topOffset \+ \$loweringOffset/);
});

test('indexed menu overlays sample the final source row and column', async() => {
	const [renderer, fragment] = await Promise.all([
		source('src/Render/GlesMapRenderer.php')
		, source('shaders/overlay.frag')
	]);

	const helper = renderer.slice(
		renderer.indexOf('private function appendOverlayQuad(')
		, renderer.indexOf('/** Draw a translucent palette-black rectangle')
	);

	assert.match(helper, /\$sourceRight = \(float\)\$image->width;/);
	assert.match(helper, /\$sourceBottom = \(float\)\$image->height;/);
	assert.doesNotMatch(helper, /(?:width|height)\s*-\s*1/);
	assert.equal(renderer.match(/\$this->appendOverlayQuad\(/g)?.length, 4);
	assert.match(renderer, /closestIndex\(255, 128, 0\)/);
	assert.match(renderer, /\$reticle && \$health->current > 0/);
	assert.match(fragment, /ivec2\(floor\(textureUv\)\)/);
	assert.match(fragment, /clamp\([\s\S]*size - 1\)/);

	// With edge coordinates 0..size, each equally sized destination pixel reaches its
	// corresponding source texel, including the last one. A 0..size-1 range misses it.
	const sampled = Array.from({length: 16}, (_, x) => Math.floor((x + .5) / 16 * 16));
	assert.deepEqual(sampled, Array.from({length: 16}, (_, x) => x));
});
