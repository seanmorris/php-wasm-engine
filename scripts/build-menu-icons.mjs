import fs from 'node:fs/promises';
import {existsSync} from 'node:fs';
import {chromium} from '@playwright/test';

// Imported moving-boxes-2 artwork stays vector until the final texture resolution.
const root = new URL('../', import.meta.url);
const source = new URL('art/controllers/', root);
const pixelRatio = 4;
const columns = 12;
const tileWidth = 32 * pixelRatio;
const tileHeight = 20 * pixelRatio;
const entries = JSON.parse(await fs.readFile(new URL('manifest.json', source), 'utf8'));

const images = await Promise.all(entries.map(async item => ({
	...item, svg: await fs.readFile(new URL(item.file, source), 'utf8')
})));
const imageWidth = columns * tileWidth;
const imageHeight = Math.ceil(entries.length / columns) * tileHeight;

const browser = await chromium.launch({
	...(existsSync('/usr/bin/chromium') ? {executablePath: '/usr/bin/chromium'} : {}),
	args: ['--no-sandbox']
});

try
{
	const page = await browser.newPage();

	const data = await page.evaluate(async options => {
		const {images, pixelRatio, columns, tileWidth, tileHeight, imageWidth, imageHeight} = options;
		const canvas = document.createElement('canvas');
		canvas.width = imageWidth;
		canvas.height = imageHeight;
		const context = canvas.getContext('2d');

		for(const [index, item] of images.entries())
		{
			const svg = new DOMParser().parseFromString(item.svg, 'image/svg+xml').documentElement;
			const width = svg.viewBox.baseVal.width || parseFloat(svg.getAttribute('width'));
			const height = svg.viewBox.baseVal.height || parseFloat(svg.getAttribute('height'));
			const scale = Math.min(item.width / width, 16 / height);
			const w = width * scale * pixelRatio;
			const h = height * scale * pixelRatio;

			// Set the SVG viewport before decoding, avoiding an intrinsic-size bitmap upscale.
			svg.setAttribute('width', String(w));
			svg.setAttribute('height', String(h));
			const blob = new Blob([new XMLSerializer().serializeToString(svg)], {type: 'image/svg+xml'});
			const url = URL.createObjectURL(blob);

			try
			{
				const image = new Image();
				image.src = url;
				await image.decode();

				context.drawImage(
					image
					, index % columns * tileWidth + (item.width * pixelRatio - w) / 2
					, Math.floor(index / columns) * tileHeight + (16 * pixelRatio - h) / 2
					, w
					, h
				);
			}
			finally
			{
				URL.revokeObjectURL(url);
			}
		}

		return canvas.toDataURL('image/png').split(',')[1];
	}, {images, pixelRatio, columns, tileWidth, tileHeight, imageWidth, imageHeight});
	await fs.writeFile(new URL('examples/assets/buttons.png', root), Buffer.from(data, 'base64'));

	const prop = (name, value) => ({
		name
		, type: typeof value === 'number' ? 'int' : typeof value === 'boolean' ? 'bool' : 'string'
		, value
	});

	const tiles = entries.map((entry, id) => ({id
		, properties: [
			prop('glyph.char', entry.char), prop('glyph.theme', entry.theme)
			, prop('glyph.width', entry.width * pixelRatio)
			, prop('glyph.height', 16 * pixelRatio)
			, prop('glyph.pixelRatio', pixelRatio), prop('glyph.smooth', true)
			, prop('glyph.advance', entry.width + 2)
			, prop('glyph.offsetY', -2)
			, prop('glyph.tint', false)
		]
	}));
	const set = {
		type: 'tileset', version: '1.10', tiledversion: '1.10.1', name: 'buttons'
		, image: 'buttons.png', imagewidth: imageWidth, imageheight: imageHeight
		, tilewidth: tileWidth, tileheight: tileHeight, columns
		, tilecount: Math.ceil(tiles.length / columns) * columns
		, margin: 0
		, spacing: 0
		, tiles
	};
	await fs.writeFile(new URL('examples/assets/buttons.tsj', root), JSON.stringify(set, null, 2) + '\n');
	console.log(`Built ${tiles.length} annotated keyboard/controller glyphs at ${pixelRatio}x density.`);
}
finally
{
	await browser.close();
}
