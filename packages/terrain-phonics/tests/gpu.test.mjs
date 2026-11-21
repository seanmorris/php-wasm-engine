import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {chromium} from '@playwright/test';

// Run directly with: node --test packages/terrain-phonics/tests/gpu.test.mjs
// Requires PHP and Chromium (CHROMIUM overrides the executable; otherwise system or Playwright Chromium).
test('terrain WebGL2 caches and analytic water agree with their numeric references', {timeout: 60_000}, async () => {
	const fixture = fileURLToPath(new URL('./gpu-fixture.php', import.meta.url));
	const data = JSON.parse(execFileSync('php', [fixture], {encoding: 'utf8'}));

	const names = ['atmosphere-cache.vert'
		, 'atmosphere-cache.frag'
		, 'atmosphere-aerial.frag'
		, 'atmosphere.glsl'
		, 'patch-grid.glsl'
		, 'water-functions.glsl'
		, 'water-optics.glsl'
		, 'spot-lights.glsl'
		, 'shadows.vert'
		, 'shadows.frag'
		, 'triangle-shadows.frag'

		, 'stars.vert', 'stars.frag'
		, 'sky.vert'
		, 'sky.frag'
		, 'water.vert'
		, 'water.frag'
		, 'terrain.vert'
		, 'terrain.frag'
		, 'surface-layers.glsl'
	];

	const shaders = Object.fromEntries(names.map(name => [name
		, readFileSync(new URL(`../shaders/${name}`, import.meta.url), 'utf8')
	]));
	shaders['terrain.frag'] = shaders['terrain.frag'].replace('// LAYERS', shaders['surface-layers.glsl']);

	const browser = await chromium.launch({
		executablePath: process.env.CHROMIUM ?? (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined)
		, headless: true
		, args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader']
	});

	try
	{
		const page = await browser.newPage();

		const result = await page.evaluate(({data, shaders}) => {
			const canvas = document.createElement('canvas');
			canvas.width = 256;
			canvas.height = 128;
			const gl = canvas.getContext('webgl2', {antialias: false, preserveDrawingBuffer: true});

			if(!gl) throw new Error('WebGL2 is unavailable');

			const includes = [
				['// PATCH', 'patch-grid']
				, ['// OPTICS', 'water-optics']
				, ['// SPOT LIGHTS', 'spot-lights']
			];

			const compile = (type, source) => {
				const shader = gl.createShader(type);
				let text = source;

				for(const [marker, name] of includes) text = text.replace(marker, shaders[`${name}.glsl`]);

				gl.shaderSource(shader, text);
				gl.compileShader(shader);

				if(!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(shader));

				return shader;
			};

			const program = (vertex, fragment, varyings = null) => {
				const linked = gl.createProgram();
				const stages = [compile(gl.VERTEX_SHADER, vertex), compile(gl.FRAGMENT_SHADER, fragment)];

				for(const stage of stages) gl.attachShader(linked, stage);

				if(varyings) gl.transformFeedbackVaryings(linked, varyings, gl.INTERLEAVED_ATTRIBS);

				gl.linkProgram(linked);

				for(const stage of stages) gl.deleteShader(stage);

				if(!gl.getProgramParameter(linked, gl.LINK_STATUS)) throw new Error(gl.getProgramInfoLog(linked));

				return linked;
			};

			const parameters = linked => {
				gl.useProgram(linked);

				for(const [key, value] of Object.entries(data.uniforms))
				{
					const location = gl.getUniformLocation(linked, `u${key[0].toUpperCase()}${key.slice(1)}`);

					if(Array.isArray(value))
					{
						if(value.length === 3) gl.uniform3fv(location, value);
						else if(value.length === 2) gl.uniform2fv(location, value);
					}
					else gl.uniform1f(location, value);
				}

				gl.uniform1i(gl.getUniformLocation(linked, 'uTransmittance'), 0);
				gl.uniform4f(gl.getUniformLocation(linked, 'uWaterOptics'), .85, .85, .035, .65);
				gl.uniform3f(gl.getUniformLocation(linked, 'uWaterTint'), .02, .17, .18);
			};

			const appearance = (linked, uniforms) => {
				for(const [name, values] of Object.entries(uniforms))
				{
					const location = gl.getUniformLocation(linked, name);

					// Only the canyon and mountain palettes are longer than a vec4; both are arrays of vec3.
					if(values.length === 1) gl.uniform1f(location, values[0]);
					else gl[`uniform${values.length > 4 ? 3 : values.length}fv`](location, values);
				}
			};

			const texture = (unit, width, height, bytes) => {
				const created = gl.createTexture();
				gl.activeTexture(gl.TEXTURE0 + unit);
				gl.bindTexture(gl.TEXTURE_2D, created);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
				gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA8, width, height, 0, gl.RGBA, gl.UNSIGNED_BYTE, bytes);

				return created;
			};

			const framebuffer = gl.createFramebuffer();

			const target = (unit, width, height) => {
				const created = texture(unit, width, height, null);
				gl.bindFramebuffer(gl.FRAMEBUFFER, framebuffer);
				gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, created, 0);

				if(gl.checkFramebufferStatus(gl.FRAMEBUFFER) !== gl.FRAMEBUFFER_COMPLETE)
				throw new Error('RGBA8 cache framebuffer is incomplete');

				gl.viewport(0, 0, width, height);
			};

			const pixel = () => {
				gl.viewport(0, 0, 1, 1);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				const bytes = new Uint8Array(4);
				gl.readPixels(0, 0, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, bytes);

				return Array.from(bytes).slice(0, 3);
			};

			const vertex = shaders['atmosphere-cache.vert'];

			const integrate = source => source.replace('// ATMOSPHERE', shaders['atmosphere.glsl']);
			const header = '#version 300 es\nprecision highp float;\nprecision highp int;\n';
			gl.bindVertexArray(gl.createVertexArray());
			texture(0, 64, 16, Uint8Array.from(atob(data.lut), c => c.charCodeAt(0)));
			const skyCache = program(vertex, integrate(shaders['atmosphere-cache.frag']));

			for(const [unit, altitude] of [[2, 100], [3, 0]])
			{
				target(unit, 256, 128);
				parameters(skyCache);
				gl.uniform2f(gl.getUniformLocation(skyCache, 'uSkyCacheSize'), 256, 128);
				gl.uniform1f(gl.getUniformLocation(skyCache, 'uSkyCacheAltitude'), altitude);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
			}

			gl.bindFramebuffer(gl.FRAMEBUFFER, null);

			const skySample = program(vertex, `${header}${data.source}
				uniform vec3 uTestRay; uniform float uTestAltitude; out vec4 color;
				void main() { color = vec4(terrainToneMap(skyRadiance(uTestRay, uTestAltitude)), 1.0); }`
			);

			parameters(skySample);
			gl.uniform1i(gl.getUniformLocation(skySample, 'uSkyCacheEye'), 2);
			gl.uniform1i(gl.getUniformLocation(skySample, 'uSkyCacheWater'), 3);
			gl.uniform2f(gl.getUniformLocation(skySample, 'uSkyCacheSize'), 256, 128);
			gl.uniform2f(gl.getUniformLocation(skySample, 'uSkyCacheAltitudes'), 100, 0);
			const skyResults = [];

			for(const altitude of [0, 100])
			for(const ray of [[0, 1, 0]
				, [1, .5, 0]
				, [-1, .2, 1]
				, [1, .03, 0]
				, [-1, .01, 1]
				, data.uniforms
				.sunDirection
			]){
				const colors = [];

				for(const ready of [0, 1])
				{
					gl.uniform1i(gl.getUniformLocation(skySample, 'uSkyCacheReady'), ready);
					gl.uniform3fv(gl.getUniformLocation(skySample, 'uTestRay'), ray);
					gl.uniform1f(gl.getUniformLocation(skySample, 'uTestAltitude'), altitude);
					colors.push(pixel());
				}

				skyResults.push({altitude, ray, colors});
			}

			const aerialCache = program(vertex, integrate(shaders['atmosphere-aerial.frag']));

			const buildAerialCache = altitude => {
				target(4, 512, 16);
				const radiance = texture(5, 512, 16, null);
				gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT1, gl.TEXTURE_2D, radiance, 0);
				gl.drawBuffers([gl.COLOR_ATTACHMENT0, gl.COLOR_ATTACHMENT1]);
				parameters(aerialCache);
				gl.uniform3f(gl.getUniformLocation(aerialCache, 'uAerialCacheGrid'), 32, 16, 16);
				gl.uniform1f(gl.getUniformLocation(aerialCache, 'uAerialCacheMaxDistance'), 90000);
				gl.uniform1f(gl.getUniformLocation(aerialCache, 'uAerialCacheAltitude'), altitude);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				gl.drawBuffers([gl.COLOR_ATTACHMENT0]);
				gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT1, gl.TEXTURE_2D, null, 0);

				gl.bindFramebuffer(gl.FRAMEBUFFER, null);
			};
			buildAerialCache(100);

			const aerialSample = program(vertex, `${header}${data.source}
				uniform vec3 uTestRay; uniform float uTestAltitude; uniform float uTestDistance; out vec4 color;
				void main() { color = vec4(terrainToneMap(terrainAtmosphere(uTestRay, uTestAltitude,
					uTestDistance, vec3(.15, .3, .1))), 1.0); }`
			);

			parameters(aerialSample);
			gl.uniform1i(gl.getUniformLocation(aerialSample, 'uAerialCacheTransmittance'), 4);
			gl.uniform1i(gl.getUniformLocation(aerialSample, 'uAerialCacheRadiance'), 5);
			gl.uniform3f(gl.getUniformLocation(aerialSample, 'uAerialCacheGrid'), 32, 16, 16);
			gl.uniform1f(gl.getUniformLocation(aerialSample, 'uAerialCacheMaxDistance'), 90000);
			gl.uniform1f(gl.getUniformLocation(aerialSample, 'uAerialCacheAltitude'), 100);
			const aerialResults = [];

			for(const altitude of [100, 120])
			for(const distance of [0, 1, 100, 1000, 10000, 45000, 90000, 95000])
			for(const ray of [[1, 0, 0], [1, .02, 0], [0, -.1, 1], [-1, .3, 1]])
			{
				const colors = [];

				for(const ready of [0, 1])
				{
					gl.uniform1i(gl.getUniformLocation(aerialSample, 'uAerialCacheReady'), ready);
					gl.uniform3fv(gl.getUniformLocation(aerialSample, 'uTestRay'), ray);
					gl.uniform1f(gl.getUniformLocation(aerialSample, 'uTestAltitude'), altitude);
					gl.uniform1f(gl.getUniformLocation(aerialSample, 'uTestDistance'), distance);
					colors.push(pixel());
				}

				aerialResults.push({altitude, distance, ray, colors});
			}

			// The default canyon clamps to sea-level density. A visible wall spans both sides of eye level.
			buildAerialCache(0);
			gl.useProgram(aerialSample);
			gl.uniform1f(gl.getUniformLocation(aerialSample, 'uAerialCacheAltitude'), 0);
			gl.uniform1f(gl.getUniformLocation(aerialSample, 'uTestAltitude'), -380);
			const canyonHaze = [];
			for(const ready of [0,1])
			{
				gl.uniform1i(gl.getUniformLocation(aerialSample, 'uAerialCacheReady'), ready);
				const colors = [];
				for(const [elevation,distance] of [[-.001,0],[-.001,4000],[.001,4000]])
				{
					gl.uniform3f(gl.getUniformLocation(aerialSample, 'uTestRay'), 1,elevation,0);
					gl.uniform1f(gl.getUniformLocation(aerialSample, 'uTestDistance'), distance);
					colors.push(pixel());
				}
				canyonHaze.push(colors);
			}

			// Capture shader floats directly, using core WebGL2 transform feedback rather than a float render target.
			const waterSource = shaders['water-functions.glsl'];

			const waterProgram = program(
				`${header}${waterSource}
				uniform vec2 uTestPosition; uniform float uTestTime;
				out vec4 vSample; out vec3 vNormal; out vec4 vFrame;
				void main() { vSample = terrainWaterSample(uTestPosition, uTestTime);
					vFrame = terrainFrameWakes(uTestPosition, vec2(0), vec2(0), true);
					vFrame.xyz += terrainDirectionalSample(uTestPosition, uTestTime).xyz;
					vNormal = terrainWaterNormal(vSample); gl_Position = vec4(0, 0, 0, 1); gl_PointSize = 1.0; }`
				, `${header}out vec4 color; void main() { color = vec4(0); }`
				, ['vSample', 'vNormal', 'vFrame']
			);

			const feedback = gl.createTransformFeedback();
			const outputBuffer = gl.createBuffer();
			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, feedback);
			gl.bindBuffer(gl.TRANSFORM_FEEDBACK_BUFFER, outputBuffer);
			gl.bufferData(gl.TRANSFORM_FEEDBACK_BUFFER, 11 * 4, gl.DYNAMIC_READ);
			gl.bindBufferBase(gl.TRANSFORM_FEEDBACK_BUFFER, 0, outputBuffer);
			gl.useProgram(waterProgram);
			gl.enable(gl.RASTERIZER_DISCARD);
			const waterResults = [];

			for(const scenario of data.waterCases)
			{
				const values = scenario.uniforms;
				gl.uniform1f(gl.getUniformLocation(waterProgram, 'uWaterLevel'), values.level);
				gl.uniform1i(gl.getUniformLocation(waterProgram, 'uWaveCount'), values.waveCount);
				gl.uniform1i(gl.getUniformLocation(waterProgram, 'uWakeCount'), values.wakeCount);

				values.waveVectors.forEach(
					(v, i) => gl.uniform4fv(gl.getUniformLocation(waterProgram, `uWaveVectors[${i}]`), v)
				);

				values.waveDynamics.forEach(
					(v, i) => gl.uniform2fv(gl.getUniformLocation(waterProgram, `uWaveDynamics[${i}]`), v)
				);

				values.wakeVectors.forEach(
					(v, i) => gl.uniform4fv(gl.getUniformLocation(waterProgram, `uWakeVectors[${i}]`), v)
				);

				for(const sample of scenario.samples)
				{
					gl.uniform2fv(gl.getUniformLocation(waterProgram, 'uTestPosition'), sample.position);
					gl.uniform1f(gl.getUniformLocation(waterProgram, 'uTestTime'), sample.time);
					gl.uniform1i(gl.getUniformLocation(waterProgram, 'uFrameWakeCount'), sample.packets.length);
					if(sample.packets.length)
					{
						gl.uniform4fv(gl.getUniformLocation(waterProgram, 'uFrameWakes[0]'), sample.packets.flat());
						gl.uniform2fv(gl.getUniformLocation(waterProgram, 'uJetVelocities[0]'), sample.jets.flat());
						gl.uniform4fv(gl.getUniformLocation(waterProgram, 'uWakeBounds'), sample.bounds);
					}
					gl.beginTransformFeedback(gl.POINTS);
					gl.drawArrays(gl.POINTS, 0, 1);
					gl.endTransformFeedback();

					const actual = new Float32Array(11);
					gl.getBufferSubData(gl.TRANSFORM_FEEDBACK_BUFFER, 0, actual);
					waterResults.push({name: scenario.name, ...sample, actual: Array.from(actual)});
				}
			}

			gl.disable(gl.RASTERIZER_DISCARD);
			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, null);

			const shadowProgram = program(shaders['shadows.vert'], shaders['shadows.frag']);
			const heightTexture = gl.createTexture();
			gl.activeTexture(gl.TEXTURE1);
			gl.bindTexture(gl.TEXTURE_2D, heightTexture);
			gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
			gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);
			const shadowResults = [];

			for(const [name, sun, ridge] of [['flat', [1, .001, 0], false]
				, ['ridge', [1, .1, 0], true]
				, ['reversed', [-1, .1, 0], true], ['night', [1, -.1, 0], false]
			]){
				const heights = new Float32Array(33 * 33);

				if(ridge)
				for(let z = 0; z < 33; z++) for(let x = 16; x <= 18; x++) heights[z * 33 + x] = 300;

				gl.activeTexture(gl.TEXTURE1);
				gl.bindTexture(gl.TEXTURE_2D, heightTexture);
				gl.texImage2D(gl.TEXTURE_2D, 0, gl.R32F, 33, 33, 0, gl.RED, gl.FLOAT, heights);
				target(6, 256, 256);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
				gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
				gl.useProgram(shadowProgram);
				gl.uniform1i(gl.getUniformLocation(shadowProgram, 'uTerrainHeights'), 1);
				gl.uniform1f(gl.getUniformLocation(shadowProgram, 'uTerrainShadowExtent'), 2048);
				gl.uniform1f(gl.getUniformLocation(shadowProgram, 'uTerrainShadowSize'), 256);

				gl.uniform3fv(
					gl.getUniformLocation(shadowProgram, 'uShadowSunDirection')
					, sun.map(v => v / Math.hypot(...sun))
				);

				gl.drawArrays(gl.TRIANGLES, 0, 3);
				const visibility = [];

				for(const x of [64, 192])
				{
					const bytes = new Uint8Array(4);
					gl.readPixels(x, 128, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
					visibility.push(bytes[0]);
				}

				shadowResults.push({name, visibility});
			}

			// A wall only one metre thick crosses the interior of an eight-metre terrain triangle.
			// Run both production passes against a signed, toroidal lattice in each storage format.
			const adaptive = program(shaders['shadows.vert'], shaders['triangle-shadows.frag']);
			const adaptiveResults = [];
			const sampleFunction = shaders['terrain.frag'].split('float localSunlight')[1].split('#endif')[0];
			const sampleTriangle = program(vertex, header + `
				uniform highp sampler2D uShadowTriangles;
				uniform vec3 uShadowGrid;
				uniform float uCraft;
				out vec4 color;
				float localSunlight` + sampleFunction + `
				void main() {
					vec2 samples[6] = vec2[6](vec2(1),vec2(7,.2),vec2(8./3.),vec2(4,2),vec2(6,1.99),vec2(6,2.01));
					int i = int(gl_FragCoord.x);
					color = vec4(localSunlight(samples[i],i == 3 ? 10. : .01,1.),0.,0.,1.);
				}`);
			const local = name => gl.getUniformLocation(adaptive,name);
			for(const packed of [false,true])
			for(const stripe of [false,true]) {
				const field = new Float32Array(64*64).fill(-350);
				for(let z = 0; z < 64; z++) {
					if(!stripe || z === 2 || z === 3) field[z*64+(stripe ? 8 : 10)] += stripe ? 12 : 6;
				}
				texture(10,64,64,null);
				if(packed) gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA8,64,64,0,gl.RGBA,gl.UNSIGNED_BYTE,new Uint8Array(field.buffer));
				else gl.texImage2D(gl.TEXTURE_2D,0,gl.R32F,64,64,0,gl.RED,gl.FLOAT,field);
				// Keep the far field below every sun ray.
				const distant = new Float32Array(4).fill(-1000);
				texture(11,2,2,null);
				if(packed) gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA8,2,2,0,gl.RGBA,gl.UNSIGNED_BYTE,new Uint8Array(distant.buffer));
				else gl.texImage2D(gl.TEXTURE_2D,0,gl.R32F,2,2,0,gl.RED,gl.FLOAT,distant);
				gl.useProgram(adaptive);
				gl.uniform1i(local('uCaster'),10);
				gl.uniform1i(local('uOverview'),11);
				gl.uniform1i(local('uPacked'),+packed);
				gl.uniform1f(local('uCasterStep'),1);
				gl.uniform1f(local('uReach'),24);
				gl.uniform1f(local('uExtent'),64);
				gl.uniform3f(local('uSun'),Math.SQRT1_2,Math.SQRT1_2,0);
				gl.uniform3f(local('uShadowGrid'),-64,-64,8);
				target(12,17,17);
				gl.uniform1i(local('uVertices'),10);
				gl.uniform1i(local('uRefine'),0);
				gl.drawArrays(gl.TRIANGLES,0,3);
				target(13,32,16);
				gl.uniform1i(local('uVertices'),12);
				gl.uniform1i(local('uRefine'),1);
				gl.drawArrays(gl.TRIANGLES,0,3);
				const pixel = new Uint8Array(4);
				gl.readPixels(16,8,1,1,gl.RGBA,gl.UNSIGNED_BYTE,pixel);
				target(14,6,1);
				gl.useProgram(sampleTriangle);
				gl.uniform1i(gl.getUniformLocation(sampleTriangle,'uShadowTriangles'),13);
				gl.uniform3f(gl.getUniformLocation(sampleTriangle,'uShadowGrid'),-64,-64,8);
				gl.drawArrays(gl.TRIANGLES,0,3);
				const rendered = new Uint8Array(24);
				gl.readPixels(0,0,6,1,gl.RGBA,gl.UNSIGNED_BYTE,rendered);
				adaptiveResults.push({record:[...pixel], pixels:[...rendered.filter((_,i)=>i%4===0)]});
			}
			gl.bindFramebuffer(gl.FRAMEBUFFER, null);

			// Actual surface fragments must receive zero radiance when their only light source has zero irradiance.
			const surfaceVertex = `${header}
				out vec3 vRelative, vWorld, vNormal, vPaint0, vPaint1; out float vGround;
				uniform float uTestGround;
				void main() { vec2 p = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
					vRelative = vec3(0, -5, -5); vWorld = vec3(0); vNormal = vec3(0, 1, 0);
					vPaint0 = vec3(0, 1, 0); vPaint1 = vec3(0); vGround = uTestGround;
					gl_Position = vec4(p * 2.0 - 1.0, 0, 1); }`;

			const illuminationResults = [];
			let inactiveAppearance = null;

			for(const name of ['terrain', 'water'])
			{
				const surface = program(
					surfaceVertex
					, shaders[`${name}.frag`].replace('// ATMOSPHERE', data.source).replace('// WATER', waterSource)
				);

				parameters(surface);

				// The compiler drops a uniform the shader stops reading, and uploads to it then do nothing.
				if(name === 'terrain')
				{
					inactiveAppearance = Object.keys(data.appearance)
						.filter(uniform => gl.getUniformLocation(surface, uniform) === null);
				}

				if(name === 'terrain') appearance(surface, data.appearance);

				gl.uniform3f(gl.getUniformLocation(surface, 'uEye'), 0, 5, 5);
				gl.uniform1f(gl.getUniformLocation(surface, 'uTestGround'), -3);
				const colors = [];

				for(const intensity of [0, 20])
				{
					gl.uniform1f(gl.getUniformLocation(surface, 'uSunIntensity'), intensity);
					colors.push(pixel());
				}

				// The test vertex paints grass, so a red grass colour must turn the lit fragment red.
				if(name === 'terrain')
				{
					appearance(surface, data.redGrass);
					colors.push(pixel());
					appearance(surface, data.appearance);
				}

				gl.uniform1f(gl.getUniformLocation(surface, 'uSunIntensity'), 0);
				gl.uniform3fv(gl.getUniformLocation(surface, 'uNightAmbient'), data.nightAmbient);
				illuminationResults.push({name, colors, night: pixel()});
			}

			// One row of banded rock on a vertical face, with the strata phase advancing by a fixed step per pixel.
			// Each step puts the bands on a whole DFT bin and one of their odd harmonics just past the pixel rate.
			const bandVertex = `${header}
				out vec3 vRelative, vWorld, vNormal, vPaint0, vPaint1;
				uniform float uBandStep;
				void main() { vec2 p = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
					vRelative = vec3(-50, 0, 0); vWorld = vec3(0, 500.0 + p.x * 256.0 * uBandStep, 0);
					vNormal = vec3(1, 0, 0); vPaint0 = vec3(0); vPaint1 = vec3(0);
					gl_Position = vec4(p * 2.0 - 1.0, 0, 1); }`;

			const bandProgram = program(bandVertex, shaders['terrain.frag'].replace('// ATMOSPHERE', data.source));
			parameters(bandProgram);
			gl.uniform3f(gl.getUniformLocation(bandProgram, 'uEye'), 0, 3000, 0);
			target(7, 256, 1);
			const bandStep = gl.getUniformLocation(bandProgram, 'uBandStep');
			const bandResults = [];

			for(const uniforms of data.bands)
			for(const bin of [43, 26, 19, 15])
			{
				const bytes = new Uint8Array(256 * 4);

				appearance(bandProgram, uniforms);
				gl.uniform1f(bandStep, 2 * Math.PI * bin / 256 / uniforms.uBanding[0]);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				gl.readPixels(0, 0, 256, 1, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
				const red = Array.from({length: 256}, (_, i) => bytes[i * 4]);
				bandResults.push({sharpness: uniforms.uBanding[2], bin, red});
			}

			// The same rows on a mountain face, which is granite without strata.
			gl.uniform1f(gl.getUniformLocation(bandProgram, 'uTerrainStyle'), 1);
			appearance(bandProgram, data.bands[0]);
			const mountainBands = [];

			for(const bin of [43, 26, 19, 15])
			{
				const bytes = new Uint8Array(256 * 4);

				gl.uniform1f(bandStep, 2 * Math.PI * bin / 256 / data.bands[0].uBanding[0]);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				gl.readPixels(0, 0, 256, 1, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
				mountainBands.push({bin, red: Array.from({length: 256}, (_, i) => bytes[i * 4])});
			}

			// A mountain face 200 m across at the given slope, seen square on at under a metre per pixel, so every
			// patch noise is at full strength. It lies 150 m below the snowline, at the default treeline, where talus
			// still shows between the trees.
			const amountVertex = `${header}
				out vec3 vRelative, vWorld, vNormal, vPaint0, vPaint1;
				uniform float uFaceSlope;
				void main() { vec2 p = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
					vec2 s = p * 2.0 - 1.0;
					float c = 1.0 - uFaceSlope, a = sqrt(1.0 - c * c);
					vNormal = vec3(a, c, 0);
					vWorld = vec3(-1200, 190, 3000) + s.x * vec3(0, 0, 100) + s.y * 25.0 * vec3(-c, a, 0);
					vRelative = -150.0 * vNormal; vPaint0 = vec3(0); vPaint1 = vec3(0);
					gl_Position = vec4(s, 0, 1); }`;

			const amountProgram = program(amountVertex, shaders['terrain.frag'].replace('// ATMOSPHERE', data.source));
			parameters(amountProgram);
			gl.uniform1f(gl.getUniformLocation(amountProgram, 'uTerrainStyle'), 1);
			gl.uniform3f(gl.getUniformLocation(amountProgram, 'uEye'), 0, 3000, 0);
			target(7, 256, 64);
			const mountainAmounts = [];

			for(const {material, slope, amount, cases} of data.mountainAmounts)
			{
				gl.uniform1f(gl.getUniformLocation(amountProgram, 'uFaceSlope'), slope);

				const images = cases.map(uniforms => {
					const bytes = new Uint8Array(256 * 64 * 4);
					appearance(amountProgram, uniforms);
					gl.drawArrays(gl.TRIANGLES, 0, 3);
					gl.readPixels(0, 0, 256, 64, gl.RGBA, gl.UNSIGNED_BYTE, bytes);

					return bytes;
				});

				// A patch in either colour differs by far more than 8 steps, while a mix of talus into granite at a
				// weight of 1 can keep a rounding error from the talus colour and move a pixel by one step.
				let differing = 0;

				for(let i = 0; i < images[0].length; i += 4)
					if([0, 1, 2].some(c => Math.abs(images[0][i + c] - images[1][i + c]) > 8)) differing++;

				mountainAmounts.push({material, amount, share: differing / (256 * 64)});
			}

			gl.bindFramebuffer(gl.FRAMEBUFFER, null);

			// Draw the real surface over two known opaque colors. Their difference isolates transmission.
			const surface = program(surfaceVertex, shaders['water.frag'].replace('// ATMOSPHERE', data.source)
				.replace('// WATER', waterSource));
			parameters(surface);
			gl.uniform3f(gl.getUniformLocation(surface, 'uEye'), 0, 5, 5);
			gl.enable(gl.BLEND);
			gl.blendFuncSeparate(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA, gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
			const backdropContrast = [];
			for(const [transparency, reflection, foam, ground] of [
				[0,0,0,-3], [.5,0,0,-3], [1,0,0,-3], [1,1,0,-3], [1,0,1,0], [1,0,1,-3]
			])
			{
				gl.uniform4f(gl.getUniformLocation(surface, 'uWaterOptics'), transparency, reflection, 0, foam);
				gl.uniform1f(gl.getUniformLocation(surface, 'uTestGround'), ground);
				const reds = [];
				for(const red of [0, 1])
				{
					gl.clearColor(red, 0, 0, 1);
					gl.clear(gl.COLOR_BUFFER_BIT);
					reds.push(pixel()[0]);
				}
				backdropContrast.push(reds[1] - reds[0]);
			}
			gl.disable(gl.BLEND);
			const column = program(vertex, header + shaders['water-optics.glsl'] + `
				uniform vec3 uOffset; out vec4 color;
				void main(){ color = vec4(waterColumnLight(vec3(1,0,0), uOffset, 2., 0., 1.), 1.); }`);
			gl.useProgram(column);
			gl.uniform3f(gl.getUniformLocation(column, 'uWaterTint'), 0, 1, 0);
			gl.uniform4f(gl.getUniformLocation(column, 'uWaterOptics'), 1, 0, .1, 0);
			const waterPaths = [];
			for(const [y,z] of [[-1,0], [-3,0], [-12,0], [-12,12]])
			{
				gl.uniform3f(gl.getUniformLocation(column,'uOffset'), 0, y, z);
				waterPaths.push(pixel());
			}

			const visualSource = shaders['water.frag'].replace('// ATMOSPHERE', data.source)
				.replace('// WATER', waterSource).replace('void main()', 'void waterSurfaceMain()');

			const visualProgram = program(surfaceVertex, `${visualSource}
				uniform float uVisualPixelSpan, uVisualOffset;
				void main() { vec2 position = vec2(gl_FragCoord.x * uVisualPixelSpan + uVisualOffset, 0.0);
					vec4 filtered = waterVisualSample(position, 0.0, dFdx(position), dFdy(position));
					vec4 original = terrainWaterSample(position, 0.0);
					color = vec4(0.5 + 0.5 * filtered.y, 0.5 + 0.5 * original.y,
						waterPhaseFilter(dFdx(position.x), dFdy(position.x)), 1.0); }`
			);

			gl.useProgram(visualProgram);
			gl.uniform1i(gl.getUniformLocation(visualProgram, 'uWaveCount'), 1);
			gl.uniform1i(gl.getUniformLocation(visualProgram, 'uWakeCount'), 0);
			gl.uniform4f(gl.getUniformLocation(visualProgram, 'uWaveVectors[0]'), 1, 0, 1, 1);
			gl.uniform2f(gl.getUniformLocation(visualProgram, 'uWaveDynamics[0]'), 0, 0);
			const aliasResults = [];

			for(const span of [.1,2.3,4])
			for(const offset of [0,.37,.73])
			{
				target(7, 64, 8);
				gl.uniform1f(gl.getUniformLocation(visualProgram, 'uVisualPixelSpan'), span);
				gl.uniform1f(gl.getUniformLocation(visualProgram, 'uVisualOffset'), offset);
				gl.drawArrays(gl.TRIANGLES, 0, 3);

				const bytes = new Uint8Array(64 * 8 * 4);
				gl.readPixels(0, 0, 64, 8, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
				const channels = [0,1,2].map(channel => Array.from({length: 64*8}, (_, i) => bytes[i*4+channel]));
				aliasResults.push({span,offset,channels});
			}

			const reflectionProgram = program(surfaceVertex, `${visualSource}
				uniform float uReflectionElevation;
				void main() { vec3 ray = normalize(vec3(1.0,uReflectionElevation,0.0));
					vec3 reflected = waterEnvironmentReflection(ray,0.0,0.002,vec3(0.006,0.045,0.09),uSunIntensity/20.0);
					color = vec4(terrainToneMap(reflected),1.0); }`
			);

			parameters(reflectionProgram);
			gl.bindFramebuffer(gl.FRAMEBUFFER, null);
			gl.uniform1i(gl.getUniformLocation(reflectionProgram, 'uSkyCacheEye'), 2);
			gl.uniform1i(gl.getUniformLocation(reflectionProgram, 'uSkyCacheWater'), 3);
			gl.uniform2f(gl.getUniformLocation(reflectionProgram, 'uSkyCacheSize'), 256, 128);
			gl.uniform2f(gl.getUniformLocation(reflectionProgram, 'uSkyCacheAltitudes'), 100, 0);
			const reflectionResults = [];

			for(const cached of [0,1])
			for(const elevation of [-.2,-.1,-.01,-.002,-.001,0,.001,.002,.01,.1])
			{
				gl.uniform1i(gl.getUniformLocation(reflectionProgram, 'uSkyCacheReady'), cached);
				gl.uniform1f(gl.getUniformLocation(reflectionProgram, 'uReflectionElevation'), elevation);
				reflectionResults.push({cached,elevation,color: pixel()});
			}

			// Evaluate darkness directly; cache invalidation on irradiance changes has its own lifecycle regression.
			gl.uniform1i(gl.getUniformLocation(reflectionProgram, 'uSkyCacheReady'), 0);
			gl.uniform1f(gl.getUniformLocation(reflectionProgram, 'uSunIntensity'), 0);
			const darkReflections = [];

			for(const elevation of [-.2,0,.1])
			{
				gl.uniform1f(gl.getUniformLocation(reflectionProgram, 'uReflectionElevation'), elevation);
				darkReflections.push(pixel());
			}

			const facingProgram = program(surfaceVertex, `${visualSource}
				uniform vec3 uTestNormal, uTestView;
				void main() { vec3 normal = normalize(uTestNormal), view = normalize(uTestView);
					vec3 facing = waterFacingNormal(normal,view);
					color = vec4(0.5+0.5*dot(facing,view),length(facing),0.5+0.5*dot(normal,view),1.0); }`
			);

			gl.useProgram(facingProgram);
			const facingResults = [];

			for(const [normal,view] of [[[0,1,0],[1,.1,0]],[[-.95,.31,0],[1,.1,0]],[[0,1,0],[0,-1,0]]])
			{
				gl.uniform3fv(gl.getUniformLocation(facingProgram, 'uTestNormal'), normal);
				gl.uniform3fv(gl.getUniformLocation(facingProgram, 'uTestView'), view);
				facingResults.push(pixel());
			}

			const solarProgram = program(vertex, `${header}${data.source}
				uniform float uTestSolarFootprint; out vec4 color;
				void main() { vec2 angle = (gl_FragCoord.xy / 256.0 - 0.5) * 0.08;
					vec3 ray = normalize(vec3(angle.x,1.0,angle.y));
					vec3 light = terrainSolarDisk(ray,100.0,vec3(1.0),uTestSolarFootprint);
						float radius = 2.0 * sin(0.5 * uAngularRadius);
						color = vec4(light * TERRAIN_PI * radius * radius,1.0); }`
			);

			parameters(solarProgram);
			gl.uniform3f(gl.getUniformLocation(solarProgram, 'uSunDirection'), 0, 1, 0);
			gl.uniform1f(gl.getUniformLocation(solarProgram, 'uSunIntensity'), 1);
			const solarResults = [];

			for(const footprint of [0,.005,.02])
			{
				target(7, 256, 256);
				gl.uniform1f(gl.getUniformLocation(solarProgram, 'uTestSolarFootprint'), footprint);
				gl.drawArrays(gl.TRIANGLES, 0, 3);
				const bytes = new Uint8Array(256 * 256 * 4);
				gl.readPixels(0, 0, 256, 256, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
				let energy = 0, peak = 0;

				for(let i = 0; i < bytes.length; i += 4)
				{
					energy += bytes[i];
					peak = Math.max(peak, bytes[i]);
				}

				solarResults.push({footprint,energy,peak});
			}

			gl.bindFramebuffer(gl.FRAMEBUFFER, null);

			const materialProgram = program(
				shaders['terrain.vert']
				, `${header}out vec4 c;void main(){c=vec4(0);}`
				, ['vPaint0','vPaint1']
			);

			const materialInput = gl.createBuffer();
			gl.bindBuffer(gl.ARRAY_BUFFER, materialInput);
			gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([1,4]), gl.STATIC_DRAW);
			gl.enableVertexAttribArray(3);
			gl.vertexAttribPointer(3, 1, gl.FLOAT, false, 4, 0);

			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, feedback);
			gl.bindBuffer(gl.TRANSFORM_FEEDBACK_BUFFER, outputBuffer);
			gl.bufferData(gl.TRANSFORM_FEEDBACK_BUFFER, 12*4, gl.DYNAMIC_READ);
			gl.useProgram(materialProgram);
			gl.enable(gl.RASTERIZER_DISCARD);
			gl.beginTransformFeedback(gl.POINTS);
			gl.drawArrays(gl.POINTS, 0, 2);
			gl.endTransformFeedback();

			const materialWeights = new Float32Array(12);
			gl.getBufferSubData(gl.TRANSFORM_FEEDBACK_BUFFER, 0, materialWeights);
			gl.disable(gl.RASTERIZER_DISCARD);
			gl.disableVertexAttribArray(3);
			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, null);

			// Exercise the real water vertex shader at all three types of new fine vertices:
			// horizontal/vertical edges and the parent triangle diagonal, with a wake crossing them.
			const waterMesh = program(shaders['water.vert'].replace('// WATER', waterSource),
				header + 'out vec4 c; void main(){c=vec4(1);}', ['vWorld','vGround']);
			gl.useProgram(waterMesh);
			const loc = name => gl.getUniformLocation(waterMesh, name);
			gl.uniform1i(loc('uWaveCount'), 1);
			gl.uniform4f(loc('uWaveVectors[0]'), 0.8, 0.6, 0.9, 2);
			gl.uniform2f(loc('uWaveDynamics[0]'), 1.1, 0.4);
			gl.uniform1i(loc('uFrameWakeCount'), 1);
			gl.uniform4f(loc('uFrameWakes[0]'), 20, 20, 3, .7);
			gl.uniform4f(loc('uWakeBounds'), -50, -50, 50, 50);
			gl.vertexAttrib3f(0, 0, -8, 0);
			gl.vertexAttrib1f(2, -3);
			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, feedback);
			gl.bindBuffer(gl.TRANSFORM_FEEDBACK_BUFFER, outputBuffer);
			gl.bufferData(gl.TRANSFORM_FEEDBACK_BUFFER, 16, gl.DYNAMIC_READ);
			gl.enable(gl.RASTERIZER_DISCARD);
			const sampleMesh = (x, z, step, morph) => {
				gl.uniform4f(loc('uPatch'), 0, 0, step, morph);
				gl.beginTransformFeedback(gl.POINTS);
				gl.drawArrays(gl.POINTS, z * 33 + x, 1);
				gl.endTransformFeedback();
				const result = new Float32Array(4);
				gl.getBufferSubData(gl.TRANSFORM_FEEDBACK_BUFFER, 0, result);
				return result;
			};
			let transitionError = 0;
			for(const time of [0, 1.5, 3.75])
			{
				gl.uniform1f(loc('uWaterTime'), time);
				for(const [x,z] of [[11,10],[10,11],[11,11],[10,10]])
				{
					const step = 1.953125, ox = x % 2, oz = -(z % 2);
					const a = sampleMesh((x-ox)/2, (z-oz)/2, step*2, 0);
					const b = sampleMesh((x+ox)/2, (z+oz)/2, step*2, 0);
					const fine = sampleMesh(x, z, step, 0);
					for(const morph of [.4,1])
					{
						const actual = sampleMesh(x, z, step, morph);
						for(let k = 0; k < 3; k++)
						{
							const expected = fine[k]*(1-morph) + .5*(a[k]+b[k])*morph;
							transitionError = Math.max(transitionError, Math.abs(actual[k]-expected));
						}
						transitionError = Math.max(transitionError, Math.abs(actual[3]-(-8+5*morph)));
					}
				}
			}
			gl.disable(gl.RASTERIZER_DISCARD);
			gl.bindTransformFeedback(gl.TRANSFORM_FEEDBACK, null);

			// Compile the real consuming scene programs too, so include/link compatibility is part of this regression.
			for(const name of ['sky', 'terrain', 'water'])
			program(
				shaders[`${name}.vert`].replace('// WATER', waterSource)
				, shaders[`${name}.frag`].replace('// ATMOSPHERE', data.source).replace('// WATER', waterSource)
			);
			// Render the stellar catalogue through the horizon at two headings on an otherwise black target.
			const stars = program(shaders['stars.vert'], shaders['stars.frag']);
			target(7, 256, 128);
			gl.useProgram(stars);
			gl.uniform1f(gl.getUniformLocation(stars,'uPixelScale'), 1);
			gl.uniform1f(gl.getUniformLocation(stars,'uExposure'), 1);
			gl.enable(gl.BLEND);
			gl.blendFuncSeparate(gl.SRC_ALPHA, gl.ONE, gl.ZERO, gl.ONE);
			const starField = (visibility, yaw) => {
				const c = Math.cos(yaw), s = Math.sin(yaw);
				gl.uniformMatrix4fv(gl.getUniformLocation(stars,'uViewProjection'), false,
					[c,0,s,s, 0,2,0,0, s,0,-c,-c, 0,0,-.5,0]);
				gl.uniform1f(gl.getUniformLocation(stars,'uNightVisibility'), visibility);
				gl.clearColor(0,0,0,1);
				gl.clear(gl.COLOR_BUFFER_BIT);
				gl.drawArrays(gl.POINTS, 0, 2400);
				const pixels = new Uint8Array(256 * 128 * 4);
				gl.readPixels(0, 0, 256, 128, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
				return pixels.filter((v,i) => i % 4 === 2);
			};
			const night = starField(1, 0), dusk = starField(.5, 0), day = starField(0, 0);
			const turned = starField(1, 1.2), returned = starField(1, 0);
			const litPixels = night.filter(v => v > 12).length;
			const starChecks = {
				litPixels, belowHorizon: night.slice(0, 256*64).some(v => v > 1),
				fade: dusk.every((v,i) => Math.abs(2*v-night[i]) <= 3),
				dayDark: day.every(v => v === 0), turned: turned.some((v,i) => Math.abs(v-night[i]) > 30),
				stable: returned.every((v,i) => v === night[i])
			};

			// Light level ground at the origin, seen from (0, 5, 5), at night with the lamps that PHP packed.
			gl.disable(gl.BLEND);

			const spotResults = {};

			/**
			 * Link a surface shader with the lit or the lamp-free spot-light include and set up the night scene.
			 * @param {string} name 'terrain' or 'water'.
			 * @param {string} spots Include for the "// SPOT LIGHTS" marker.
			 * @return {WebGLProgram} The program, in use.
			 */
			const atNight = (name, spots) => {
				const linked = program(
					surfaceVertex
					, shaders[`${name}.frag`].replace('// ATMOSPHERE', data.source).replace('// WATER', waterSource)
						.replace('// SPOT LIGHTS', spots)
				);

				parameters(linked);

				if(name === 'terrain') appearance(linked, data.appearance);

				gl.uniform3f(gl.getUniformLocation(linked, 'uEye'), 0, 5, 5);
				gl.uniform1f(gl.getUniformLocation(linked, 'uTestGround'), -3);
				gl.uniform1f(gl.getUniformLocation(linked, 'uSunIntensity'), 0);
				gl.uniform3fv(gl.getUniformLocation(linked, 'uNightAmbient'), data.nightAmbient);

				return linked;
			};

			/**
			 * Sink the surface under a metre of murky water, as for the water column check.
			 * @param {WebGLProgram} linked Program in use.
			 * @return {void}
			 */
			const submerge = linked => {
				gl.uniform1f(gl.getUniformLocation(linked, 'uWaterLevel'), 1);
				gl.uniform4f(gl.getUniformLocation(linked, 'uWaterOptics'), .85, .85, 1, .65);
			};

			for(const name of ['terrain', 'water'])
			{
				// Scene draws the lamp-free variant while no light is set. It must match the lit one without lamps.
				const lampFree = atNight(name, data.lampFreeSpotLights);
				const unlit = {dark: pixel()};
				submerge(lampFree);
				unlit.submerged = pixel();

				const surface = atNight(name, shaders['spot-lights.glsl']);
				const colors = {dark: pixel()};
				const lamps = gl.getUniformLocation(surface, 'uSpotLights[0]');
				// A count of zero ignores whatever the array holds.
				gl.uniform4fv(lamps, data.spotLights.pair['uSpotLights[0]']);
				colors.ignored = pixel();

				for(const [key, uniforms] of Object.entries(data.spotLights))
				{
					gl.uniform1i(gl.getUniformLocation(surface, 'uSpotLightCount'), uniforms.uSpotLightCount);

					if(uniforms.uSpotLightCount) gl.uniform4fv(lamps, uniforms['uSpotLights[0]']);

					colors[key] = pixel();
				}

				// Under a metre of murky water the column between the surface and the ground scatters lamp light.
				submerge(surface);
				gl.uniform1i(gl.getUniformLocation(surface, 'uSpotLightCount'), 0);
				colors.submerged = pixel();
				gl.uniform1i(gl.getUniformLocation(surface, 'uSpotLightCount'), 1);
				gl.uniform4fv(lamps, data.spotLights.column['uSpotLights[0]']);
				colors.column = pixel();
				spotResults[name] = {...colors, unlit};
			}

			const error = gl.getError();

			if(error !== gl.NO_ERROR) throw new Error(`WebGL error: ${error}`);

			return {
				skyResults, transitionError, starChecks
				, spotResults
				, aerialResults, canyonHaze
				, waterResults, backdropContrast, waterPaths
				, shadowResults, adaptiveResults
				, illuminationResults
				, bandResults
				, mountainBands
				, mountainAmounts
				, inactiveAppearance

				, aliasResults
				, reflectionResults
				, darkReflections
				, facingResults
				, solarResults
				, materialWeights: Array.from(materialWeights)
			};
		}, {data, shaders});

		const stars = result.starChecks;
		assert.ok(stars.litPixels > 80 && stars.litPixels < 2500 && !stars.belowHorizon, 'stars sparsely cover the visible sky');
		assert.ok(stars.fade && stars.dayDark, 'stars fade through twilight and vanish by day');
		assert.ok(stars.turned && stars.stable, 'the star field is fixed to viewing directions');
		assert.ok(result.transitionError < 1e-5, 'waves, wakes and shoreline depths match the morphing mesh');

		const near = (a, b) => a.every((value, i) => Math.abs(value - b[i]) <= 1);

		for(const [name, colors] of Object.entries(result.spotResults))
		{
			for(const key of ['ignored', 'none', 'away', 'outside', 'short', 'below'])
			assert.deepEqual(colors[key], colors.dark, `${name}: the ${key} case adds no lamp light`);

			assert.deepEqual(
				colors.unlit
				, {dark: colors.dark, submerged: colors.submerged}
				, `${name}: the lamp-free program draws what the lit one draws without lamps`
			);

			// The knee keeps colour in light that would otherwise clip: 64 times the overhead lamp stays below white.
			assert.ok(
				colors.glare.every((value, i) => value > colors.overhead[i] && value < 250)
				, `${name}: 64 times the lamp is brighter and does not clip: ${colors.overhead} to ${colors.glare}`
			);

			// The cones fade the compressed light, so halfway through its band the glare lamp gives a quarter of
			// what it gives on its axis, less than the overhead lamp. Compressed after the cone, it would be
			// brighter.
			assert.ok(
				colors.band.every((value, i) => value > colors.dark[i] && value < colors.overhead[i])
				, `${name}: the cone band dims the glare lamp below the overhead lamp: ${colors.band}`
			);

			if(name === 'terrain')
			assert.ok(
				colors.column[1] > colors.submerged[1] + 5 && colors.column[2] > colors.submerged[2] + 5
				, `the water column scatters lamp light: ${colors.submerged} to ${colors.column}`
			);

			const gain = colors.overhead.map((value, i) => value - colors.dark[i]);

			assert.ok(
				Math.min(...gain) > 0 && gain[0] + gain[1] + gain[2] > 30
				, `${name}: an overhead lamp lights the surface at night: ${colors.dark} to ${colors.overhead}`
			);

			// Inverse squares, the cosine of incidence and the sum over lamps each keep the irradiance.
			for(const key of ['higher', 'oblique', 'pair'])
			assert.ok(
				near(colors[key], colors.overhead)
				, `${name}: the ${key} lamp gives the same irradiance: ${colors[key]} and ${colors.overhead}`
			);
		}

		for(const [i, expected] of [0, 128, 255].entries())
			assert.ok(Math.abs(result.backdropContrast[i] - expected) <= 2, 'surface transmits the selected fraction');
		assert.ok(result.backdropContrast[3] < 255 && result.backdropContrast[3] > 230, 'reflection overlays a clear surface');
		assert.ok(result.backdropContrast[4] < 125, 'foam covers the clear shoreline');
		assert.equal(result.backdropContrast[5], 255, 'shore foam leaves deep water clear');
		for(const [i, metres] of [0, 1, 10, Math.sqrt(2)*10].entries())
		{
			const red = Math.round(255 * Math.exp(-.1 * metres));
			assert.ok(Math.abs(result.waterPaths[i][0] - red) <= 1);
			assert.ok(Math.abs(result.waterPaths[i][1] - (255 - red)) <= 1);
		}

		let skyError = 0, aerialError = 0, waterError = 0;

		for(const sample of result.skyResults)
		{
			const delta = Math.max(...sample.colors[0].map((value, i) => Math.abs(value - sample.colors[1][i])));
			skyError = Math.max(skyError, delta);
			assert.ok(delta <= 4, `Sky cache color deviation: ${JSON.stringify(sample)}`);
		}

		for(const sample of result.aerialResults)
		{
			const delta = Math.max(...sample.colors[0].map((value, i) => Math.abs(value - sample.colors[1][i])));
			aerialError = Math.max(aerialError, delta);
			const identity = sample.distance === 0 || sample.distance > 90000 || sample.altitude !== 100;
			assert.ok(delta <= (identity ? 0 : 4), `Aerial cache color deviation: ${JSON.stringify(sample)}`);
		}

		for(const [clear,below,above] of result.canyonHaze)
		{
			assert.ok(Math.max(...below.map((c,i)=>Math.abs(c-clear[i]))) > 8, 'sun haze reaches visible terrain below eye level');
			assert.ok(below.every((c,i)=>Math.abs(c-above[i]) <= 2), 'sun haze is continuous across the canyon horizon');
		}

		for(const sample of result.waterResults)
		{
			const delta = Math.max(...sample.expected.map((value, i) => Math.abs(value - sample.actual[i])));
			waterError = Math.max(waterError, delta);
			// GLSL uses float32 phase arithmetic; world-edge coordinates need a larger bound than local samples.
			const tolerance = Math.max(...sample.position.map(Math.abs)) > 10000 ? .003 : .0002;
			for(let i = 0; i < 3; i++)
				assert.ok(Math.abs(sample.actual[7+i] - sample.expected[i]) <= tolerance, 'prepared wake height and slopes match physics');
			const foam = sample.actual[10];
			if(sample.name === 'wakes' && sample.time === .001 && sample.position.every(value => value === 0))
				assert.ok(foam > .9, 'fresh propulsion visibly churns the water at the stern');
			if(sample.name !== 'wakes' || sample.time >= 3 || sample.position[0] < -100)
				assert.equal(foam, 0, 'unpowered, expired and distant water has no jet wash');

			assert.ok(
				Number.isFinite(delta) && delta <= tolerance
				, `Water CPU/GPU deviation: ${JSON.stringify(sample)}`
			);
		}

		const [wall, stripe, packedWall, packedStripe] = result.adaptiveResults;
		assert.deepEqual([packedWall, packedStripe], [wall, stripe], 'packed heights produce the same edges as float heights');
		assert.equal(wall.record[0], 3, 'only the second corner lies in shadow');
		assert.deepEqual(wall.pixels.slice(0,3), [255,0,255], 'the shadow edge cuts across the near triangle');
		assert.ok(wall.pixels[3] > 180 && wall.pixels[3] < 211, 'about three quarters of the distant triangle is lit');
		assert.deepEqual(wall.pixels.slice(4), [0,0], 'the reflected triangle meets the same edge on the diagonal');
		assert.equal(stripe.record[0], 8, 'the centre probe finds the narrow shadow between three lit vertices');
		assert.deepEqual(stripe.pixels.slice(0,3), [255,255,0], 'only the interior of the near triangle is shaded');
		assert.ok(stripe.pixels[3] > 204 && stripe.pixels[3] < 255, 'the interior shadow contributes to distant coverage');
		const visibility = Object.fromEntries(result.shadowResults.map(sample => [sample.name, sample.visibility]));
		assert.deepEqual(visibility.flat, [255, 255], 'a flat plane remains sunlit even at low sun angles');
		assert.deepEqual(visibility.ridge, [0, 255], 'a broad ridge blocks the down-sun side');
		assert.deepEqual(visibility.reversed, [255, 0], 'reversing the sun reverses the ridge shadow');
		assert.deepEqual(visibility.night, [0, 0], 'the coarse map contains no direct visibility at night');

		for(const sample of result.illuminationResults)
		{
			assert.deepEqual(sample.colors[0], [0, 0, 0], `${sample.name} has no unlit ambient/body/foam emission`);
			assert.ok(sample.night[2] > sample.night[0] && sample.night[1] > sample.night[0], `${sample.name} receives cool night fill`);
			assert.ok(sample.colors[1].some(channel => channel > 0), `${sample.name} receives nonzero daylight`);
		}

		assert.deepEqual(result.inactiveAppearance, [], 'terrain.frag reads every appearance uniform');

		const [, grass, redGrass] = result.illuminationResults.find(sample => sample.name === 'terrain').colors;
		assert.ok(grass[1] > grass[0] && grass[1] > grass[2], `default grass paint draws green: ${grass}`);
		assert.ok(redGrass[0] > redGrass[1] && redGrass[0] > redGrass[2], `red grass paint draws red: ${redGrass}`);

		const amplitude = (values, bin) => {
			let real = 0, imaginary = 0;

			values.forEach((value, i) => {
				real += value * Math.cos(2 * Math.PI * bin * i / values.length);
				imaginary -= value * Math.sin(2 * Math.PI * bin * i / values.length);
			});

			return 2 * Math.hypot(real, imaginary) / values.length;
		};

		// A harmonic past the pixel rate folds back onto a bin below it. Tone mapping folds back 1 to 2% of a plain
		// sine's amplitude. Sharpened bands must fade their harmonics out before they fold back 6%.
		const bandAliases = [];

		for(const {sharpness, bin, red} of result.bandResults)
		{
			const resolved = new Set(), folded = new Set();

			for(let k = 1; k * bin <= 128; k++) resolved.add(k * bin);

			// Bins 1 to 3 hold the slow change of sunlight with height.
			for(let k = 2; k <= 21; k++)
			{
				const wrapped = k * bin % 256, alias = wrapped > 128 ? 256 - wrapped : wrapped;

				if(k * bin > 128 && alias > 3 && !resolved.has(alias)) folded.add(alias);
			}

			const fundamental = amplitude(red, bin);
			const ratio = Math.hypot(...[...folded].map(alias => amplitude(red, alias))) / fundamental;
			bandAliases.push({sharpness, bin, fundamental: +fundamental.toFixed(2), ratio: +ratio.toFixed(4)});
		}

		console.log('Folded band harmonics:', JSON.stringify(bandAliases));

		for(const {sharpness, bin, fundamental, ratio} of bandAliases)
		{
			assert.ok(fundamental > 5, `bands at sharpness ${sharpness}, bin ${bin} are visible: ${fundamental}`);
			assert.ok(ratio < .06, `bands at sharpness ${sharpness}, bin ${bin} alias by ${ratio} of their amplitude`);
		}

		// The mountain face varies, but no more at the band frequency than at the frequencies around it.
		const mountainBands = result.mountainBands.map(({bin, red}) => {
			const around = [-3, -2, -1, 1, 2, 3].map(step => amplitude(red, bin + step));

			return {bin, band: +amplitude(red, bin).toFixed(2), around: +Math.max(...around).toFixed(2)};
		});

		console.log('Mountain face at the band frequencies:', JSON.stringify(mountainBands));

		for(const {bin, band, around} of mountainBands)
		{
			const coast = bandAliases.find(sample => sample.sharpness === 1 && sample.bin === bin).fundamental;
			const flat = band < coast / 4 && band <= Math.max(1, 1.5 * around);
			assert.ok(flat, `mountain rock has no bands at bin ${bin}: ${band}`);
		}

		// Talus and stain colours must show at their default amounts and nowhere at an amount of 0.
		console.log('Mountain colours shown by amount:', JSON.stringify(result.mountainAmounts));

		for(const {material, amount, share} of result.mountainAmounts)
		{
			if(amount === 0) assert.equal(share, 0, `${material} shows on ${share} of a face at an amount of 0`);
			else assert.ok(share > .01, `${material} shows on ${share} of a face at an amount of ${amount}`);
		}

		const variance = values => {
			const mean = values.reduce((sum, value) => sum + value, 0) / values.length;

			return values.reduce((sum, value) => sum + (value-mean)**2, 0) / values.length;
		};

		for(const sample of result.aliasResults)
		{
			const [filtered,original,foam] = sample.channels;

			if(sample.span === .1)
			assert.deepEqual(filtered, original, 'resolved close waves keep their full shading slopes');

			if(sample.span === 2.3)
			{
				const ratio = variance(filtered) / variance(original);
				assert.ok(ratio > .05 && ratio < .6, 'near-Nyquist wave energy fades smoothly');
			}

			if(sample.span === 4)
			{
				assert.ok(variance(original)>1000, 'the unfiltered reference exhibits unresolved oscillation');

				assert.ok(
					Math.max(...filtered)-Math.min(...filtered)<=1
					, 'unresolved normals remain stable across pixels/phases'
				);

				assert.ok(foam.every(value => value===0), 'unresolved foam modulation is removed');
			}
		}

		for(const sample of result.reflectionResults)
		assert.ok(Math.max(...sample.color)>40, 'daytime lower-hemisphere reflection has finite nonblack illumination');

		for(const cached of [0,1])
		{
			const horizon = result.reflectionResults.filter(
				sample => sample.cached===cached&&Math.abs(sample.elevation)<=.002
			);

			for(let i = 1; i<horizon.length; i++)
			assert.ok(
				horizon[i].color.every((value, channel) => Math.abs(value-horizon[i-1].color[channel])<=5)
				, 'water reflection remains continuous across the horizon'
			);
		}

		for(const color of result.darkReflections)
		assert.deepEqual(color, [0,0,0], 'environment fallback emits no light');

		for(const color of result.facingResults)
		assert.ok(color[0]>=127&&color[1]===255, 'corrected shading normals remain unit length and face the viewer');

		assert.ok(
			Math.abs(result.facingResults[0][0]-result.facingResults[0][2])<=1
			, 'resolved facing normals are unchanged'
		);

		const solarRadius = 2 * Math.sin(data.uniforms.angularRadius / 2);
		const expectedSolarEnergy = 255 * Math.PI * solarRadius ** 2 / (.08 / 256) ** 2;

		assert.ok(
			Math.abs(result.solarResults[0].energy / expectedSolarEnergy - 1) < .03
			, 'the point-sampled disk retains its physical angular area'
		);

		for(const sample of result.solarResults.slice(1))
		{
			assert.ok(
				Math.abs(sample.energy / result.solarResults[0].energy - 1) < .03
				, `solar footprint filtering preserves energy: ${JSON.stringify(result.solarResults)}`
			);
		}

		assert.ok(
			result.solarResults[2].peak < result.solarResults[1].peak
			, 'larger reflection footprints lower solar peaks'
		);

		assert.deepEqual(
			result.materialWeights
			, [0,1,0,0,0,0,0,0,0,0,1,0]
			, 'material endpoint weights select only grass/road'
		);

		for(const blend of [.25,.5,.75])
		{
			const start = result.materialWeights.slice(0, 6), end = result.materialWeights.slice(6);
			const weights = start.map((value, i) => value * (1 - blend) + end[i] * blend);

			assert.deepEqual(
				weights
				, [0,1-blend,0,0,blend,0]
				, 'interpolated grass/road weights cannot invent sand or snow'
			);
		}

		const counts = [result.skyResults.length, result.aerialResults.length, result.waterResults.length];
		const errors = [skyError, aerialError, waterError];

		console.log('GPU parity (sky/aerial/water):', {samples: counts, maximumErrors: errors});
		console.log('Water footprint cases:', result.aliasResults.length, 'solar coverage:', result.solarResults);
	}
	finally
	{
		await browser.close();
	}
});
