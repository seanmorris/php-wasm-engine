<?php
require dirname(__DIR__) . '/autoload.php';

use PhpWasmEngine\Render\Atlas;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\RectRenderer;
use Phonics\Render\RenderTarget;
use Phonics\Render\RenderTargetPair;

// RenderTarget owns the framebuffer, colour texture and depth buffer that post-process passes and offscreen scene
// targets used to allocate themselves. It must bind over whatever framebuffer the caller has, hand it back with
// the viewport the caller asks for, survive context restoration, and release everything exactly once.

$width = 48;
$height = 32;
$device = new Device($width, $height);
$rects = new RectRenderer;
$checks = [];

$pixel = /**
 * Read one pixel of the currently bound framebuffer.
 *
 * @param int $x Column from the left.
 * @param int $y Row from the bottom.
 * @return string Hex RGBA of the pixel.
 */
function(int $x, int $y): string {
	return bin2hex(glReadPixels($x, $y, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE));
};

// A parent framebuffer stands in for whatever a game has bound when it opens a target mid-frame.
glGenFramebuffers(1, $parentFramebuffer);
$parentTexture = Atlas::texture(GL_TEXTURE_2D);
glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $width, $height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
glBindFramebuffer(GL_FRAMEBUFFER, $parentFramebuffer[0]);
glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $parentTexture, 0);
$checks['parent framebuffer is complete'] = glCheckFramebufferStatus(GL_FRAMEBUFFER) === GL_FRAMEBUFFER_COMPLETE;
glViewport(0, 0, 7, 9);

// Creating a target while the parent is bound must leave the parent bound.
$target = new RenderTarget(16, 8, GL_LINEAR, true, 'Test target');
$checks['creation leaves the caller\'s framebuffer bound']
	= glGetIntegerv(GL_FRAMEBUFFER_BINDING) === $parentFramebuffer[0];
$checks['creation reports live objects'] = glIsTexture($target->texture()) && glIsFramebuffer($target->framebuffer())
	&& glIsRenderbuffer($target->depthRenderbuffer());
$flat = new RenderTarget(4, 4, GL_NEAREST, false);
$checks['a target without depth has no renderbuffer'] = $flat->depthRenderbuffer() === 0;
$flat->close();

$target->bind([1, 0, 0, 1]);
$checks['bind records the parent and the viewport covers the target'] = $target->bound()
	&& $target->parent() === $parentFramebuffer[0]
	&& glGetIntegerv(GL_FRAMEBUFFER_BINDING) === $target->framebuffer()
	&& glGetIntegerv(GL_VIEWPORT) === [0, 0, 16, 8];
$checks['bind clears to the given colour'] = $pixel(3, 3) === 'ff0000ff';
$rects->render([[0, 0, 16, 8, 0, 1, 0, 1]], 16, 8);
$checks['draws land in the target'] = $pixel(3, 3) === '00ff00ff';
$target->unbind(7, 9);
$checks['unbind returns to the parent with the requested viewport'] = !$target->bound()
	&& glGetIntegerv(GL_FRAMEBUFFER_BINDING) === $parentFramebuffer[0]
	&& glGetIntegerv(GL_VIEWPORT) === [0, 0, 7, 9];

$target->bind();
$checks['bind without a colour keeps the contents'] = $pixel(3, 3) === '00ff00ff';
$target->unbind();
$checks['unbind without a size leaves the target viewport in place'] = glGetIntegerv(GL_VIEWPORT) === [0, 0, 16, 8]
	&& glGetIntegerv(GL_FRAMEBUFFER_BINDING) === $parentFramebuffer[0];

try
{
	$target->bind();
	$target->bind();
	$checks['binding twice is refused'] = false;
}
catch(\LogicException $error)
{
	$checks['binding twice is refused'] = true;
}

try
{
	$target->resize(20, 20);
	$checks['resizing while bound is refused'] = false;
}
catch(\LogicException $error)
{
	$checks['resizing while bound is refused'] = true;
}

$target->unbind();
$before = [$target->texture(), $target->framebuffer(), $target->depthRenderbuffer()];
$checks['resize to the same size does nothing'] = !$target->resize(16, 8)
	&& [$target->texture(), $target->framebuffer(), $target->depthRenderbuffer()] === $before;
$checks['resize to a new size rebuilds'] = $target->resize(24, 12) && $target->width === 24 && $target->height === 12
	&& glIsTexture($target->texture()) && !glIsTexture($before[0]);
$target->bind([0, 0, 1, 1]);
$checks['the resized target clears at its new size'] = glGetIntegerv(GL_VIEWPORT) === [0, 0, 24, 12]
	&& $pixel(20, 10) === '0000ffff';
$target->unbind();

$live = [$target->texture(), $target->framebuffer(), $target->depthRenderbuffer()];
$target->restore();
$checks['restore rebuilds live objects and clears the binding state'] = !$target->bound() && $target->parent() === 0
	&& glIsTexture($target->texture()) && glIsFramebuffer($target->framebuffer())
	&& glIsRenderbuffer($target->depthRenderbuffer()) && !glIsFramebuffer($live[1]);
$target->bind([0, 1, 1, 1]);
$checks['a restored target still binds and clears'] = $pixel(1, 1) === '00ffffff';
$target->unbind();

$live = [$target->texture(), $target->framebuffer(), $target->depthRenderbuffer()];
$target->close();
$target->close();
$checks['close releases the objects, once'] = !glIsTexture($live[0]) && !glIsFramebuffer($live[1])
	&& !glIsRenderbuffer($live[2]) && $target->texture() === 0 && $target->framebuffer() === 0;
$target->restore();
$checks['restore after close does nothing'] = $target->texture() === 0;

$pair = new RenderTargetPair(8, 8, GL_NEAREST, true, 'Test pair');
$checks['a pair has two distinct live targets'] = $pair->texture(0) > 0 && $pair->texture(1) > 0
	&& $pair->texture(0) !== $pair->texture(1) && $pair->target(1)->framebuffer() !== $pair->target(0)->framebuffer();
$pair->target(1)->bind([0, 0, 1, 1]);
$pair->target(1)->unbind();
$copied = [];
$pair->resize(6, 6, function(int $slot, int $old, RenderTarget $target) use (&$copied): void {
	$copied[] = [$slot, $old > 0, $target->width, $target->bound()];
});
$checks['pair resize hands each slot its old texture and the live new target'] = $pair->width === 6
	&& $pair->target(0)->width === 6 && $copied === [[0, true, 6, false], [1, true, 6, false]];
$checks['pair resize to the same size does nothing'] = $pair->resize(6, 6) === false;
$pair->close();
$pair->close();
$checks['closing a pair releases both slots'] = $pair->texture(0) === 0 && $pair->texture(1) === 0;

try
{
	new RenderTarget(0, 4);
	$checks['zero dimensions are refused'] = false;
}
catch(\InvalidArgumentException $error)
{
	$checks['zero dimensions are refused'] = true;
}

glBindFramebuffer(GL_FRAMEBUFFER, 0);
$rects->close();
glDeleteTextures(1, [$parentTexture]);
glDeleteFramebuffers(1, $parentFramebuffer);
$error = glGetError();
$device->close();
echo json_encode(compact('checks', 'error'));
