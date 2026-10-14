import {test,expect} from '@playwright/test';

async function start(page)
{
	const errors=[];
	page.on('pageerror', error=>errors.push(error.message));
	await page.route('**/favicon.ico', route=>route.fulfill({status: 204}));
	page.on('console', message=>{if(message.type()==='error') errors.push(message.text());});

	await page.goto('/');
	await page.waitForFunction(()=>typeof window.bootEngine==='function');

	await page.evaluate(async version=>{
		window.contextFrames=[];
		await window.bootEngine({run: false,version});
		const module=await enginePhp.binary;
		module.contextProbe=frame=>contextFrames.push(JSON.parse(frame));
		module.contextBeforeRender=state=>{
			if(window.loseOnRender)
			{
				window.loseOnRender=false;
				window.contextAtLoss=JSON.parse(state);
				window.loseExtension.loseContext();
			}
		};

		module.contextBeforeRestore=()=>{
			if(window.loseOnRestore)
			{
				window.loseOnRestore=false;
				window.loseExtension.loseContext();
			}
		};

		await enginePhp.run('<?php require "/engine/tests/render-context.php";');
	}, process.env.ENGINE_PHP_VERSION??'8.4'
	);
	await expect.poll(()=>page.evaluate(()=>contextFrames.length)).toBeGreaterThan(2);
	return errors;
}

async function lose(page, duringRender=false)
{
	await page.evaluate(duringRender=>{
		const canvas=document.querySelector('canvas');
		window.contextRestored=false;
		window.contextLost=false;
		window.contextAtLoss=null;
		canvas.addEventListener('webglcontextlost', ()=>contextLost=true, {once: true});
		canvas.addEventListener('webglcontextrestored', ()=>contextRestored=true, {once: true});
		window.loseExtension=canvas.getContext('webgl2').getExtension('WEBGL_lose_context');

		if(!loseExtension) throw Error('WEBGL_lose_context unavailable');

		if(duringRender) window.loseOnRender=true;
		else loseExtension.loseContext();
	}, duringRender
	);
	await page.waitForFunction(()=>contextLost);
}

async function restore(page)
{
	await page.evaluate(()=>loseExtension.restoreContext());
	await page.waitForFunction(()=>contextRestored);
}

test('context recovery preserves PHP state and restores every GPU resource twice', async({page})=>{
	const errors=await start(page);

	for(let cycle=1; cycle<=2; cycle++)
	{
		await lose(page, cycle===2);
		const before=await page.evaluate(()=>({...contextFrames.at(-1),...window.contextAtLoss}));
		const count=await page.evaluate(()=>contextFrames.length);
		await page.waitForTimeout(350);
		expect(errors).toEqual([]);
		expect(await page.evaluate(()=>contextFrames.length)).toBe(count);
		await restore(page);
		await expect.poll(()=>page.evaluate(()=>contextFrames.length)).toBeGreaterThan(count+2);
		const resumed=await page.evaluate(index=>contextFrames[index], count);
		expect(resumed.restores).toBe(cycle);
		expect(resumed.time).toBe(before.time);
		expect(resumed.ticks).toBe(before.ticks);
		expect(resumed.pixels).toBe(before.pixels);
		expect(resumed.atlas).toBe(before.atlas);
		expect(resumed.uploads).toBeGreaterThan(0);
		expect(resumed.images).toBeGreaterThan(0);
		expect(resumed.error).toBe(0);
		expect(errors).toEqual([]);
	}

	await page.evaluate(()=>enginePhp.refresh());
	const count=await page.evaluate(()=>contextFrames.length);
	await lose(page);
	await page.waitForTimeout(100);
	expect(await page.evaluate(()=>contextFrames.length)).toBe(count);
	expect(errors).toEqual([]);
});

test('stopping while the context is lost releases callbacks and allows another run', async({page})=>{
	const errors=await start(page);
	await lose(page);
	await page.evaluate(()=>enginePhp.refresh());
	const count=await page.evaluate(()=>contextFrames.length);
	await restore(page);
	await page.waitForTimeout(150);
	expect(await page.evaluate(()=>contextFrames.length)).toBe(count);
	await page.evaluate(()=>enginePhp.run('<?php require "/engine/tests/render-context.php";'));
	await expect.poll(()=>page.evaluate(()=>contextFrames.length)).toBeGreaterThan(count+2);
	expect(await page.evaluate(()=>contextFrames.at(-1).error)).toBe(0);
	expect(errors).toEqual([]);
	await page.evaluate(()=>enginePhp.refresh());
});

test('visibility pause discards elapsed idle time and releases held input', async({page})=>{
	const errors=await start(page);
	await page.locator('canvas').focus();
	await page.keyboard.down('ArrowRight');
	await expect.poll(()=>page.evaluate(()=>contextFrames.at(-1).right)).toBe(true);

	await page.evaluate(()=>{
		Object.defineProperty(document, 'hidden', {configurable: true,get: ()=>true});
		document.dispatchEvent(new Event('visibilitychange'));
	});

	const count=await page.evaluate(()=>contextFrames.length);
	const before=await page.evaluate(()=>contextFrames.at(-1));
	await page.waitForTimeout(400);
	expect(await page.evaluate(()=>contextFrames.length)).toBe(count);

	await page.evaluate(()=>{
		delete document.hidden;
		document.dispatchEvent(new Event('visibilitychange'));
	});

	await expect.poll(()=>page.evaluate(()=>contextFrames.length)).toBeGreaterThan(count+2);
	const resumed=await page.evaluate(index=>contextFrames[index], count);
	expect(resumed.time).toBe(before.time);
	expect(resumed.ticks).toBe(before.ticks);
	expect(resumed.restores).toBe(0);
	expect(resumed.right).toBe(false);
	await page.keyboard.up('ArrowRight');
	await page.keyboard.down('ArrowRight');
	await expect.poll(()=>page.evaluate(()=>contextFrames.at(-1).right)).toBe(true);
	await page.keyboard.up('ArrowRight');
	await page.evaluate(()=>enginePhp.refresh());
	expect(errors).toEqual([]);
});

test('another context loss during rebuilding can recover on the next restoration', async({page})=>{
	const errors=await start(page);
	await lose(page);
	const count=await page.evaluate(()=>contextFrames.length);
	const before=await page.evaluate(()=>contextFrames.at(-1));

	await page.evaluate(()=>{
		window.loseOnRestore=true;
		window.lostAgain=false;
		document.querySelector('canvas').addEventListener('webglcontextlost', ()=>window.lostAgain=true, {once: true});
	});

	await restore(page);
	await page.waitForFunction(()=>lostAgain);
	await page.waitForTimeout(100);
	expect(await page.evaluate(()=>contextFrames.length)).toBe(count);
	await page.evaluate(()=>{window.contextRestored=false;});

	await page.evaluate(()=>document.querySelector(
		'canvas'
	).addEventListener('webglcontextrestored', ()=>window.contextRestored=true, {once: true}));
	await restore(page);
	await expect.poll(()=>page.evaluate(()=>contextFrames.length)).toBeGreaterThan(count+2);
	const resumed=await page.evaluate(index=>contextFrames[index], count);
	expect(resumed.pixels).toBe(before.pixels);
	expect(resumed.time).toBe(before.time);
	expect(resumed.restores).toBe(1);
	expect(resumed.error).toBe(0);
	expect(errors).toEqual([]);
	await page.evaluate(()=>enginePhp.refresh());
});
