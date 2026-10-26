import {test,expect} from '@playwright/test';

test('DOM texture overlays retain the caller render target', async({page}) => {
	await page.goto('/preview/');

	const result = await page.evaluate(async() => {
		const {DomTexture} = await import('/engine/browser/DomTexture.mjs');
		const canvas = document.createElement('canvas');
		canvas.width = 320;
		canvas.height = 200;
		const gl = canvas.getContext('webgl2', {preserveDrawingBuffer: true});
		const modal = new DomTexture(canvas);

		const image = document.createElement('canvas');
		image.width = 320;
		image.height = 200;
		image.getContext('2d').fillStyle = '#00ff00';
		image.getContext('2d').fillRect(0, 0, image.width, image.height);

		const content = document.createElement('canvas');
		content.width = content.height = 1;
		content.getContext('2d').fillStyle = '#00ff00';
		content.getContext('2d').fillRect(0, 0, 1, 1);

		modal.opened = true;
		modal.dirty = false;
		modal.layout = modal.resolveLayout(canvas.width, canvas.height, 38);
		modal.overlayImage = image;
		modal.contentImage = content;
		modal.contentWidth = modal.contentHeight = modal.viewportHeight = 1;

		const texture = gl.createTexture();
		gl.bindTexture(gl.TEXTURE_2D, texture);

		gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA8, canvas.width, canvas.height, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);

		const framebuffer = gl.createFramebuffer();
		gl.bindFramebuffer(gl.FRAMEBUFFER, null);
		gl.clearColor(1, 0, 0, 1);
		gl.clear(gl.COLOR_BUFFER_BIT);
		gl.bindFramebuffer(gl.FRAMEBUFFER, framebuffer);

		gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, texture, 0);

		gl.clearColor(0, 0, 1, 1);
		gl.clear(gl.COLOR_BUFFER_BIT);

		const rendered = modal.render(canvas.width, canvas.height, 38);
		const retained = gl.getParameter(gl.DRAW_FRAMEBUFFER_BINDING) === framebuffer;
		const target = new Uint8Array(4);
		gl.readPixels(4, 4, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, target);
		gl.bindFramebuffer(gl.FRAMEBUFFER, null);
		const screen = new Uint8Array(4);
		gl.readPixels(4, 4, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, screen);
		modal.dispose();
		gl.deleteFramebuffer(framebuffer);
		gl.deleteTexture(texture);

		return {rendered,retained,target: [...target],screen: [...screen]};
	});

	expect(result).toEqual({
		rendered: true
		, retained: true
		, target: [0,255,0,255]
		, screen: [255,0,0,255]
	});
});
