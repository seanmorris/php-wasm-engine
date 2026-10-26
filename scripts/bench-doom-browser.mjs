import fs from 'node:fs/promises';
import path from 'node:path';
import {chromium} from '@playwright/test';

const options = Object.fromEntries(process.argv.slice(2).map(argument => {
	const match = /^--([a-z-]+)=(.*)$/.exec(argument);

	if(!match) throw new Error('Expected --name=value arguments');

	return [match[1], match[2]];
}));

const url = options.url
	?? ('http://127.0.0.1:9130/doom-phonics/?world='
		+ '/engine/packages/doom-phonics/demo/skulltag-stress.phonics-world.json'
	);

const seconds = Number(options.seconds ?? 8);

if(!Number.isFinite(seconds) || seconds < 1 || seconds > 60) throw new Error('--seconds must be between 1 and 60');

const output = path.resolve(options.output ?? 'scratch/d2st9/performance');
await fs.mkdir(output, {recursive: true});
const views = options.views ? JSON.parse(await fs.readFile(options.views, 'utf8')) : [{name: 'spawn'}];

for(const view of views)
{
	if(!/^[a-z0-9-]+$/.test(view.name)) throw new Error('View names must be lowercase filename components');

	if(view.position && (view.position.length !== 4 || !view.position.every(Number.isFinite)))
	throw new Error('View positions must be [x,y,z,angleRadians]');
}

const software = options.software !== 'false';
const executablePath = options.chromium
	?? await fs.access('/usr/bin/chromium').then(() => '/usr/bin/chromium', () => undefined);

const browser = await chromium.launch({
	executablePath
	, args: ['--no-sandbox'
		, ...(software
			? ['--use-gl=angle','--use-angle=swiftshader','--enable-unsafe-swiftshader']
			: []
		)
	]
});

const errors = [], runs = [];
let page;

const percentile = (values, fraction) => {
	const ordered = [...values].sort((a, b) => a - b);

	return ordered[Math.min(values.length - 1, Math.floor(values.length * fraction))];
};

const round = value => Math.round(value * 100) / 100;

try
{
	page = await browser.newPage({viewport: {width: 1100, height: 800}});

	const recordError = message => {errors.push(message); console.error(message);};

	page.on('pageerror', error => recordError(error.message));
	page.on('console', message => {if(message.type() === 'error') recordError(message.text());});

	const started = Date.now();
	await page.goto(url);

	await page.waitForFunction(
		() => document.querySelector('#status')?.textContent === 'Ready'
			|| document.querySelector('.phonics-preloader[data-failed]')
		, null
		, {timeout: 180000}
	);

	if(await page.locator('#status').textContent() !== 'Ready')
	throw new Error(await page.locator('#status').textContent());

	const loadMs = Date.now() - started;
	const canvas = page.locator('canvas');

	const state = () => page.evaluate(() => window.previewSession.doomState);
	console.log(`Ready in ${loadMs} ms`);
	await canvas.focus();
	await page.keyboard.press('Enter');

	await page.waitForFunction(() => window.previewSession.doomState.menu.screen === 'skill');

	// Let the menu observe the released confirmation key before selecting the skill.
	await page.waitForTimeout(300);
	await page.keyboard.press('Enter');

	await page.waitForFunction(() => {
		const status = window.previewSession.doomState;

		return !status.menu.open && !status.wipe.active;
	}, null, {timeout: 120000});

	const php = async code => {
		const exit = await page.evaluate(code => window.previewSession.php.run(code), code);

		if(exit) throw new Error(`Benchmark setup PHP exited ${exit}`);
	};

	const spawn = (await state()).position;

	for(const resolution of ['native','classic'])
	for(const view of views)
	{
		const [x,y,z,angle] = view.position ?? spawn;

		await php(String.raw`<?php
			$game->settings->setRenderResolution(DoomPhonics\Game\RenderResolution::from('${resolution}'));
			$benchRegistry = $engine->entities;
			$benchEntity = $benchRegistry->query(DoomPhonics\Game\PlayerState::class)[0];
			$benchPose = $benchRegistry->get($benchEntity, DoomPhonics\Component\Transform::class);
			$benchPose->x = $benchPose->previousX = ${x};
			$benchPose->y = $benchPose->previousY = ${y};
			$benchPose->z = $benchPose->previousZ = ${z};
			$benchPose->angle = ${angle};
			$benchVelocity = $benchRegistry->get($benchEntity, DoomPhonics\Component\Velocity::class);
			$benchVelocity->x = $benchVelocity->y = $benchVelocity->z = 0;
		`
		);

		await page.waitForTimeout(1500);
		const before = await state();

		if(view.move) await page.keyboard.down('w');

		if(view.turn) await page.keyboard.down('ArrowRight');

		const frames = await page.evaluate(seconds => new Promise(resolve => {
			const frames = [];
			let previous = null, end = null;

			const sample = time => {
				if(previous !== null)
				{
					const status = window.previewSession.doomState;

					frames.push(
						{dt: time - previous, ...status.frame, render: status.render, position: status.position}
					);
				}

				end ??= time + seconds * 1000;
				previous = time;

				if(time < end) requestAnimationFrame(sample);
				else resolve(frames);
			};

			requestAnimationFrame(sample);
		}), seconds
		);

		await page.keyboard.up('w');
		await page.keyboard.up('ArrowRight');

		const after = await state();
		const durations = frames.map(frame => frame.dt);

		const summary = {
			view: view.name
			, resolution
			, scene: after.render.scene
			, fps: round(frames.length * 1000 / durations.reduce((a, b) => a+b, 0))
			, frameP50Ms: round(percentile(durations, .5))
			, frameP95Ms: round(percentile(durations, .95))

			, mapCpuP50Ms: round(percentile(frames.map(frame => frame.render.mapCpuMs), .5))
			, mapStagesP50Ms: Object.fromEntries(Object.keys(after.render.mapStagesMs ?? {}).map(stage => [
				stage
				, round(percentile(frames.map(frame => frame.render.mapStagesMs[stage]), .5))
			]))
			, actorCpuP50Ms: round(percentile(frames.map(frame => frame.render.actorCpuMs), .5))
			, renderCpuP50Ms: round(percentile(frames.map(frame => frame.renderCpuMs), .5))
			, simulationCpuP50Ms: round(percentile(frames.map(frame => frame.simulationCpuMs), .5))
			, positionBefore: before.position
			, positionAfter: after.position
		};

		console.log(JSON.stringify(summary));
		const name = `${resolution}-${view.name}`;
		await canvas.screenshot({path: path.join(output, name + '.png')});
		await fs.writeFile(path.join(output, name + '.json'), JSON.stringify({summary,frames,before,after}, null, 2));
		runs.push(summary);
	}

	const gl = await page.evaluate(() => {
		const gl = document.querySelector('canvas').getContext('webgl2');
		const debug = gl?.getExtension('WEBGL_debug_renderer_info');

		return debug ? gl.getParameter(debug.UNMASKED_RENDERER_WEBGL) : 'unknown';
	});

	await fs.writeFile(
		path.join(output, 'summary.json')
		, JSON.stringify({
			url
			, loadMs
			, sampleSecondsPerView: seconds
			, browser: await browser.version()
			, software
			, gl
			// PHP diagnostic values refresh at 10 Hz; frame timing is sampled independently each RAF.
			, cpuSampling: 'latest 10 Hz diagnostic per animation frame'
			, runs
			, errors
		}, null, 2
		)
	);

	await page.click('#stop');

	if(errors.length) throw new Error(errors.join('\n'));
}
catch(error)
{
	const state = await page?.evaluate(() => window.previewSession?.doomState).catch(() => null);
	await fs.writeFile(path.join(output, 'failure.json'), JSON.stringify({error: String(error),errors,state}, null, 2));
	await page?.screenshot({path: path.join(output, 'failure.png')}).catch(() => {});

	throw error;
}
finally
{
	await browser.close();
}
