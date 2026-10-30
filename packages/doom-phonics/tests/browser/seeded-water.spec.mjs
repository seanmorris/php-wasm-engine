import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';

test('flooded portal water is textured from above and below at near and distant views', async({page}, testInfo) => {
	test.setTimeout(180000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });

	await page.goto('/engine/packages/doom-phonics/demo/seeded-navigation.html');
	const report = JSON.parse(await page.locator('#data').textContent());
	await page.locator('#play').click();
	await page.locator('.game-embed-poster').click();
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await expect.poll(() => page.evaluate(() => previewSession.doomState?.menu?.screen)).toBe('title');

	await page.locator('canvas').focus();
	await page.keyboard.press('Enter');
	await expect.poll(() => page.evaluate(() => previewSession.doomState.menu.screen)).toBe('skill');
	await page.keyboard.press('Enter');
	await expect.poll(() => page.evaluate(() => previewSession.doomState.menu.open)).toBe(false);
	await expect.poll(() => page.evaluate(() => previewSession.doomState.wipe.active), {timeout: 30000}).toBe(false);

	const run = source => page.evaluate(async source => {
		let result;
		const runtime = await previewSession.php.binary;

		runtime.waterProbe = json => { result = JSON.parse(String(json)); };

		const code = await previewSession.php.run('<?php ' + source);

		if(code !== 0) throw new Error(`Water probe exited with ${code}`);

		return result;
	}, source
	);

	await run(String.raw`
		$engine->userPaused=true; $engine->setInputHandler(static fn()=>true);
		$content=(new ReflectionProperty($game,'lease'))->getValue($game)->context->content();
		$level=$content->level; $player=$engine->entities->query(DoomPhonics\Game\PlayerState::class)[0];
		$pose=$engine->entities->get($player,DoomPhonics\Component\Transform::class);
		$view=$engine->entities->get($player,DoomPhonics\Component\ViewInput::class);
		$engine->entities->get($player,DoomPhonics\Component\Body::class)->grounded=false;
		$mesh=(new ReflectionProperty($content->renderer,'mesh'))->getValue($content->renderer);
	`
	);

	for(const name of ['LayeredWalkthrough','SeededWalkthrough'])
	{
		const source = await fs.readFile(new URL(`../fixtures/${name}.php`, import.meta.url), 'utf8');
		await run(source.replace(/^<\?php\s*/, '').replace(/^require_once .*;$/m, ''));
	}

	const encoded = Buffer.from(JSON.stringify({...report,regions: report.sectorIds})).toString('base64');
	await run(`$r=json_decode(base64_decode('${encoded}'),true);`);

	const capture = async name => {
		await page.evaluate(async() => { for(let i = 0; i < 8; i++) await new Promise(requestAnimationFrame); });

		return page.locator('canvas').screenshot({path: testInfo.outputPath(name)});
	};

	const difference = (a, b) => page.evaluate(async images => {
		const pixels = [];

		for(const data of images)
		{
			const image = await createImageBitmap(
				new Blob([Uint8Array.from(atob(data), c => c.charCodeAt(0))], {type: 'image/png'})
			);

			const canvas = new OffscreenCanvas(image.width, image.height);
			const context = canvas.getContext('2d');
			context.drawImage(image, 0, 0);

			// Exclude the HUD and weapon. Only the central view of the aperture counts.
			pixels.push(
				context.getImageData(
					Math.floor(image.width*.25)
					, 0
					, Math.floor(image.width*.5)
					, Math.floor(image.height*.65)
				).data
			);

			image.close();
		}

		let changed = 0;

		for(let i = 0; i < pixels[0].length; i += 4)
			if(Math.max(...[0,1,2].map(c => Math.abs(pixels[0][i+c]-pixels[1][i+c]))) > 8) changed++;

		return changed/(pixels[0].length/4);
	}, [a.toString('base64'),b.toString('base64')]);

	for(const kind of ['gallery','shaft'])
	{
		const portal = report.verticalConnections.find(
			p => p.kind === kind && report.cells[report.sectorIds[p.to]].ceiling <= 0
		);

		expect(portal).toBeTruthy();

		await run(
			`$portal=json_decode('${JSON.stringify(
				portal
			)}',true); DoomPhonics\\Tests\\SeededWalkthrough::swimPortal($level,$r,$portal);`
		);

		for(const [direction,id] of [['above',portal.from],['below',portal.to]])
		for(const distance of [128,32])
		{
			const c = report.cells[report.sectorIds[id]];
			const eye = direction === 'above' ? c.floor+32+distance : c.ceiling-distance;

			await run(`
				$pose->x=$pose->previousX=${c.center[0]-distance}; $pose->y=$pose->previousY=${c.center[1]};
				$pose->z=$pose->previousZ=${eye-41}; $pose->angle=0; $view->setPitch(${direction === 'above' ? '-.75' : '.75'});
			`
			);

			const shown = await capture(`${kind}-${direction}-${distance}-water.png`);

			// Hide only this water flat and its projection. Swimming tint, other
			// geometry and camera stay fixed, so a difference proves texture visibility.
			await run(`
				$hidden=[]; $model=$level->sectors[${report.sectorIds[portal.to]}]->volumes[0];
				foreach($mesh->volumeSpans as $i=>&$span) if($span['volume']===$model && $span['plane']===$model->topPlane()) {
					$hidden[$i]=$span['opacity']; $span['opacity']=0;
				} unset($span);
			`
			);

			const hidden = await capture(`${kind}-${direction}-${distance}-hidden.png`);

			expect(
				await difference(shown, hidden)
				, `${kind} ${direction} ${distance}: textured water must cover the aperture`
			).toBeGreaterThan(.03);

			await run(`foreach($hidden as $i=>$opacity) $mesh->volumeSpans[$i]['opacity']=$opacity;`);
		}
	}

	expect(await run(`(vrzno_env('waterProbe'))(json_encode(glGetError()));`)).toBe(0);
	expect(errors).toEqual([]);
});
