import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from '@playwright/test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const directory = path.join(root, 'site/static/images/games');
const selected = process.env.PHONICS_SCREENSHOT_GAME || '';

// Each hero window is 4:3 in source pixels. It keeps the play area and leaves out the
// embed's sound and fullscreen controls, debug text, and side panels.
const games = {
	'doom-phonics': {hero: [110, 0, 960, 720]}
	, 'php-noid': {hero: [224, 56, 544, 408]}
	, 'sane-phonics': {hero: [380, 150, 760, 570]}
	, 'php-tris': {hero: [250, 40, 700, 525]}
	, 'php-slalom': {hero: [86, 0, 516, 387]}
	, 'php-buster': {hero: [40, 0, 1032, 774]}
};

const executablePath = await fs.access('/usr/bin/chromium').then(() => '/usr/bin/chromium').catch(() => undefined);
const browser = await chromium.launch({headless: true, executablePath});
const page = await browser.newPage();

/**
 * Crop, scale, and encode one capture through the browser's WebP encoder.
 *
 * Flat pixel art often compresses better losslessly, so the smaller encoding wins.
 *
 * @param {string} source Base64 PNG capture.
 * @param {number[]} crop Source rectangle as x, y, width, and height.
 * @param {number} width Output width in pixels.
 * @param {number} quality WebP quality from 0 to 1.
 * @returns {Promise<Buffer>} Encoded WebP bytes.
 */
async function encode(source, crop, width, quality)
{
	const encoded = await page.evaluate(async({source, crop, width, quality}) => {
		const response = await fetch(`data:image/png;base64,${source}`);
		const bitmap = await createImageBitmap(await response.blob());
		const [x, y, cropWidth, cropHeight] = crop ?? [0, 0, bitmap.width, bitmap.height];
		const height = Math.round(width * cropHeight / cropWidth);
		const canvas = new OffscreenCanvas(width, height);
		const context = canvas.getContext('2d');

		context.imageSmoothingQuality = 'high';
		context.drawImage(bitmap, x, y, cropWidth, cropHeight, 0, 0, width, height);

		const encodings = await Promise.all(
			[quality, 1].map(level => canvas.convertToBlob({type: 'image/webp', quality: level}))
		);

		const blob = encodings.reduce((smallest, encoding) => encoding.size < smallest.size ? encoding : smallest);
		const bytes = new Uint8Array(await blob.arrayBuffer());
		let binary = '';

		for(let index = 0; index < bytes.length; index += 0x8000)
			binary += String.fromCharCode(...bytes.subarray(index, index + 0x8000));

		return btoa(binary);
	}, {source, crop, width, quality}
	);

	return Buffer.from(encoded, 'base64');
}

try
{
	await fs.mkdir(path.join(directory, 'hero'), {recursive: true});

	for(const [slug, {hero}] of Object.entries(games))
	{
		if(selected && slug !== selected) continue;

		const png = await fs.readFile(path.join(directory, `${slug}.png`));
		const width = png.readUInt32BE(16);
		const source = png.toString('base64');

		const outputs = [
			[`hero/${slug}.webp`, await encode(source, hero, 480, .82)]
			, [`${slug}-768.webp`, await encode(source, null, Math.min(768, width), .8)]
			, [`${slug}.webp`, await encode(source, null, Math.min(1280, width), .82)]
		];

		for(const [name, bytes] of outputs)
		{
			await fs.writeFile(path.join(directory, name), bytes);

			console.log(`Derived ${name} (${Math.round(bytes.length / 1024)} KiB)`);
		}
	}
}
finally
{
	await browser.close();
}
