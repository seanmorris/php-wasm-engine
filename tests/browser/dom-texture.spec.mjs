import {test,expect} from '@playwright/test';

test('DOM texture overlays retain the caller render target', async({page}) => {
	await page.goto('/preview/');

	const result = await page.evaluate(async() => {
		const {DomTexture} = await import('/engine/browser/DomTexture.mjs');
		const canvas = document.createElement('canvas');
		canvas.width = 320;
		canvas.height = 200;
		const gl = canvas.getContext('webgl2', {preserveDrawingBuffer: true});
		const root = new DomTexture(canvas);
		const modal = root.fork();
		const targetWidth = 160;
		const targetHeight = 100;

		const image = document.createElement('canvas');
		image.width = targetWidth;
		image.height = targetHeight;
		image.getContext('2d').fillStyle = '#00ff00';
		image.getContext('2d').fillRect(0, 0, image.width, image.height);

		const content = document.createElement('canvas');
		content.width = content.height = 1;
		content.getContext('2d').fillStyle = '#00ff00';
		content.getContext('2d').fillRect(0, 0, 1, 1);

		modal.opened = true;
		modal.dirty = false;
		modal.layout = modal.resolveLayout(targetWidth, targetHeight, 38);
		modal.overlayImage = image;
		modal.contentImage = content;
		modal.contentWidth = modal.contentHeight = modal.viewportHeight = 1;

		const texture = gl.createTexture();
		gl.bindTexture(gl.TEXTURE_2D, texture);

		gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA8, targetWidth, targetHeight, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);

		const framebuffer = gl.createFramebuffer();
		gl.bindFramebuffer(gl.FRAMEBUFFER, null);
		gl.clearColor(1, 0, 0, 1);
		gl.clear(gl.COLOR_BUFFER_BIT);
		gl.bindFramebuffer(gl.FRAMEBUFFER, framebuffer);

		gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, texture, 0);

		gl.clearColor(0, 0, 1, 1);
		gl.clear(gl.COLOR_BUFFER_BIT);

		const rendered = modal.render(targetWidth, targetHeight, 38);
		const retained = gl.getParameter(gl.DRAW_FRAMEBUFFER_BINDING) === framebuffer;
		const target = new Uint8Array(4);
		gl.readPixels(4, 4, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, target);
		gl.bindFramebuffer(gl.FRAMEBUFFER, null);
		const screen = new Uint8Array(4);
		gl.readPixels(4, 4, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, screen);
		root.dispose();
		const childDisposed = modal.disposed;
		gl.deleteFramebuffer(framebuffer);
		gl.deleteTexture(texture);

		return {rendered,retained,childDisposed,target: [...target],screen: [...screen]};
	});

	expect(result).toEqual({
		rendered: true
		, retained: true
		, childDisposed: true
		, target: [0,255,0,255]
		, screen: [255,0,0,255]
	});
});

test('DOM texture surfaces use every target pixel without modal chrome', async({page}) => {
	await page.goto('/preview/');

	const result = await page.evaluate(async() => {
		const {DomTexture} = await import('/engine/browser/DomTexture.mjs');
		const canvas = document.createElement('canvas');
		const texture = new DomTexture(canvas);
		texture.showSurface('<main>Edge to edge</main>');
		const layout = texture.resolveLayout(512, 256, 38);
		const state = JSON.parse(texture.state());
		texture.dispose();

		return {layout,state};
	});

	expect(result.layout).toMatchObject({
		width: 512
		, height: 256
		, margin: 0
		, panelX: 0
		, panelY: 0
		, panelWidth: 512
		, panelHeight: 256
		, headerHeight: 0
		, footerHeight: 0
		, contentX: 0
		, contentY: 0
		, contentWidth: 512
		, contentHeight: 256
	});
	expect(result.state).toMatchObject({open: true,surface: true});
});
