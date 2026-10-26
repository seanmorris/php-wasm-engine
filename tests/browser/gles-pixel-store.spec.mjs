import {test, expect} from '@playwright/test';

async function fixture(page)
{
	await page.route('**/gles-pixel-store-fixture', route => route.fulfill({
		contentType: 'text/html'
		, body: '<canvas width="4" height="4"></canvas>'
	}));

	await page.goto('/gles-pixel-store-fixture');

	await page.evaluate(async() => {
		const {cachePixelStoreQueries} = await import('/engine/browser/GlesPixelStore.mjs');
		const canvas = document.querySelector('canvas');
		window.gl = canvas.getContext('webgl2');
		window.layoutQueries = 0;
		const getParameter = gl.getParameter.bind(gl);

		gl.getParameter = (...args) => {
			if(args[0] === gl.UNPACK_ROW_LENGTH) window.layoutQueries++;

			return getParameter(...args);
		};

		cachePixelStoreQueries(gl, canvas);
	});
}

test('cached pixel layouts preserve strided uploads, readbacks and invalid-write errors', async({page}) => {
	await fixture(page);

	const result = await page.evaluate(() => {
		const initial = [gl.getParameter(gl.UNPACK_ROW_LENGTH), gl.getParameter(gl.UNPACK_ROW_LENGTH)];
		const initialQueries = window.layoutQueries;
		gl.pixelStorei(gl.UNPACK_ROW_LENGTH, 4);
		gl.pixelStorei(gl.UNPACK_SKIP_PIXELS, 1);
		const rowLength = gl.getParameter(gl.UNPACK_ROW_LENGTH);
		gl.pixelStorei(gl.UNPACK_ROW_LENGTH, -1);
		const afterInvalid = gl.getParameter(gl.UNPACK_ROW_LENGTH);
		const invalidError = gl.getError();

		const texture = gl.createTexture();
		gl.bindTexture(gl.TEXTURE_2D, texture);
		const source = new Uint8Array(32);
		source.set([255, 0, 0, 255], 4);
		source.set([0, 255, 0, 255], 20);
		gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA8, 1, 2, 0, gl.RGBA, gl.UNSIGNED_BYTE, source);

		const framebuffer = gl.createFramebuffer();
		gl.bindFramebuffer(gl.FRAMEBUFFER, framebuffer);
		gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, texture, 0);
		gl.pixelStorei(gl.PACK_ROW_LENGTH, 3);
		gl.pixelStorei(gl.PACK_SKIP_PIXELS, 1);
		const pixels = new Uint8Array(24);
		gl.readPixels(0, 0, 1, 2, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
		const error = gl.getError();

		gl.deleteFramebuffer(framebuffer);
		gl.deleteTexture(texture);

		return {initial, initialQueries, rowLength, afterInvalid, invalidError, error, pixels: [...pixels]};
	});

	expect(result.initial).toEqual([0, 0]);
	expect(result.initialQueries).toBe(1);
	expect(result.rowLength).toBe(4);
	expect(result.afterInvalid).toBe(4);
	expect(result.invalidError).toBe(0x0501);
	expect(result.error).toBe(0);
	expect(result.pixels.slice(4, 8)).toEqual([255, 0, 0, 255]);
	expect(result.pixels.slice(16, 20)).toEqual([0, 255, 0, 255]);
});

test('restored contexts query the default layout instead of the previous cached value', async({page}) => {
	await fixture(page);

	const result = await page.evaluate(async() => {
		const canvas = document.querySelector('canvas');
		gl.pixelStorei(gl.UNPACK_ROW_LENGTH, 8);
		const before = gl.getParameter(gl.UNPACK_ROW_LENGTH);
		const extension = gl.getExtension('WEBGL_lose_context');

		const lost = new Promise(resolve => canvas.addEventListener('webglcontextlost', event => {
			event.preventDefault();
			resolve();
		}, {once: true}));

		extension.loseContext();
		const duringLoss = gl.getParameter(gl.UNPACK_ROW_LENGTH);
		await lost;
		const restored = new Promise(resolve => canvas.addEventListener('webglcontextrestored', resolve, {once: true}));
		// Restoration is allowed after the loss event has finished dispatching.
		setTimeout(() => extension.restoreContext(), 0);
		await restored;

		return {before, duringLoss, after: gl.getParameter(gl.UNPACK_ROW_LENGTH)};
	});

	expect(result).toEqual({before: 8, duringLoss: null, after: 0});
});
