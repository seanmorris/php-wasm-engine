import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import http from 'node:http';
import {execFileSync} from 'node:child_process';
let server, directory, origin;

test.beforeAll(async () => {
	directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-cors-'));

	for(const name of ['motion','color'])
	{
		const source = path.resolve('tests/fixtures/composition', name);

		const packed = JSON.parse(
			execFileSync(
				'npm'
				, ['pack','--json','--ignore-scripts','--pack-destination',directory]
				, {cwd: source,encoding: 'utf8'}
			)
		)[0];

		await fs.mkdir(path.join(directory, name));

		execFileSync(
			'tar'
			, ['-xzf',path.join(directory, packed.filename),'-C',path.join(directory, name),'--strip-components=1']
		);
	}

	server = http.createServer(async (request, response) => {
		response.setHeader('Access-Control-Allow-Origin', '*');

		try
		{
			const file = path.join(directory, new URL(request.url, 'http://localhost').pathname);
			response.end(await fs.readFile(file));
		}
		catch
		{
			response.statusCode = 404;
			response.end('missing');
		}
	});

	await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
	origin = `http://127.0.0.1:${server.address().port}`;
	const world = {
		type: 'world'
		, maps: [{fileName: 'maps/start.tmj',x: 0,y: 0,width: 960,height: 540}]
		, properties: [
			{name: 'phonics.import.motion',type: 'string',value: origin + '/motion/phonics.json'}
			, {name: 'phonics.import.color',type: 'string',value: origin + '/color/phonics.json'}
			, {name: 'phonics.game',type: 'string',value: '@motion/game'}
			, {name: 'phonics.modules',type: 'string',value: '["@color/module"]'}
			, {name: 'phonics.start',type: 'string',value: 'maps/start.tmj'}
		]
	};

	const map = {
		type: 'map'
		, tilewidth: 16
		, tileheight: 16
		, width: 60
		, height: 34
		, layers: [{
			id: 1
			, type: 'objectgroup'
			, objects: [
				{
					id: 1
					, type: 'Fixture.Position'
					, x: 20
					, y: 30
					, properties: [{
						name: 'phonics.entity'
						, type: 'string'
						, value: origin + '/motion/phonics.json#position'
					}]
				}
			]
		}]
	};

	for(const relocation of ['world','relocated/nested'])
	{
		await fs.mkdir(path.join(directory, relocation, 'maps'), {recursive: true});
		await fs.writeFile(path.join(directory, relocation, 'new.world'), JSON.stringify(world));
		await fs.writeFile(path.join(directory, relocation, 'maps/start.tmj'), JSON.stringify(map));
	}
});

test.afterAll(
	async () => {
		await new Promise(resolve => server.close(resolve));
		await fs.rm(directory, {recursive: true,force: true});
	}
);

test(
	'unchanged host composes two packed modules from a second origin and survives world relocation'
	, async ({page}) => {
		await page.goto('/preview/');

		for(const location of ['world','relocated/nested'])
		{
			await page.evaluate(async ({url,origin}) => {
				window.composed = await Phonics.loadWorld(
					url
					, {canvas: document.querySelector('canvas'),policy: {codeOrigins: [window.location.origin,origin]}}
				);
			}, {url: origin + '/' + location + '/new.world',origin}
			);

			await expect.poll(
				async () => JSON.parse(await page.locator('canvas').getAttribute('data-composition') || '[0,0]')[0]
			).toBeGreaterThan(22);

			const coordinates = JSON.parse(await page.locator('canvas').getAttribute('data-composition'));
			expect(coordinates[1]).toBeGreaterThan(30);
			await page.evaluate(() => window.composed.dispose());
		}
	}
);

test('PhpNoid runs its complete campaign without downloading Sane-Phonics', async ({page}) => {
	const requests = [];
	page.on('request', request => requests.push(request.url()));
	await page.goto('/php-noid/');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await page.keyboard.press('Space');

	await expect.poll(
		async() => JSON.parse(await page.locator('canvas').getAttribute('data-php-noid')).frames
	).toBeGreaterThan(5);

	const campaignState = await page.locator('canvas').getAttribute('data-php-noid');
	const campaignLength = JSON.parse(campaignState).stageCount;

	for(let stage = 0; stage<campaignLength; stage++)
	{
		await page.evaluate(async() => {
			const php = previewSession.php;

			const code = await php.run(
				`<?php foreach($field->registry()->query(\\PhpNoid\\Component\\Brick::class) as $id) { $b=$field->registry()->get($id,\\PhpNoid\\Component\\Brick::class); if(!$b->unbreakable) $field->damage($id,$b->hits,true,'red'); }`
			);

			if(code) throw Error('Stage verification failed');
		});

		await expect.poll(async() => {
			const state = JSON.parse(await page.locator('canvas').getAttribute('data-php-noid'));

			return stage===campaignLength-1 ? state.phase : state.stage;
		}).toBe(stage===campaignLength-1 ? 'complete' : stage+1);
	}

	await page.keyboard.press('Space');

	const stageNumber = async () => {
		const data = await page.locator('canvas').getAttribute('data-php-noid');

		return JSON.parse(data).stage;
	};

	await expect.poll(stageNumber).toBe(0);

	expect(requests.filter(url => url.includes('sane-phonics'))).toEqual([]);
	await page.click('#stop');
	await expect(page.locator('#status')).toHaveText('Stopped');
});

test(
	'Sane-Phonics shares imported systems and factories on a canvas-only host and retires level entities'
	, async ({page}) => {
		await page.goto('/preview/');
		const host = new URL(page.url()).origin;
		const assets = host + '/engine/packages/sane-phonics/examples/assets/';
		const map = await (await page.request.get(assets + 'platformer.tmj')).json();

		const references = value => {
			if(!value || typeof value !== 'object') return;

			if(value.type === 'file' && value.value) value.value = new URL(value.value, assets).href;

			if(value.name === 'frames' && typeof value.value === 'string')
			{
				const frames = JSON.parse(value.value);
				references(frames);
				value.value = JSON.stringify(frames);
			}

			for(const [key, child] of Object.entries(value))
			{
				if(['image','source','template'].includes(key) && typeof child === 'string')
				value[key] = new URL(child, assets).href;
				else references(child);
			}
		};

		references(map);

		map.layers.push({
			id: 999
			, type: 'objectgroup'
			, objects: [
				{
					id: 9991
					, type: 'Fixture.Position'
					, x: 20
					, y: 30
					, properties: [
						{name: 'phonics.entity',type: 'string',value: '@motion/position'}
					]
				}
				, {
					id: 9992
					, type: 'SanePhonics.Player'
					, x: 200
					, y: 100
					, properties: [
						{name: 'phonics.entity',type: 'string',value: '@sane/player'}
					]
				}
			]
		});

		const world = {
			type: 'world'
			, maps: [{fileName: 'custom.tmj',x: 0,y: 0,width: 4096,height: 768}]
			, properties: [
				{name: 'phonics.import.sane',type: 'string',value: host + '/engine/packages/sane-phonics/phonics.json'}
				, {name: 'phonics.import.motion',type: 'string',value: origin + '/motion/phonics.json'}
				, {name: 'phonics.game',type: 'string',value: '@sane/game'}
				, {name: 'phonics.start',type: 'string',value: 'custom.tmj'}
				, {name: 'phonics.modules',type: 'string',value: '["@motion/module","@sane/platformer"]'}
				, {name: 'sane.scene',type: 'string',value: 'platformer'}
			]
		};

		await fs.writeFile(path.join(directory, 'custom.world'), JSON.stringify(world));
		await fs.writeFile(path.join(directory, 'custom.tmj'), JSON.stringify(map));
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));

		await page.evaluate(async origin => {
			const canvas = document.querySelector('canvas');
			document.body.replaceChildren(canvas);

			window.composed = await Phonics.loadWorld(origin + '/custom.world', {
				canvas
				, policy: {codeOrigins: [location.origin,origin]}
			});
		}, origin
		);

		const inspect = () => page.evaluate(async () => {
			let output = '';

			const read = event => { output += event.detail.join(''); };

			composed.php.addEventListener('output', read);

			try
			{
				await composed.php.run(
					`<?php $ids=array_values(array_filter($phonics->entities->query(\\Phonics\\Physics\\Pose::class),static fn($id)=>get_class($phonics->entities->get($id,\\Phonics\\Physics\\Pose::class))===\\Phonics\\Physics\\Pose::class)); echo json_encode(['shared'=>$engine->entities===$phonics->entities,'count'=>count($ids),'x'=>$ids ? $phonics->entities->get($ids[0],\\Phonics\\Physics\\Pose::class)->x : null]);`
				);

				return JSON.parse(output);
			}
			finally
			{
				composed.php.removeEventListener('output', read);
			}
		});

		await expect.poll(async () => (await inspect()).x).toBeGreaterThan(22);
		expect((await inspect()).shared).toBe(true);
		await page.evaluate(() => composed.php.run('<?php $game->selectScene("water");'));
		await expect.poll(async () => (await inspect()).count).toBe(0);
		await page.evaluate(() => composed.php.run('<?php $game->selectScene("platformer");'));
		await expect.poll(async () => (await inspect()).count).toBe(1);
		await page.evaluate(() => composed.dispose());
		expect(errors).toEqual([]);
	}
);

test('PhpNoid treats repeated map sources as separate campaign placements', async ({page}) => {
	await page.goto('/preview/');
	const host = new URL(page.url()).origin;
	const stage = host + '/engine/packages/php-noid/worlds/stage-01.tmj';
	const world = {
		type: 'world'
		, maps: [0,640].map(x => ({fileName: stage,x,y: 0,width: 640,height: 480}))
		, properties: [
			{
				name: 'phonics.import.php-noid'
				, type: 'string'
				, value: host + '/engine/packages/php-noid/phonics.json'
			}
			, {name: 'phonics.game',type: 'string',value: '@php-noid/game'}
			, {
				name: 'phonics.start'
				, type: 'string'
				, value: host + '/engine/packages/php-noid/phonics.json#firstStage'
			}
		]
	};

	await fs.writeFile(path.join(directory, 'repeated.world'), JSON.stringify(world));

	await page.evaluate(async origin => {
		window.composed = await Phonics.loadWorld(origin + '/repeated.world', {
			canvas: document.querySelector('canvas')
			, policy: {codeOrigins: [location.origin,origin]}
		});
	}, origin
	);

	const state = () => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.phpNoid));
	expect((await state()).stage).toBe(0);
	await page.locator('canvas').click();
	await page.keyboard.press('Space');
	await expect.poll(async () => (await state()).title).toBe(false);

	await page.evaluate(
		() => composed.php.run(
			`<?php foreach($field->registry()->query(\\PhpNoid\\Component\\Brick::class) as $id) { $brick=$field->registry()->get($id,\\PhpNoid\\Component\\Brick::class); if(!$brick->unbreakable) $field->damage($id,$brick->hits); }`
		)
	);

	await expect.poll(async () => (await state()).stage).toBe(1);
	await expect.poll(async () => (await state()).phase).toBe('playing');
	await page.evaluate(() => composed.dispose());
});
